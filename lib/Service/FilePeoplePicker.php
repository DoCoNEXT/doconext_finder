<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Db\SearchShare;
use OCA\DcnFinder\Search\FileQuery;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/**
 * Who the "Created by" and "Modified by" filters can be set to.
 *
 * Everyone the share dialog would offer, and also people who have left, because
 * what someone did before leaving is a large part of why this filter is used:
 *
 * - a disabled account is listed by name, unless the admin limits who can be
 *   found, since the list would then show people the share dialog keeps hidden;
 * - any account that is gone or disabled is offered on its exact id, but only
 *   if the searcher can see a file it made or changed, as the filter asks. That says nothing they could not
 *   already find out, so it needs no such limit. A deleted account has no name
 *   left, and the index holds only a hash, so the id is all there is to go on.
 */
class FilePeoplePicker
{
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DELETED = 'deleted';

    private const LIMIT = 10;

    /** A shorter term is still being typed, and is not worth a file search. */
    private const MIN_EXACT_ID_LENGTH = 3;

    public function __construct(
        private ShareRecipients $recipients,
        private IUserManager $userManager,
        private IShareManager $shareManager,
        private FileSearchService $files,
    ) {
    }

    /**
     * @return list<array{type: string, id: string, displayName: string, subname: string, status?: string}>
     */
    public function search(string $field, string $term, IUser $me): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $people = $this->recipients->searchUsers($term, $me);
        $seen = array_flip(array_column($people, 'id'));

        if ($this->mayListEveryone()) {
            foreach ($this->userManager->getDisabledUsers(self::LIMIT, 0, $term) as $user) {
                if (!isset($seen[$user->getUID()])) {
                    $seen[$user->getUID()] = true;
                    $people[] = self::entry($user->getUID(), $user->getDisplayName(), self::STATUS_DISABLED);
                }
            }
        }

        if (!isset($seen[$term])) {
            $former = $this->formerAccount($field, $term, $me);
            if ($former !== null) {
                $people[] = $former;
            }
        }

        return $people;
    }

    /**
     * The account with exactly this id, if it no longer works and the searcher
     * can see a file the filter would find for it.
     *
     * @return array{type: string, id: string, displayName: string, subname: string, status: string}|null
     */
    private function formerAccount(string $field, string $id, IUser $me): ?array
    {
        if (strlen($id) < self::MIN_EXACT_ID_LENGTH) {
            return null;
        }

        $user = $this->userManager->get($id);
        if (($user !== null && $user->isEnabled()) || !$this->seesFileOf($field, $me, $id)) {
            return null;
        }

        return $user !== null
            ? self::entry($id, $user->getDisplayName(), self::STATUS_DISABLED)
            : self::entry($id, $id, self::STATUS_DELETED);
    }

    private function seesFileOf(string $field, IUser $me, string $id): bool
    {
        try {
            $found = $this->files->search($me->getUID(), FileQuery::fromArray([
                'conditions' => [['field' => $field, 'operator' => 'eq', 'value' => $id]],
                'limit' => 1,
            ]));
        } catch (\Throwable) {
            // A suggestion is not worth failing the box over.
            return false;
        }

        return $found['results'] !== [];
    }

    private function mayListEveryone(): bool
    {
        return $this->shareManager->allowEnumeration()
            && !$this->shareManager->limitEnumerationToGroups()
            && !$this->shareManager->limitEnumerationToPhone()
            && !$this->shareManager->shareWithGroupMembersOnly();
    }

    /** @return array{type: string, id: string, displayName: string, subname: string, status: string} */
    private static function entry(string $id, string $name, string $status): array
    {
        return [
            'type'        => SearchShare::TYPE_USER,
            'id'          => $id,
            'displayName' => $name,
            'subname'     => $id === $name ? '' : $id,
            'status'      => $status,
        ];
    }
}
