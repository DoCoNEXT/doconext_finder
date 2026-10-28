<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\StoredQuery;
use PHPUnit\Framework\TestCase;

/**
 * A stored search resolved on the server, as the page resolves it. Where the
 * two would disagree, a schedule says "12 new" and the page shows 9.
 */
class StoredQueryTest extends TestCase
{
    private const BUILTIN_TYPES = [
        ['type' => 'builtin', 'id' => 'documents'],
        ['type' => 'builtin', 'id' => 'email'],
        ['type' => 'custom', 'id' => 'contracts', 'label' => 'Contracts', 'mimetypes' => ['application/pdf']],
    ];

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        // A Wednesday afternoon in Amsterdam.
        $this->now = new \DateTimeImmutable('2026-10-28 15:30', new \DateTimeZone('Europe/Amsterdam'));
    }

    public function testTheBuiltinTypesMatchThePage(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/filters/presets.ts');
        preg_match('/function builtinFileTypeDefinitions[\s\S]*?\n}\n/', $source, $block);
        $this->assertNotEmpty($block, 'builtinFileTypeDefinitions() not found in presets.ts');
        preg_match_all("/id: '([^']+)'[\s\S]*?mimetypes: \[([\s\S]*?)\]/", $block[0], $entries, PREG_SET_ORDER);

        $page = [];
        foreach ($entries as [, $id, $list]) {
            preg_match_all("/'([^']+)'/", $list, $mimes);
            $page[$id] = $mimes[1];
        }

        $this->assertSame($page, StoredQuery::BUILTIN_MIMETYPES);
        $this->assertSame(AppConstants::BUILTIN_FILE_TYPE_IDS, array_keys(StoredQuery::BUILTIN_MIMETYPES));
    }

    public function testATypePresetResolvesToItsMimetypes(): void
    {
        $this->assertSame(
            ['message/rfc822', 'application/vnd.ms-outlook'],
            $this->request(['typePreset' => 'email'])['mimetypes'],
        );
        $this->assertSame(['application/pdf'], $this->request(['typePreset' => 'contracts'])['mimetypes']);
    }

    public function testATypeTheAdminNoLongerOffersIsAnyType(): void
    {
        $this->assertSame([], $this->request(['typePreset' => 'images'])['mimetypes']);
    }

    public function testTheDistilledTypeWinsWhenSelected(): void
    {
        $request = $this->request([
            'typePreset' => 'word',
            'customType' => ['id' => 'word', 'label' => 'Word', 'mimetypes' => ['application/msword']],
        ]);

        $this->assertSame(['application/msword'], $request['mimetypes']);
    }

    public function testDatePresetsAreTheUsersCalendar(): void
    {
        $zone = $this->now->getTimezone();
        $at = static fn (string $time) => (new \DateTimeImmutable($time, $zone))->getTimestamp();

        $this->assertSame([$at('2026-10-28 00:00'), null], StoredQuery::modifiedRange('today', $this->now));
        $this->assertSame([$this->now->getTimestamp() - 7 * 86400, null], StoredQuery::modifiedRange('week', $this->now));
        $this->assertSame([$at('2026-10-01 00:00'), null], StoredQuery::modifiedRange('this-month', $this->now));
        $this->assertSame([$at('2026-09-01 00:00'), $at('2026-10-01 00:00')], StoredQuery::modifiedRange('last-month', $this->now));
        $this->assertSame([$at('2026-01-01 00:00'), null], StoredQuery::modifiedRange('this-year', $this->now));
        $this->assertSame([$at('2025-01-01 00:00'), $at('2026-01-01 00:00')], StoredQuery::modifiedRange('last-year', $this->now));
        // Renamed, not dropped: an unknown id would widen to "any time".
        $this->assertSame(StoredQuery::modifiedRange('this-year', $this->now), StoredQuery::modifiedRange('year', $this->now));
        $this->assertSame([null, null], StoredQuery::modifiedRange('any', $this->now));
    }

    public function testUnusableAndRetiredConditionsAreDroppedAndLabelsStripped(): void
    {
        $request = $this->request(['conditions' => [
            ['field' => 'name', 'operator' => 'contains', 'value' => ''],
            ['field' => 'owner', 'operator' => 'eq', 'value' => 'alice'],
            ['field' => 'size', 'operator' => 'gt', 'value' => 1024, 'label' => 'Bigger than 1 KB'],
        ]]);

        $this->assertSame([['field' => 'size', 'operator' => 'gt', 'value' => 1024]], $request['conditions']);
    }

    public function testAnOldContentPhraseBecomesTheTerm(): void
    {
        $request = $this->request(['term' => '', 'content' => 'lease', 'sort' => 'relevance']);

        $this->assertSame('lease', $request['term']);
        $this->assertSame('lease', $request['content']);
        $this->assertSame('mtime', $request['sort']);
    }

    public function testTheScopeTravelsWithoutItsLabel(): void
    {
        $request = $this->request(['scope' => ['level' => 'folder', 'id' => 12, 'label' => 'Dossiers']]);

        $this->assertSame(['level' => 'folder', 'id' => 12], $request['scope']);
    }

    public function testTheRequestIsOneFileQueryAccepts(): void
    {
        $query = FileQuery::fromArray([
            ...$this->request(['typePreset' => 'documents', 'modifiedPreset' => 'week']),
            'changedSince' => $this->now->getTimestamp() - 86400,
        ]);

        $this->assertSame($this->now->getTimestamp() - 86400, $query->changedSince);
        $this->assertNotSame([], $query->mimetypes);
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function request(array $stored): array
    {
        return StoredQuery::toRequest($stored, self::BUILTIN_TYPES, $this->now);
    }
}
