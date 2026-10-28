<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCA\DcnFinder\Db\SearchSchedule;
use OCA\DcnFinder\Db\SearchScheduleMapper;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\StoredQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Config\IUserConfig;
use OCP\DB\Exception as DbException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Scheduled searches: a saved search that runs on its own every morning, or
 * every Friday, and says how much is new since the user last looked.
 *
 * Nextcloud has no change tracking for files — nothing like SharePoint's change
 * tokens — and none is needed: "written or uploaded after the user last looked"
 * is a condition the filecache answers directly (FileQuery::$changedSince), and
 * it runs with the user's own permissions, so a schedule can never count a file
 * its user could not open.
 *
 * What it says is a number, never names. A notification leaves the instance —
 * to a phone, to a reminder mail — and a file name is often the sensitive part.
 *
 * Each user sets their own schedule, on their own searches and on searches
 * shared with them; one on a shared search is that user's alone.
 */
class ScheduleService
{
    /**
     * How many schedules one user may keep. Every one is a full search on the
     * server once a day, and a schedule is a question someone wants answered —
     * a handful, not a batch system.
     */
    public const MAX_SCHEDULES = 20;

    /**
     * How many files a run counts before it stops and says "more than". The
     * number is for a notification; past a hundred, the next one changes
     * nothing about what the user does.
     */
    public const COUNT_LIMIT = 100;

    /** How many due schedules one pass of the background job takes on. */
    public const BATCH = 50;

    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private SearchScheduleMapper $schedules,
        private SavedSearchMapper $searches,
        private SearchShareService $shares,
        private FileSearchService $files,
        private FileTypeFilterSettings $typeFilters,
        private ScheduleNotifications $notifications,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private IUserConfig $userConfig,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    // ---- The user's side ---------------------------------------------------

    /** @return array<int, SearchSchedule> by search id */
    public function forUser(string $userId): array
    {
        return $this->schedules->findByUser($userId);
    }

    /**
     * Sets when the user wants to hear about a search, or changes it. Changing
     * the time keeps what is still unseen: the files did not stop being new.
     *
     * @return array<string,mixed>
     * @throws DoesNotExistException when the search is not one the user has
     * @throws \InvalidArgumentException for a schedule that makes no sense
     * @throws \RuntimeException at {@see MAX_SCHEDULES}
     */
    public function set(string $userId, int $searchId, string $frequency, int $weekday, string $time): array
    {
        if (!in_array($frequency, SearchSchedule::FREQUENCIES, true)) {
            throw new \InvalidArgumentException('Unknown frequency');
        }
        if (preg_match(self::TIME_PATTERN, $time) !== 1) {
            throw new \InvalidArgumentException('A time is HH:MM');
        }
        if ($frequency === SearchSchedule::FREQUENCY_WEEKLY) {
            if ($weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException('A weekly schedule needs a day of the week');
            }
        } else {
            $weekday = 0;
        }

        $this->searchFor($userId, $searchId);
        $now = time();

        $schedule = $this->schedules->findFor($userId, $searchId);
        if ($schedule === null) {
            if ($this->schedules->countByUser($userId) >= self::MAX_SCHEDULES) {
                throw new \RuntimeException('max_schedules');
            }
            $schedule = new SearchSchedule();
            $schedule->setSearchId($searchId);
            $schedule->setUserId($userId);
            // The first run reports what changed after this moment.
            $schedule->setSeenAt($now);
            $schedule->setCreated($now);
        }
        $schedule->setFrequency($frequency);
        $schedule->setWeekday($weekday);
        $schedule->setTime($time);
        $schedule->setNextRun(self::nextRun($frequency, $weekday, $time, $this->zoneOf($userId), $now));

        if ($schedule->getId() === null) {
            try {
                $schedule = $this->schedules->insert($schedule);
            } catch (DbException $e) {
                // A double click got there first; its row is the one to change.
                if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }

                return $this->set($userId, $searchId, $frequency, $weekday, $time);
            }
        } else {
            $schedule = $this->schedules->update($schedule);
        }

        return $schedule->toArray();
    }

    public function remove(string $userId, int $searchId): void
    {
        $schedule = $this->schedules->findFor($userId, $searchId);
        if ($schedule !== null) {
            $this->drop($schedule);
        }
    }

    /**
     * The user opened the search: what was new is now seen, and the next run
     * counts from here.
     */
    public function markSeen(string $userId, int $searchId): void
    {
        $schedule = $this->schedules->findFor($userId, $searchId);
        if ($schedule === null) {
            return;
        }

        $hadUnseen = $schedule->getUnseen() > 0;
        $schedule->setSeenAt(time());
        $schedule->setUnseen(0);
        $this->schedules->update($schedule);
        if ($hadUnseen) {
            $this->notifications->withdraw($schedule->getId());
        }
    }

    /**
     * A scheduled search for the notification to describe, if it still has
     * something to say and its user still has the search.
     *
     * @return array{schedule: SearchSchedule, search: SavedSearch, zone: \DateTimeZone}|null
     */
    public function describable(string $userId, int $scheduleId): ?array
    {
        try {
            $schedule = $this->schedules->findById($scheduleId);
            if ($schedule->getUserId() !== $userId || $schedule->getUnseen() === 0) {
                return null;
            }

            return [
                'schedule' => $schedule,
                'search'   => $this->searchFor($userId, $schedule->getSearchId()),
                'zone'     => $this->zoneOf($userId),
            ];
        } catch (DoesNotExistException) {
            return null;
        }
    }

    // ---- Clean-up ----------------------------------------------------------

    /** The search is being deleted; every schedule on it goes with it. */
    public function forgetSearch(int $searchId): void
    {
        foreach ($this->schedules->findBySearch($searchId) as $schedule) {
            $this->drop($schedule);
        }
    }

    /**
     * An account is being deleted: its own schedules, and those others set on
     * the searches it owned.
     *
     * @param int[] $ownSearchIds
     */
    public function forgetUser(string $userId, array $ownSearchIds): void
    {
        foreach ($ownSearchIds as $searchId) {
            $this->forgetSearch($searchId);
        }
        $this->schedules->deleteByUser($userId);
    }

    // ---- The background job ------------------------------------------------

    /** @return int how many schedules ran */
    public function runDue(int $now): int
    {
        $due = $this->schedules->findDue($now, self::BATCH);
        foreach ($due as $schedule) {
            $this->run($schedule, $now);
        }

        return count($due);
    }

    public function run(SearchSchedule $schedule, int $now): void
    {
        $userId = $schedule->getUserId();
        $user = $this->userManager->get($userId);
        try {
            if ($user === null) {
                throw new DoesNotExistException('No such user');
            }
            $search = $this->searchFor($userId, $schedule->getSearchId());
        } catch (DoesNotExistException) {
            // Deleted, unshared, hidden, or the user left the group it came
            // through. Nothing to run, ever again.
            $this->drop($schedule);

            return;
        }

        $zone = $this->zoneOf($userId);
        // A disabled account keeps its schedule for the day it comes back,
        // but nothing is searched or said meanwhile.
        if ($user->isEnabled()) {
            try {
                $this->count($schedule, $search, $user, $zone, $now);
            } catch (\Throwable $e) {
                // One broken search must not stop the others in the batch, and
                // must not run again every quarter of an hour either.
                $this->logger->warning(AppConstants::LOG_PREFIX . ' A scheduled search failed', [
                    'exception' => $e,
                    'app'       => AppConstants::APP_ID,
                    'schedule'  => $schedule->getId(),
                ]);
            }
        }

        $schedule->setLastRun($now);
        $schedule->setNextRun(self::nextRun(
            $schedule->getFrequency(),
            $schedule->getWeekday(),
            $schedule->getTime(),
            $zone,
            $now,
        ));
        $this->schedules->update($schedule);
    }

    /**
     * Runs the search for what changed since the user last looked, and keeps
     * the count. A new notification only when the count moved: the same twelve
     * files found again tomorrow are not news.
     */
    private function count(SearchSchedule $schedule, SavedSearch $search, IUser $user, \DateTimeZone $zone, int $now): void
    {
        $stored = json_decode($search->getQuery(), true);
        $body = StoredQuery::toRequest(
            is_array($stored) ? $stored : [],
            $this->typeFilters->list(),
            (new \DateTimeImmutable('@' . $now))->setTimezone($zone),
        );
        $query = FileQuery::fromArray([
            ...$body,
            'changedSince' => $schedule->getSeenAt(),
            // Only counted, so the cheapest order there is; relevance would
            // collect the whole window first.
            'sort'         => 'mtime',
            'limit'        => self::COUNT_LIMIT,
            'offset'       => 0,
        ]);

        $previous = $this->userSession->getUser();
        // Some of what a search consults asks the session who is looking —
        // DoCoNEXT Core's access checks among them — and a background job has
        // nobody logged in. Without this a dossier scope reaches nothing.
        $this->userSession->setVolatileActiveUser($user);
        try {
            $page = $this->files->search($schedule->getUserId(), $query);
        } finally {
            $this->userSession->setVolatileActiveUser($previous);
        }

        if ($page['scopeUnavailable']) {
            // Not this user's to search today; it may be again. Nothing said.
            return;
        }

        $found = count($page['results']) + ($page['hasMore'] ? 1 : 0);
        if ($found === $schedule->getUnseen()) {
            return;
        }
        $schedule->setUnseen($found);
        if ($found === 0) {
            // Whatever was new has since gone — deleted, or moved out of reach.
            $this->notifications->withdraw($schedule->getId());
        } else {
            $this->notifications->changed($schedule->getUserId(), $schedule->getId());
        }
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * A saved search the user has: their own, or one shared with them that
     * they have not hidden.
     *
     * @throws DoesNotExistException
     */
    private function searchFor(string $userId, int $searchId): SavedSearch
    {
        try {
            $search = $this->searches->findOwned($searchId, $userId);
        } catch (DoesNotExistException $notOwn) {
            try {
                return $this->shares->visibleTo($userId, $searchId);
            } catch (DoesNotExistException) {
                throw $notOwn;
            }
        }
        if ($search->getKind() !== SavedSearch::KIND_SAVED) {
            // A recent is trimmed behind its owner's back.
            throw new DoesNotExistException('Only saved searches can be scheduled');
        }

        return $search;
    }

    private function drop(SearchSchedule $schedule): void
    {
        $this->schedules->delete($schedule);
        $this->notifications->withdraw($schedule->getId());
    }

    /**
     * The user's own clock — the zone their browser reported, which Nextcloud
     * keeps for them — or the server's default.
     */
    private function zoneOf(string $userId): \DateTimeZone
    {
        $zone = $this->userConfig->getValueString($userId, 'core', 'timezone', '');
        if ($zone === '') {
            $zone = $this->config->getSystemValueString('default_timezone', 'UTC');
        }
        try {
            return new \DateTimeZone($zone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * The first moment after `$after` that the schedule is due, in Unix seconds.
     *
     * Worked out on the user's own calendar, a day at a time: eight o'clock is
     * eight o'clock on the day after a change of daylight saving time too,
     * which adding 86400 seconds would not give.
     */
    public static function nextRun(string $frequency, int $weekday, string $time, \DateTimeZone $zone, int $after): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $today = (new \DateTimeImmutable('@' . $after))->setTimezone($zone)->setTime(0, 0);

        // Eight days reach the same weekday a week on, whatever today is.
        for ($day = 0; $day <= 7; $day++) {
            $candidate = $today->modify("+{$day} days")->setTime($hour, $minute);
            if ($candidate->getTimestamp() <= $after) {
                continue;
            }
            $isoDay = (int)$candidate->format('N');
            $due = match ($frequency) {
                SearchSchedule::FREQUENCY_WEEKDAYS => $isoDay <= 5,
                SearchSchedule::FREQUENCY_WEEKLY   => $isoDay === $weekday,
                default                            => true,
            };
            if ($due) {
                return $candidate->getTimestamp();
            }
        }

        // Unreachable for a valid schedule; a day on keeps a broken one quiet.
        return $after + 86400;
    }
}
