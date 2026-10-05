<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCA\DcnFinder\Service\SearchHistoryService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whose saved searches a request can reach.
 *
 * Every route that takes an id ends in {@see SavedSearchMapper::findOwned()},
 * which matches on the id *and* the caller: someone else's id is simply not
 * found. These tests pin that every such path asks with the caller's own uid
 * and does nothing further when the answer is "not found".
 */
class SearchHistoryServiceTest extends TestCase
{
    /** @return array<string, array{\Closure(SearchHistoryService): mixed}> */
    public static function idTakingPaths(): array
    {
        return [
            'rename'       => [static fn (SearchHistoryService $s) => $s->rename('alice', 7, 'Mine now', '')],
            'replaceQuery' => [static fn (SearchHistoryService $s) => $s->replaceQuery('alice', 7, ['term' => 'x'])],
            'touch'        => [static fn (SearchHistoryService $s) => $s->touch('alice', 7)],
            'delete'       => [static fn (SearchHistoryService $s) => $s->delete('alice', 7)],
        ];
    }

    /** @param \Closure(SearchHistoryService): mixed $call */
    #[DataProvider('idTakingPaths')]
    public function testAnIdTheCallerDoesNotOwnIsNotFoundAndNothingIsWritten(\Closure $call): void
    {
        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->expects($this->once())
            ->method('findOwned')
            ->with(7, 'alice')
            ->willThrowException(new DoesNotExistException('not yours'));
        $mapper->expects($this->never())->method('update');
        $mapper->expects($this->never())->method('delete');
        $mapper->expects($this->never())->method('insert');

        $this->expectException(DoesNotExistException::class);

        $call(new SearchHistoryService($mapper));
    }

    /** @return array<string, array{\Closure(SearchHistoryService, array<string,mixed>): mixed}> */
    public static function queryWritingPaths(): array
    {
        return [
            'save'         => [static fn (SearchHistoryService $s, array $q) => $s->save('alice', 'Big', '', $q)],
            'recordRecent' => [static fn (SearchHistoryService $s, array $q) => $s->recordRecent('alice', $q)],
            'replaceQuery' => [static fn (SearchHistoryService $s, array $q) => $s->replaceQuery('alice', 7, $q)],
        ];
    }

    /** @param \Closure(SearchHistoryService, array<string,mixed>): mixed $call */
    #[DataProvider('queryWritingPaths')]
    public function testAQueryTooLargeToKeepIsRefusedAndNothingIsWritten(\Closure $call): void
    {
        $owned = new SavedSearch();
        $owned->setKind(SavedSearch::KIND_SAVED);

        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->method('countByKind')->willReturn(0);
        $mapper->method('findByFingerprint')->willReturn(null);
        $mapper->method('findOwned')->willReturn($owned);
        $mapper->expects($this->never())->method('insert');
        $mapper->expects($this->never())->method('update');

        $this->expectException(\InvalidArgumentException::class);

        $call(new SearchHistoryService($mapper), ['term' => str_repeat('a', SearchHistoryService::MAX_QUERY_BYTES)]);
    }

    public function testListingAsksOnlyForTheCallersOwnRows(): void
    {
        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->expects($this->once())
            ->method('findByKind')
            ->with('alice', SavedSearch::KIND_SAVED, $this->anything())
            ->willReturn([]);

        $this->assertSame([], (new SearchHistoryService($mapper))->list('alice', SavedSearch::KIND_SAVED));
    }
}
