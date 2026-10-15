<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCA\DcnFinder\Service\SearchHistoryService;
use OCA\DcnFinder\Service\SearchShareService;
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
 *
 * A search shared with the caller is the one way past that, and only through
 * {@see SearchShareService}; with nothing shared, the answer stays the owner's
 * "not found".
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

        $call($this->service($mapper));
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

        $call($this->service($mapper), ['term' => str_repeat('a', SearchHistoryService::MAX_QUERY_BYTES)]);
    }

    public function testListingAsksOnlyForTheCallersOwnRows(): void
    {
        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->expects($this->once())
            ->method('findByKind')
            ->with('alice', SavedSearch::KIND_SAVED, $this->anything())
            ->willReturn([]);

        $this->assertSame([], $this->service($mapper)->list('alice', SavedSearch::KIND_SAVED));
    }

    public function testARecipientWhoMayEditRenamesTheSharedSearch(): void
    {
        $shared = self::saved(7, 'carol');

        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->method('findOwned')->willThrowException(new DoesNotExistException('not yours'));
        $mapper->expects($this->once())->method('update')->with($shared)->willReturnArgument(0);

        $shares = $this->createMock(SearchShareService::class);
        $shares->expects($this->once())->method('editableBy')->with('alice', 7)->willReturn($shared);

        $this->assertSame('Ours now', $this->service($mapper, $shares)->rename('alice', 7, 'Ours now', '')['name']);
    }

    /**
     * The owner orders their list by when they ran a search; a recipient
     * editing it must not move it there. The editor's own order moves instead.
     */
    public function testARecipientReplacingTheQueryLeavesTheOwnersLastRunAlone(): void
    {
        $shared = self::saved(7, 'carol');
        $shared->setLastRun(1000);
        $shared->resetUpdatedFields();

        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->method('findOwned')->willThrowException(new DoesNotExistException('not yours'));
        $mapper->method('findByFingerprint')->willReturn(null);
        $mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $shares = $this->createMock(SearchShareService::class);
        $shares->method('editableBy')->willReturn($shared);
        $shares->expects($this->once())->method('markRun')->with('alice', 7);

        $this->service($mapper, $shares)->replaceQuery('alice', 7, ['term' => 'new']);

        $this->assertSame(1000, $shared->getLastRun());
        $this->assertArrayNotHasKey('lastRun', $shared->getUpdatedFields());
    }

    public function testDeletingASavedSearchTakesItsSharesWithIt(): void
    {
        $own = self::saved(7, 'alice');

        $mapper = $this->createMock(SavedSearchMapper::class);
        $mapper->method('findOwned')->with(7, 'alice')->willReturn($own);
        $mapper->expects($this->once())->method('delete')->with($own);

        $shares = $this->createMock(SearchShareService::class);
        $shares->expects($this->once())->method('forgetSearch')->with(7);

        $this->service($mapper, $shares)->delete('alice', 7);
    }

    /**
     * Without a share service told otherwise, nothing is shared with anyone:
     * every lookup through it answers "not found", as the owner check does.
     */
    private function service(SavedSearchMapper $mapper, ?SearchShareService $shares = null): SearchHistoryService
    {
        if ($shares === null) {
            $shares = $this->createMock(SearchShareService::class);
            $shares->method('editableBy')->willThrowException(new DoesNotExistException('not shared'));
            $shares->method('markRun')->willThrowException(new DoesNotExistException('not shared'));
            $shares->method('findByFingerprint')->willReturn(null);
            $shares->method('sharedAmong')->willReturn([]);
        }

        return new SearchHistoryService($mapper, $shares);
    }

    private static function saved(int $id, string $owner): SavedSearch
    {
        $search = new SavedSearch();
        $search->setId($id);
        $search->setUserId($owner);
        $search->setKind(SavedSearch::KIND_SAVED);
        $search->setName('Quotes this year');
        $search->setQuery('{"term":"quote"}');
        $search->resetUpdatedFields();

        return $search;
    }
}
