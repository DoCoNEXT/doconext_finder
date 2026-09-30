<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Platform;

use DateTime;
use OCA\DcnFinder\Search\CoreSearch;
use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOrder;
use OCP\FilesMetadata\IMetadataQuery;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What this app assumes about Nextcloud's core-private search classes, checked
 * against the real ones.
 *
 * {@see CoreSearch} constructs them because there is no public way to build a
 * file search. Psalm cannot see them and the OCP stubs do not ship them, so
 * nothing else notices when a release changes a constructor or what a getter
 * hands back — CI runs this suite once per Nextcloud version info.xml claims,
 * against that version's server source.
 *
 * Fails rather than skips when the classes are missing: a platform check that
 * quietly does not run is the failure it exists to catch.
 */
class CoreSearchTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\OC\Files\Search\SearchQuery::class)) {
            $this->fail('OC\Files\Search is not loadable: set NEXTCLOUD_SERVER_DIR to a Nextcloud server checkout.');
        }
    }

    public function testQueryHandsBackWhatItWasBuiltWith(): void
    {
        $operator = CoreSearch::compare(ISearchComparison::COMPARE_LIKE, 'name', '%a%');
        $orders = [CoreSearch::orderBy(ISearchOrder::DIRECTION_ASCENDING, 'fileid')];
        $user = $this->createMock(IUser::class);

        $query = CoreSearch::query($operator, 51, 100, $orders, $user);

        $this->assertSame($operator, $query->getSearchOperation());
        $this->assertSame(51, $query->getLimit());
        $this->assertSame(100, $query->getOffset());
        $this->assertSame($orders, $query->getOrder());
        $this->assertSame($user, $query->getUser());
        // Folder::search() refuses limitToHome outside a home folder; the
        // scopes Finder searches are any folder, so it must default off.
        $this->assertFalse($query->limitToHome());
    }

    /**
     * Every value shape FileSearchService passes: a LIKE pattern, a file id,
     * the content window's id list, and a date bound.
     *
     * @return array<string, array{string, string, mixed}>
     */
    public static function comparisons(): array
    {
        return [
            'pattern' => [ISearchComparison::COMPARE_LIKE, 'name', '%offerte%'],
            'file id' => [ISearchComparison::COMPARE_EQUAL, 'fileid', 42],
            'id list' => [ISearchComparison::COMPARE_IN, 'fileid', [1, 2, 3]],
            'date' => [ISearchComparison::COMPARE_GREATER_THAN_EQUAL, 'mtime', new DateTime('2026-01-01')],
        ];
    }

    #[DataProvider('comparisons')]
    public function testComparisonHandsBackWhatItWasBuiltWith(string $type, string $field, mixed $value): void
    {
        $comparison = CoreSearch::compare($type, $field, $value);

        $this->assertSame($type, $comparison->getType());
        $this->assertSame($field, $comparison->getField());
        $this->assertSame($value, $comparison->getValue());
        $this->assertSame('', $comparison->getExtra());
    }

    public function testMetadataComparisonKeepsItsExtra(): void
    {
        // SearchBuilder reads the extra to route the field to the metadata index.
        $comparison = CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'dcn_core_status', 'open', IMetadataQuery::EXTRA);

        $this->assertSame(IMetadataQuery::EXTRA, $comparison->getExtra());
    }

    public function testComparisonCarriesQueryHints(): void
    {
        // Core's optimizer steps set hints that SearchBuilder then reads back.
        $comparison = CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'path', 'files');
        $this->assertTrue($comparison->getQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, true));

        $comparison->setQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, false);
        $this->assertFalse($comparison->getQueryHint(ISearchComparison::HINT_PATH_EQ_HASH, true));
    }

    public function testCombinationHandsBackItsOperands(): void
    {
        $a = CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'fileid', 1);
        $b = CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'fileid', 2);

        $or = CoreSearch::combine(ISearchBinaryOperator::OPERATOR_OR, [$a, $b]);

        $this->assertInstanceOf(ISearchBinaryOperator::class, $or);
        $this->assertSame(ISearchBinaryOperator::OPERATOR_OR, $or->getType());
        $this->assertSame([$a, $b], $or->getArguments());
    }

    public function testNotWrapsASingleComparison(): void
    {
        // The only shape of "not" SearchBuilder accepts, and the one
        // FileSearchService::withoutRoot() builds.
        $root = CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'fileid', 7);

        $not = CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [$root]);

        $this->assertInstanceOf(ISearchBinaryOperator::class, $not);
        $this->assertSame([$root], $not->getArguments());
    }

    public function testOrderHandsBackWhatItWasBuiltWith(): void
    {
        $order = CoreSearch::orderBy(ISearchOrder::DIRECTION_DESCENDING, 'dcn_core_status', IMetadataQuery::EXTRA);

        $this->assertSame(ISearchOrder::DIRECTION_DESCENDING, $order->getDirection());
        $this->assertSame('dcn_core_status', $order->getField());
        $this->assertSame(IMetadataQuery::EXTRA, $order->getExtra());
    }

    /**
     * The columns core's own comparator must know.
     *
     * When a folder spans several storages — a home with team folders in it —
     * Folder::search() gets its rows per storage and sorts the merged page again
     * in PHP with sortFileInfo(). A field that comparator does not know compares
     * as equal, and the page silently comes back in another order than the SQL
     * cut it by. These are the sorts that depend on it, fileid being the
     * tie-break FileSearchService::ordered() adds to every order.
     *
     * @return array<string, array{string, string, int|string, int|string}>
     */
    public static function sortedFields(): array
    {
        return [
            'fileid' => ['fileid', 'getId', 1, 2],
            'name' => ['name', 'getName', 'a.txt', 'b.txt'],
            'size' => ['size', 'getSize', 10, 20],
            'mtime' => ['mtime', 'getMtime', 1_700_000_000, 1_800_000_000],
        ];
    }

    #[DataProvider('sortedFields')]
    public function testCoreSortsTheMergedPageBy(string $field, string $getter, int|string $low, int|string $high): void
    {
        $a = $this->createMock(FileInfo::class);
        $a->method($getter)->willReturn($low);
        $b = $this->createMock(FileInfo::class);
        $b->method($getter)->willReturn($high);

        $ascending = CoreSearch::orderBy(ISearchOrder::DIRECTION_ASCENDING, $field);
        $descending = CoreSearch::orderBy(ISearchOrder::DIRECTION_DESCENDING, $field);

        $this->assertLessThan(0, $ascending->sortFileInfo($a, $b));
        $this->assertGreaterThan(0, $descending->sortFileInfo($a, $b));
    }
}
