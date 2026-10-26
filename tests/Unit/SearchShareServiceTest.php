<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCA\DcnFinder\Db\SearchShare;
use OCA\DcnFinder\Db\SearchShareMapper;
use OCA\DcnFinder\Db\SearchShareState;
use OCA\DcnFinder\Db\SearchShareStateMapper;
use OCA\DcnFinder\Service\FileSearchService;
use OCA\DcnFinder\Service\SearchShareService;
use OCA\DcnFinder\Service\ShareNotifications;
use OCA\DcnFinder\Service\ShareRecipients;
use OCA\DcnFinder\Service\ShareRefusedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Who can share a search, who a share reaches, and what it lets them do.
 *
 * The rules that keep sharing from becoming a way around access: only the
 * owner shares or unshares, only Nextcloud's sharing rules decide who with,
 * a share carries no permission it was not given, and a recipient who may only
 * read cannot edit.
 */
class SearchShareServiceTest extends TestCase
{
    private SavedSearchMapper&MockObject $searches;
    private SearchShareMapper&MockObject $shares;
    private SearchShareStateMapper&MockObject $states;
    private ShareRecipients&MockObject $recipients;
    private FileSearchService&MockObject $files;
    private ShareNotifications&MockObject $notifications;

    protected function setUp(): void
    {
        $this->searches = $this->createMock(SavedSearchMapper::class);
        $this->shares = $this->createMock(SearchShareMapper::class);
        $this->states = $this->createMock(SearchShareStateMapper::class);
        $this->recipients = $this->createMock(ShareRecipients::class);
        $this->files = $this->createMock(FileSearchService::class);
        $this->notifications = $this->createMock(ShareNotifications::class);
        $this->recipients->method('displayName')->willReturnArgument(1);
        $this->recipients->method('membershipsOf')->willReturnCallback(static fn (string $uid) => [
            SearchShare::TYPE_USER   => [$uid],
            SearchShare::TYPE_GROUP  => ['staff'],
            SearchShare::TYPE_CIRCLE => [],
        ]);
    }

    public function testOnlyTheOwnerCanShare(): void
    {
        $this->searches->method('findOwned')->with(7, 'mallory')->willThrowException(new DoesNotExistException('not yours'));
        $this->shares->expects($this->never())->method('insert');

        $this->expectException(DoesNotExistException::class);

        $this->service()->share('mallory', 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ);
    }

    public function testARecentCannotBeShared(): void
    {
        $recent = self::search(7, 'alice');
        $recent->setKind(SavedSearch::KIND_RECENT);
        $this->searches->method('findOwned')->willReturn($recent);
        $this->shares->expects($this->never())->method('insert');

        $this->expectException(DoesNotExistException::class);

        $this->service()->share('alice', 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ);
    }

    public function testNextcloudsSharingRulesHaveTheLastWord(): void
    {
        $this->searches->method('findOwned')->willReturn(self::search(7, 'alice'));
        $this->recipients->method('refusal')->with('alice', SearchShare::TYPE_GROUP, 'board')->willReturn('not_group_member');
        $this->shares->expects($this->never())->method('insert');

        try {
            $this->service()->share('alice', 7, SearchShare::TYPE_GROUP, 'board', SearchShare::PERMISSION_READ);
            $this->fail('A refused share was made');
        } catch (ShareRefusedException $e) {
            $this->assertSame('not_group_member', $e->reason);
        }
    }

    /** A bit no share may carry yet — resharing, say — is refused, not stored. */
    public function testAnUnknownPermissionIsRefused(): void
    {
        $this->searches->method('findOwned')->willReturn(self::search(7, 'alice'));
        $this->recipients->method('refusal')->willReturn(null);
        $this->shares->expects($this->never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->share('alice', 7, SearchShare::TYPE_USER, 'bob', 4);
    }

    public function testEveryShareCanAtLeastBeRead(): void
    {
        $this->searches->method('findOwned')->willReturn(self::search(7, 'alice'));
        $this->recipients->method('refusal')->willReturn(null);
        $this->shares->method('findRecipient')->willReturn(null);
        $this->shares->expects($this->once())->method('insert')
            ->with($this->callback(static fn (SearchShare $s) => $s->getPermissions() === SearchShare::PERMISSION_READ
                && $s->getSharedBy() === 'alice'))
            ->willReturnArgument(0);

        $this->service()->share('alice', 7, SearchShare::TYPE_USER, 'bob', 0);
    }

    public function testANewShareWithAPersonNotifiesThem(): void
    {
        $this->searches->method('findOwned')->willReturn(self::search(7, 'alice'));
        $this->recipients->method('refusal')->willReturn(null);
        $this->shares->method('findRecipient')->willReturn(null);
        $this->shares->method('insert')->willReturnArgument(0);
        $this->notifications->expects($this->once())->method('shared')->with('alice', 'bob', 7);

        $this->service()->share('alice', 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ);
    }

    /** A group could be the whole company; its members find it in their list. */
    public function testAGroupShareNotifiesNobody(): void
    {
        $this->searches->method('findOwned')->willReturn(self::search(7, 'alice'));
        $this->recipients->method('refusal')->willReturn(null);
        $this->shares->method('findRecipient')->willReturn(null);
        $this->shares->method('insert')->willReturnArgument(0);
        $this->notifications->expects($this->never())->method('shared');

        $this->service()->share('alice', 7, SearchShare::TYPE_GROUP, 'staff', SearchShare::PERMISSION_READ);
    }

    public function testOnlyTheOwnerCanUnshare(): void
    {
        $this->searches->method('findOwned')->with(7, 'bob')->willThrowException(new DoesNotExistException('not yours'));
        $this->shares->expects($this->never())->method('delete');

        $this->expectException(DoesNotExistException::class);

        $this->service()->unshare('bob', 7, 1);
    }

    /**
     * Reached twice — directly and through a group — a search is listed once,
     * with what both shares allow together. Hiding it through one share does
     * not hide it while the other still shows it; that is why hide() writes
     * to every share at once.
     */
    public function testASearchReachedTwiceIsListedOnceWithBothPermissions(): void
    {
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ),
            self::share(2, 7, SearchShare::TYPE_GROUP, 'staff', SearchShare::PERMISSION_ALL),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([self::search(7, 'alice')]);
        $this->states->method('findForUser')->willReturn([1 => self::state(1, 'bob', hidden: 1)]);

        $listed = $this->service()->sharedWith('bob');

        $this->assertCount(1, $listed);
        $this->assertTrue($listed[0]['canEdit']);
        $this->assertFalse($listed[0]['mine']);
        $this->assertSame('alice', $listed[0]['owner']);
    }

    public function testASearchHiddenThroughEveryShareIsNotListed(): void
    {
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_GROUP, 'staff', SearchShare::PERMISSION_READ),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([self::search(7, 'alice')]);
        $this->states->method('findForUser')->willReturn([1 => self::state(1, 'bob', hidden: 1)]);

        $this->assertSame([], $this->service()->sharedWith('bob'));
    }

    /** Shared with a group the owner is in: it is already in their own list. */
    public function testTheOwnerIsNotReachedByTheirOwnShare(): void
    {
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_GROUP, 'staff', SearchShare::PERMISSION_ALL),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([self::search(7, 'alice')]);
        $this->states->method('findForUser')->willReturn([]);

        $this->assertSame([], $this->service()->sharedWith('alice'));
    }

    /**
     * The owner's picker named the folder; a recipient who cannot open it must
     * not learn its name from the list. The scope itself stays, so running the
     * search still says it reaches nowhere.
     */
    public function testTheNameOfAScopeTheRecipientCannotReachIsWithheld(): void
    {
        $search = self::search(7, 'alice');
        $search->setQuery('{"term":"quote","scope":{"level":"folder","id":42,"label":"Board/Salaries"}}');
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([$search]);
        $this->states->method('findForUser')->willReturn([]);
        $this->files->method('scopeReachable')->willReturn(false);

        $listed = $this->service()->sharedWith('bob')[0];

        $this->assertTrue($listed['scopeUnavailable']);
        $this->assertSame('', $listed['query']['scope']['label']);
        $this->assertSame(42, $listed['query']['scope']['id']);
    }

    public function testAReadOnlyShareDoesNotAllowEditing(): void
    {
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_USER, 'bob', SearchShare::PERMISSION_READ),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([self::search(7, 'alice')]);

        $this->expectException(DoesNotExistException::class);

        $this->service()->editableBy('bob', 7);
    }

    public function testAnEditableShareAllowsEditing(): void
    {
        $search = self::search(7, 'alice');
        $this->shares->method('findReaching')->willReturn([
            self::share(1, 7, SearchShare::TYPE_GROUP, 'staff', SearchShare::PERMISSION_ALL),
        ]);
        $this->searches->method('findSavedByIds')->willReturn([$search]);

        $this->assertSame($search, $this->service()->editableBy('bob', 7));
    }

    public function testHidingASearchNotSharedWithTheUserIsNotFound(): void
    {
        $this->shares->method('findReaching')->willReturn([]);
        $this->states->expects($this->never())->method('insert');
        $this->states->expects($this->never())->method('update');

        $this->expectException(DoesNotExistException::class);

        $this->service()->hide('mallory', 7);
    }

    private function service(): SearchShareService
    {
        $users = $this->createMock(IUserManager::class);
        $users->method('getDisplayName')->willReturnArgument(0);

        return new SearchShareService($this->searches, $this->shares, $this->states, $this->recipients, $users, $this->files, $this->notifications);
    }

    private static function search(int $id, string $owner): SavedSearch
    {
        $search = new SavedSearch();
        $search->setId($id);
        $search->setUserId($owner);
        $search->setKind(SavedSearch::KIND_SAVED);
        $search->setName('Quotes this year');
        $search->setQuery('{"term":"quote"}');

        return $search;
    }

    private static function share(int $id, int $searchId, string $type, string $with, int $permissions): SearchShare
    {
        $share = new SearchShare();
        $share->setId($id);
        $share->setSearchId($searchId);
        $share->setShareType($type);
        $share->setShareWith($with);
        $share->setPermissions($permissions);
        $share->setSharedBy('alice');
        $share->setCreated(1000);

        return $share;
    }

    private static function state(int $shareId, string $userId, int $hidden): SearchShareState
    {
        $state = new SearchShareState();
        $state->setShareId($shareId);
        $state->setUserId($userId);
        $state->setHidden($hidden);

        return $state;
    }
}
