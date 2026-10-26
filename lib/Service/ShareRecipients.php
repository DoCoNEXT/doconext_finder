<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Db\SearchShare;
use OCP\App\IAppManager;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Teams\ITeamManager;
use OCP\Teams\Team;
use Psr\Log\LoggerInterface;

/**
 * Who a search can be shared with, and who a share reaches.
 *
 * Everything here defers to Nextcloud's own sharing: the same people search the
 * Files share dialog uses, and the same admin rules — sharing disabled for some
 * groups, no group shares, only within one's own groups. A search shared past
 * those rules would be a way around them.
 *
 * Deliberately not DoCoNEXT Core's principal lookup: that one answers for Core's
 * metadata fields, and sharing has to work without Core.
 */
class ShareRecipients
{
    /** How many people a typeahead offers per kind. */
    private const SEARCH_LIMIT = 10;

    /**
     * Why a share was refused, in English. The page shows its own translation
     * of each — see ShareRefusedException.
     */
    public const REFUSALS = [
        'sharing_disabled' => 'You are not allowed to share',
        'self' => 'You cannot share a search with yourself',
        'no_such_user' => 'This user does not exist',
        'not_in_shared_group' => 'You can only share with members of your own groups',
        'group_sharing_disabled' => 'Sharing with groups is not allowed',
        'no_such_group' => 'This group does not exist',
        'not_group_member' => 'You can only share with groups you are a member of',
        'not_team_member' => 'You can only share with teams you are a member of',
        'unknown_type' => 'Unknown kind of recipient',
    ];

    /** @var array<string, array<string, Team>> */
    private array $teams = [];

    public function __construct(
        private ISearch $collaborators,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IShareManager $shareManager,
        private ITeamManager $teamManager,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * People, groups and teams matching a typed name, as the share dialog
     * offers them. Searches as the signed-in user, so the admin's limits on
     * who may be found apply.
     *
     * `subname` is the second line the Files share dialog shows: what tells
     * two people of the same name apart (their e-mail address, or their id),
     * and a team's own description. Empty for a group, which has neither.
     *
     * @return list<array{type: string, id: string, displayName: string, subname: string}>
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $types = [IShare::TYPE_USER];
        if ($this->shareManager->allowGroupSharing()) {
            $types[] = IShare::TYPE_GROUP;
        }
        if ($this->appManager->isEnabledForUser('circles')) {
            $types[] = IShare::TYPE_CIRCLE;
        }

        /** @var array{0: array{exact?: array<string, list<array<string, mixed>>>, ...<string, list<array<string, mixed>>>}, 1: bool} $answer */
        $answer = $this->collaboratorSearch($term, $types);
        $found = $answer[0];

        $out = [];
        $seen = [];
        foreach (['users' => SearchShare::TYPE_USER, 'groups' => SearchShare::TYPE_GROUP, 'circles' => SearchShare::TYPE_CIRCLE] as $key => $type) {
            // An exact match is listed apart from the rest; it belongs first.
            $exact = $found['exact'][$key] ?? [];
            $rest = $found[$key] ?? [];
            foreach ([...$exact, ...$rest] as $row) {
                /** @var array{label?: string, value?: array{shareWith?: string}, shareWithDisplayNameUnique?: string, shareWithDescription?: string} $row */
                $id = (string)($row['value']['shareWith'] ?? '');
                if ($id === '' || isset($seen[$type][$id])) {
                    continue;
                }
                $seen[$type][$id] = true;
                $name = (string)($row['label'] ?? $id);
                $subname = (string)($row['shareWithDisplayNameUnique'] ?? $row['shareWithDescription'] ?? '');
                $out[] = [
                    'type'        => $type,
                    'id'          => $id,
                    'displayName' => $name,
                    // A second line that only repeats the first says nothing.
                    'subname'     => $subname === $name ? '' : $subname,
                ];
            }
        }

        return $out;
    }

    /**
     * Nextcloud's collaborator search, as the Files share dialog runs it.
     *
     * NC35 replaced search() with filteredSearch(), which also lets other apps
     * narrow the result through AutoCompleteFilterEvent; NC35's search() skips
     * that event. NC33 and NC34 have only search().
     *
     * @param list<int> $types IShare::TYPE_*
     * @return array
     */
    private function collaboratorSearch(string $term, array $types): array
    {
        if (method_exists($this->collaborators, 'filteredSearch')) {
            return $this->collaborators->filteredSearch($term, $types, false, null, null, self::SEARCH_LIMIT, 0);
        }

        /** @psalm-suppress DeprecatedMethod the only search NC33 and NC34 have */
        return $this->collaborators->search($term, $types, false, self::SEARCH_LIMIT, 0);
    }

    /**
     * Everything a user is reached through: themselves, their groups, their
     * teams. Asked at read time, so joining a group later is enough to see what
     * was shared with it.
     *
     * @return array<string, list<string>> share type => ids
     */
    public function membershipsOf(string $userId): array
    {
        return [
            SearchShare::TYPE_USER   => [$userId],
            SearchShare::TYPE_GROUP  => $this->groupsOf($userId),
            SearchShare::TYPE_CIRCLE => array_keys($this->teamsOf($userId)),
        ];
    }

    /**
     * Whether a user may share with this recipient — the rules the Files share
     * dialog enforces, applied to searches.
     *
     * @return string|null null when allowed; otherwise one of the {@see REFUSALS} keys
     */
    public function refusal(string $sharer, string $type, string $with): ?string
    {
        if (!$this->shareManager->shareApiEnabled() || $this->shareManager->sharingDisabledForUser($sharer)) {
            return 'sharing_disabled';
        }

        switch ($type) {
            case SearchShare::TYPE_USER:
                if ($with === $sharer) {
                    return 'self';
                }
                if (!$this->userManager->userExists($with)) {
                    return 'no_such_user';
                }
                if ($this->shareManager->shareWithGroupMembersOnly() && !$this->shareAGroup($sharer, $with)) {
                    return 'not_in_shared_group';
                }

                return null;

            case SearchShare::TYPE_GROUP:
                if (!$this->shareManager->allowGroupSharing()) {
                    return 'group_sharing_disabled';
                }
                if (!$this->groupManager->groupExists($with)) {
                    return 'no_such_group';
                }
                if ($this->shareManager->shareWithGroupMembersOnly()
                    && !in_array($with, $this->ownGroups($sharer), true)) {
                    return 'not_group_member';
                }

                return null;

            case SearchShare::TYPE_CIRCLE:
                // Only a team one is in, as the share dialog offers.
                if (!isset($this->teamsOf($sharer)[$with])) {
                    return 'not_team_member';
                }

                return null;
        }

        return 'unknown_type';
    }

    /**
     * What to call a recipient; its id when it no longer resolves.
     *
     * A team is named from the teams the viewer is in. Nextcloud 33 has no way
     * to look one team up by id (ITeamManager::getTeam() is 35), and the one
     * asking is the owner, who could only share with a team they are in.
     */
    public function displayName(string $type, string $with, string $viewer): string
    {
        $name = match ($type) {
            SearchShare::TYPE_USER   => $this->userManager->getDisplayName($with),
            SearchShare::TYPE_GROUP  => $this->groupManager->getDisplayName($with),
            SearchShare::TYPE_CIRCLE => ($this->teamsOf($viewer)[$with] ?? null)?->getDisplayName(),
            default                  => null,
        };

        return $name ?? $with;
    }

    /**
     * The groups that count for "only within one's own groups".
     *
     * @return list<string>
     */
    private function ownGroups(string $userId): array
    {
        return array_values(array_diff(
            $this->groupsOf($userId),
            $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList(),
        ));
    }

    private function shareAGroup(string $sharer, string $recipient): bool
    {
        return array_intersect($this->ownGroups($sharer), $this->groupsOf($recipient)) !== [];
    }

    /**
     * The teams a user is a member of. Empty without the Teams app, and empty
     * — logged, not thrown — when it fails: an unreadable team must not take
     * the user's own and group shares down with it.
     *
     * Remembered per request: the share list asks once per team share.
     *
     * @return array<string, Team> by team id
     */
    private function teamsOf(string $userId): array
    {
        if (isset($this->teams[$userId])) {
            return $this->teams[$userId];
        }
        try {
            $teams = [];
            foreach ($this->teamManager->getTeamsForUser($userId) as $team) {
                $teams[$team->getId()] = $team;
            }

            return $this->teams[$userId] = $teams;
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Could not read a user\'s teams', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return [];
        }
    }

    /**
     * getUserGroupIds() takes an IUser, not an id. A user that no longer exists
     * is in no group.
     *
     * @return list<string>
     */
    private function groupsOf(string $userId): array
    {
        $user = $this->userManager->get($userId);

        return $user === null ? [] : array_map('strval', $this->groupManager->getUserGroupIds($user));
    }
}
