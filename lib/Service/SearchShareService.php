<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCA\DcnFinder\Db\SearchShare;
use OCA\DcnFinder\Db\SearchShareMapper;
use OCA\DcnFinder\Db\SearchShareState;
use OCA\DcnFinder\Db\SearchShareStateMapper;
use OCA\DcnFinder\Search\FileScope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IUserManager;

/**
 * Saved searches shared with users, groups and teams.
 *
 * What is shared is the search, never its results: whoever runs it sees the
 * files they can reach, so a share hands nobody a file they could not already
 * open. A share is a live reference rather than a copy — an edit made with the
 * edit permission is what everyone runs next.
 *
 * Who a share reaches is worked out when it is read, not when it is made, so
 * joining a group is enough to see what was shared with it, and leaving one is
 * enough to lose it.
 *
 * A recipient cannot delete the original; they hide it from their own list.
 * Only the owner deletes a search, and its shares go with it.
 */
class SearchShareService
{
    public function __construct(
        private SavedSearchMapper $searches,
        private SearchShareMapper $shares,
        private SearchShareStateMapper $states,
        private ShareRecipients $recipients,
        private IUserManager $userManager,
        private FileSearchService $files,
        private ShareNotifications $notifications,
    ) {
    }

    // ---- The owner's side --------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     * @throws DoesNotExistException when the search is not the caller's own saved search
     */
    public function sharesOf(string $owner, int $searchId): array
    {
        $this->ownSaved($owner, $searchId);

        return array_map(
            fn (SearchShare $share) => $this->shareToArray($share, $owner),
            array_values($this->shares->findBySearch($searchId)),
        );
    }

    /**
     * Shares a search, or — shared with this recipient already — changes what
     * the existing share allows.
     *
     * @return array<string,mixed>
     * @throws DoesNotExistException when the search is not the caller's own saved search
     * @throws \InvalidArgumentException for a recipient or permission that makes no sense
     * @throws ShareRefusedException when Nextcloud's sharing rules do not allow it
     */
    public function share(string $owner, int $searchId, string $type, string $with, int $permissions): array
    {
        $this->ownSaved($owner, $searchId);
        $with = trim($with);
        if (!in_array($type, SearchShare::TYPES, true) || $with === '') {
            throw new \InvalidArgumentException('Unknown recipient');
        }
        $permissions = self::permissions($permissions);

        $refusal = $this->recipients->refusal($owner, $type, $with);
        if ($refusal !== null) {
            throw new ShareRefusedException($refusal, ShareRecipients::REFUSALS[$refusal]);
        }

        $share = $this->shares->findRecipient($searchId, $type, $with);
        if ($share !== null) {
            $share->setPermissions($permissions);
            $share = $this->shares->update($share);
            // Sharing again with someone who hid it is asking them to look:
            // the owner named this person, so it comes back into their list.
            // Not for a group — one member hiding it is not undone for them
            // because the owner changed what the whole group may do.
            if ($type === SearchShare::TYPE_USER) {
                $this->states->unhide($share->getId());
            }
        } else {
            $share = new SearchShare();
            $share->setSearchId($searchId);
            $share->setShareType($type);
            $share->setShareWith($with);
            $share->setPermissions($permissions);
            $share->setSharedBy($owner);
            $share->setCreated(time());
            $share = $this->shares->insert($share);
            if ($type === SearchShare::TYPE_USER) {
                $this->notifications->shared($owner, $with, $searchId);
            }
        }

        return $this->shareToArray($share, $owner);
    }

    /**
     * @return array<string,mixed>
     * @throws DoesNotExistException when the search or the share is not the caller's
     * @throws \InvalidArgumentException for a permission that makes no sense
     */
    public function updatePermissions(string $owner, int $searchId, int $shareId, int $permissions): array
    {
        $this->ownSaved($owner, $searchId);
        $share = $this->shares->findOfSearch($shareId, $searchId);
        $share->setPermissions(self::permissions($permissions));

        return $this->shareToArray($this->shares->update($share), $owner);
    }

    /** @throws DoesNotExistException when the search or the share is not the caller's */
    public function unshare(string $owner, int $searchId, int $shareId): void
    {
        $this->ownSaved($owner, $searchId);
        $share = $this->shares->findOfSearch($shareId, $searchId);
        $this->states->deleteByShares([$share->getId()]);
        $this->shares->delete($share);
        if ($share->getShareType() === SearchShare::TYPE_USER) {
            $this->notifications->withdraw($searchId, $share->getShareWith());
        }
    }

    /**
     * Which of these searches are shared with anyone, so the owner's list can
     * say so.
     *
     * @param int[] $searchIds
     * @return int[]
     */
    public function sharedAmong(array $searchIds): array
    {
        return $this->shares->sharedAmong($searchIds);
    }

    /** The search is being deleted; nothing may point at it afterwards. */
    public function forgetSearch(int $searchId): void
    {
        $this->states->deleteByShares($this->shares->deleteBySearch($searchId));
        $this->notifications->withdraw($searchId);
    }

    // ---- The recipient's side ----------------------------------------------

    /**
     * Searches others shared with this user, minus the ones they hid, most
     * recently run first. A search reached through two shares — the user's own
     * and a group's — is listed once, with whatever the two allow together.
     *
     * @return list<array<string,mixed>>
     */
    public function sharedWith(string $userId): array
    {
        $reached = $this->reachedBy($userId);
        if ($reached === []) {
            return [];
        }

        $shareIds = [];
        foreach ($reached as ['shares' => $shares]) {
            foreach ($shares as $share) {
                $shareIds[] = $share->getId();
            }
        }
        $states = $this->states->findForUser($userId, $shareIds);

        $out = [];
        foreach ($reached as ['search' => $search, 'shares' => $shares]) {
            $visible = false;
            $lastRun = 0;
            foreach ($shares as $share) {
                $state = $states[$share->getId()] ?? null;
                $visible = $visible || $state === null || $state->getHidden() === 0;
                // Until they run it, a share is as recent as the day it arrived.
                $lastRun = max($lastRun, $state?->getLastRun() ?: $share->getCreated());
            }
            if (!$visible) {
                continue;
            }
            $out[] = [
                ...$this->withReachableScope($userId, $search->toArray()),
                'lastRun'          => $lastRun,
                'mine'             => false,
                'shared'           => true,
                'canEdit'          => self::combined($shares) & SearchShare::PERMISSION_UPDATE ? true : false,
                'owner'            => $search->getUserId(),
                'ownerDisplayName' => $this->userManager->getDisplayName($search->getUserId()) ?? $search->getUserId(),
            ];
        }

        usort($out, static fn (array $a, array $b) => $b['lastRun'] <=> $a['lastRun']);

        return $out;
    }

    /**
     * A search shared with this user that they may change.
     *
     * Hiding a search does not take this away: it tidies a list, it does not
     * give anything up.
     *
     * @throws DoesNotExistException when no share reaching the user allows editing
     */
    public function editableBy(string $userId, int $searchId): SavedSearch
    {
        $reached = $this->reachedBy($userId, $searchId)[$searchId] ?? null;
        if ($reached === null || !(self::combined($reached['shares']) & SearchShare::PERMISSION_UPDATE)) {
            throw new DoesNotExistException('Not shared with this user for editing');
        }

        return $reached['search'];
    }

    /**
     * Records that a recipient ran a shared search, for the order of their own
     * list. The owner's last_run stays the owner's.
     *
     * @throws DoesNotExistException when the search is not shared with the user
     */
    public function markRun(string $userId, int $searchId): void
    {
        $this->updateState($userId, $searchId, static fn (SearchShareState $s) => $s->setLastRun(time()));
    }

    /**
     * Removes a shared search from this user's list. The share stays, and so
     * does it for everyone else the share reaches.
     *
     * @throws DoesNotExistException when the search is not shared with the user
     */
    public function hide(string $userId, int $searchId): void
    {
        $this->updateState($userId, $searchId, static fn (SearchShareState $s) => $s->setHidden(1));
        // Removed from the list, so no longer something to be told about.
        $this->notifications->withdraw($searchId, $userId);
    }

    /**
     * A search shared with this user, hidden or not.
     *
     * @throws DoesNotExistException when it is not, or no longer, shared with them
     */
    public function sharedSearch(string $userId, int $searchId): SavedSearch
    {
        $reached = $this->reachedBy($userId, $searchId)[$searchId] ?? null;
        if ($reached === null) {
            throw new DoesNotExistException('Not shared with this user');
        }

        return $reached['search'];
    }

    /**
     * The search shared with this user that carries this exact query, if any —
     * so that running it by hand is a run of that search rather than a new
     * recent beside it.
     */
    public function findByFingerprint(string $userId, string $fingerprint): ?SavedSearch
    {
        foreach ($this->reachedBy($userId) as ['search' => $search]) {
            if ($search->getFingerprint() === $fingerprint) {
                return $search;
            }
        }

        return null;
    }

    // ---- Clean-up ----------------------------------------------------------

    /**
     * An account is being deleted: shares of its searches, shares with it, and
     * what it kept of shares that reached it.
     *
     * @param int[] $ownSearchIds the searches the account owned
     */
    public function forgetUser(string $userId, array $ownSearchIds): void
    {
        foreach ($ownSearchIds as $searchId) {
            $this->forgetSearch($searchId);
        }
        $this->states->deleteByShares($this->shares->deleteByRecipient(SearchShare::TYPE_USER, $userId));
        $this->states->deleteByUser($userId);
    }

    public function forgetGroup(string $groupId): void
    {
        $this->states->deleteByShares($this->shares->deleteByRecipient(SearchShare::TYPE_GROUP, $groupId));
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * A shared search whose scope the recipient cannot reach keeps its scope —
     * running it still says so — but loses the scope's name. The label is a
     * snapshot the owner's picker took, and it names a folder or dossier this
     * reader has no right to see the name of.
     *
     * @param array<string,mixed> $entry as SavedSearch::toArray() gives it
     * @return array<string,mixed> with `scopeUnavailable` added
     */
    private function withReachableScope(string $userId, array $entry): array
    {
        $query = $entry['query'];
        $scope = is_array($query) && is_array($query['scope'] ?? null) ? $query['scope'] : null;
        if ($scope === null) {
            return [...$entry, 'scopeUnavailable' => false];
        }

        try {
            $reachable = $this->files->scopeReachable($userId, FileScope::fromArray($scope));
        } catch (\Throwable) {
            // An unusable scope reaches nothing, and a failing lookup must not
            // take the rest of the list with it.
            $reachable = false;
        }
        if ($reachable) {
            return [...$entry, 'scopeUnavailable' => false];
        }

        $query['scope']['label'] = '';

        return [...$entry, 'query' => $query, 'scopeUnavailable' => true];
    }

    /**
     * Saved searches of others that reach this user, with the shares through
     * which they do.
     *
     * @return array<int, array{search: SavedSearch, shares: list<SearchShare>}> by search id
     */
    private function reachedBy(string $userId, ?int $searchId = null): array
    {
        $byId = [];
        foreach ($this->shares->findReaching($this->recipients->membershipsOf($userId), $searchId) as $share) {
            $byId[$share->getSearchId()][] = $share;
        }
        if ($byId === []) {
            return [];
        }

        $reached = [];
        foreach ($this->searches->findSavedByIds(array_keys($byId)) as $search) {
            // A search shared with a group its owner is in reaches the owner
            // too; it is already in their own list.
            if ($search->getUserId() === $userId) {
                continue;
            }
            $reached[$search->getId()] = ['search' => $search, 'shares' => $byId[$search->getId()]];
        }

        return $reached;
    }

    /**
     * Applies a change to the user's state on every share through which the
     * search reaches them. Every one, because hiding it through the group share
     * alone would leave it in the list through their own.
     *
     * @param callable(SearchShareState): void $change
     * @throws DoesNotExistException when the search is not shared with the user
     */
    private function updateState(string $userId, int $searchId, callable $change): void
    {
        $reached = $this->reachedBy($userId, $searchId)[$searchId] ?? null;
        if ($reached === null) {
            throw new DoesNotExistException('Not shared with this user');
        }

        $shareIds = array_map(static fn (SearchShare $s) => $s->getId(), $reached['shares']);
        $states = $this->states->findForUser($userId, $shareIds);

        foreach ($shareIds as $shareId) {
            $state = $states[$shareId] ?? null;
            if ($state !== null) {
                $change($state);
                $this->states->update($state);
                continue;
            }

            $state = new SearchShareState();
            $state->setShareId($shareId);
            $state->setUserId($userId);
            $change($state);
            try {
                $this->states->insert($state);
            } catch (DbException $e) {
                // A second request got there first; its row will do.
                if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
        }
    }

    /** @throws DoesNotExistException */
    private function ownSaved(string $owner, int $searchId): SavedSearch
    {
        $search = $this->searches->findOwned($searchId, $owner);
        if ($search->getKind() !== SavedSearch::KIND_SAVED) {
            // A recent is trimmed and deduplicated behind its owner's back;
            // sharing one would hand out something that may vanish.
            throw new DoesNotExistException('Only saved searches can be shared');
        }

        return $search;
    }

    /** @param list<SearchShare> $shares */
    private static function combined(array $shares): int
    {
        return array_reduce($shares, static fn (int $all, SearchShare $s) => $all | $s->getPermissions(), 0);
    }

    /**
     * Every share can at least be read; anything beyond the bits a share may
     * carry today is refused rather than stored, so a future bit cannot arrive
     * early through a hand-made request.
     *
     * @throws \InvalidArgumentException
     */
    private static function permissions(int $permissions): int
    {
        if (($permissions & ~SearchShare::PERMISSION_ALL) !== 0) {
            throw new \InvalidArgumentException('Unknown permission');
        }

        return $permissions | SearchShare::PERMISSION_READ;
    }

    /** @return array<string,mixed> */
    private function shareToArray(SearchShare $share, string $viewer): array
    {
        return [
            'id'          => $share->getId(),
            'type'        => $share->getShareType(),
            'with'        => $share->getShareWith(),
            'displayName' => $this->recipients->displayName($share->getShareType(), $share->getShareWith(), $viewer),
            'canEdit'     => $share->can(SearchShare::PERMISSION_UPDATE),
            'created'     => $share->getCreated(),
        ];
    }
}
