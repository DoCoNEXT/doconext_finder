<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\CoreScope;
use OCA\DcnFinder\Service\FileSearchService;
use OCP\Files\Search\ISearchComparison;
use OCP\FilesMetadata\IFilesMetadataManager;
use PHPUnit\Framework\TestCase;

/**
 * "Is advies" on a field that holds several choices.
 *
 * Core stores a multi-select on a file as its choices joined by `, ` —
 * `nda, advies` — the same way it stores several people. Comparing the whole
 * value finds only a file holding advies alone; the search has to find it as
 * one item of the list. Which keys are such lists only Core knows.
 */
class SeveralChoicesSearchTest extends TestCase
{
    /**
     * Do:     build the patterns for "is advies".
     * Expect: advies alone, first, last and between two others, each anchored
     *         on the `, ` Core writes.
     * Why:    a plain "contains" would let `ann` find `joanne`.
     */
    public function testAnItemIsFoundAloneFirstLastAndBetween(): void
    {
        $this->assertSame([
            [ISearchComparison::COMPARE_EQUAL, 'advies'],
            [ISearchComparison::COMPARE_LIKE, 'advies, %'],
            [ISearchComparison::COMPARE_LIKE, '%, advies'],
            [ISearchComparison::COMPARE_LIKE, '%, advies, %'],
        ], FileSearchService::itemInListPatterns('advies'));
    }

    /**
     * Do:     build the patterns for an item holding the wildcards % and _.
     * Expect: they are escaped in the LIKE patterns, kept in the equality.
     * Why:    unescaped, `50%` would match every value starting with 50.
     */
    public function testWildcardsInAnItemMatchOnlyThemselves(): void
    {
        $patterns = FileSearchService::itemInListPatterns('50%_off');

        $this->assertSame('50%_off', $patterns[0][1]);
        $this->assertSame('50\%\_off, %', $patterns[1][1]);
    }

    /**
     * Do:     ask about a key Core lists as several choices, and one it does not.
     * Expect: only the listed key holds several choices.
     */
    public function testCoreSaysWhichKeysHoldSeveralChoices(): void
    {
        $core = $this->createMock(CoreScope::class);
        $core->method('multiSelectKeys')->willReturn(['dcn_core_project_manager']);
        $fields = new MetadataFields($this->createMock(IFilesMetadataManager::class), $core);

        $this->assertTrue($fields->holdsSeveralChoices('dcn_core_project_manager'));
        $this->assertFalse($fields->holdsSeveralChoices('dcn_core_project_naam'));
    }

    /**
     * Do:     ask twice in one request.
     * Expect: Core is asked once.
     * Why:    a query with several conditions asks per condition.
     */
    public function testCoreIsAskedOncePerRequest(): void
    {
        $core = $this->createMock(CoreScope::class);
        $core->expects($this->once())->method('multiSelectKeys')->willReturn([]);
        $fields = new MetadataFields($this->createMock(IFilesMetadataManager::class), $core);

        $fields->holdsSeveralChoices('dcn_core_a');
        $fields->holdsSeveralChoices('dcn_core_b');
    }
}
