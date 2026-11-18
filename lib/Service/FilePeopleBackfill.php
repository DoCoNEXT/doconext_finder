<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\IDBConnection;

/**
 * Fills "Modified by" and "Created by" for the files from before Finder kept track.
 *
 * Gives each file the names its columns show. The editor is the author of its
 * newest revision. The maker is the author of its oldest revision still kept:
 * old revisions get cleaned up, so for a file edited early on that is an
 * estimate, but no better record exists. A later revision is never used for the
 * maker, because whoever wrote that may only have edited. What is left in a
 * personal folder falls to its owner.
 *
 * Works through one batch at a time and remembers where it stopped, so an
 * instance of any size is done in small steps. It writes to the database for
 * every file, so it only runs once an admin has started it, and it can be paused.
 */
class FilePeopleBackfill
{
    private const BATCH = 500;

    /** How often a run from the command line says how far it has got. */
    private const REPORT_EVERY_SECONDS = 30;

    private const CONFIG_STEP = 'file_people_backfill_step';
    private const CONFIG_AFTER = 'file_people_backfill_after';
    private const CONFIG_RUNNING = 'file_people_backfill_running';

    /** Read in this order, then the owners; first for editors, then for makers. */
    private const VERSION_TABLES = ['files_versions', 'group_folders_versions'];

    /** @var array<int, string>|null storage id => the account whose home it is */
    private ?array $homes = null;

    public function __construct(
        private IDBConnection $db,
        private IAppConfig $appConfig,
        private IMimeTypeLoader $mimeTypes,
        private FileAuthorService $authors,
        private FilePeopleIndex $index,
    ) {
    }

    public function start(): void
    {
        $this->appConfig->setValueBool(AppConstants::APP_ID, self::CONFIG_RUNNING, true, lazy: true);
    }

    public function pause(): void
    {
        $this->appConfig->setValueBool(AppConstants::APP_ID, self::CONFIG_RUNNING, false, lazy: true);
    }

    public function isComplete(): bool
    {
        return $this->step() >= self::stepCount();
    }

    public function isRunning(): bool
    {
        return !$this->isComplete()
            && $this->appConfig->getValueBool(AppConstants::APP_ID, self::CONFIG_RUNNING, false, lazy: true);
    }

    /**
     * `queued` is started with nothing done yet: the work only begins when
     * Nextcloud next runs its background jobs, which can take a while.
     *
     * @return array{state: 'idle'|'queued'|'running'|'paused'|'done', percent: int}
     */
    public function status(): array
    {
        if ($this->isComplete()) {
            return ['state' => 'done', 'percent' => 100];
        }

        $step = $this->step();
        $after = $this->after();
        $untouched = $step === 0 && $after === 0;
        $state = match (true) {
            $this->isRunning() => $untouched ? 'queued' : 'running',
            $untouched => 'idle',
            default => 'paused',
        };

        return ['state' => $state, 'percent' => self::percent($step, $after, $this->sourceSizes())];
    }

    /**
     * How far along a position is, each source weighing as much as it holds. Not
     * 100 before it is finished: the last step can still have work to do.
     *
     * @param list<int> $sizes the highest id in each source of one pass
     */
    public static function percent(int $step, int $after, array $sizes): int
    {
        $perPass = count($sizes);
        $total = 2 * array_sum($sizes);
        if ($total === 0) {
            return 0;
        }

        $done = 0;
        for ($i = 0; $i < $step; $i++) {
            $done += $sizes[$i % $perPass];
        }
        $done += min($after, $sizes[$step % $perPass]);

        return min(99, (int)floor(100 * $done / $total));
    }

    /**
     * Handles the next batch.
     *
     * @return bool whether any work is left
     */
    public function advance(): bool
    {
        $step = $this->step();
        $after = $this->after();

        $perPass = count(self::VERSION_TABLES) + 1;
        if ($step >= self::stepCount()) {
            return false;
        }

        $creators = $step >= $perPass;
        $source = $step % $perPass;
        $last = $source < count(self::VERSION_TABLES)
            ? $this->fromRevisions(self::VERSION_TABLES[$source], $after, $creators)
            : $this->fromOwners($after, $creators);

        if ($last === null) {
            ++$step;
            $last = 0;
        }

        $this->appConfig->setValueInt(AppConstants::APP_ID, self::CONFIG_STEP, $step, lazy: true);
        $this->appConfig->setValueInt(AppConstants::APP_ID, self::CONFIG_AFTER, $last, lazy: true);

        return $step < self::stepCount();
    }

    /** @return int<0, max> */
    private function step(): int
    {
        return max(0, $this->appConfig->getValueInt(AppConstants::APP_ID, self::CONFIG_STEP, 0, lazy: true));
    }

    private function after(): int
    {
        return $this->appConfig->getValueInt(AppConstants::APP_ID, self::CONFIG_AFTER, 0, lazy: true);
    }

    /** Two passes, editors and makers, over the revision tables and the owners. */
    private static function stepCount(): int
    {
        return 2 * (count(self::VERSION_TABLES) + 1);
    }

    /** @return list<int> the highest id in each source of one pass */
    private function sourceSizes(): array
    {
        $sizes = array_map(fn (string $table): int => $this->highestId($table, 'file_id'), self::VERSION_TABLES);
        $sizes[] = $this->highestId('filecache', 'fileid');

        return $sizes;
    }

    private function highestId(string $table, string $column): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($column)->from($table)->orderBy($column, 'DESC')->setMaxResults(1);
            if ($table === 'filecache') {
                $qb->runAcrossAllShards();
            }

            return $this->ids($qb)[0] ?? 0;
        } catch (\Throwable) {
            // No team folders app, so no such table. Progress is only a figure.
            return 0;
        }
    }

    /**
     * Works without pause until done or out of time, for the occ command.
     *
     * The background job is held off meanwhile, so the two do not take the same
     * batches, and resumes afterwards if it was running. A killed run leaves it
     * paused, which the settings page shows and can continue.
     *
     * @param int|null $maxSeconds null for no limit
     * @param callable(): void $onProgress called now and then
     * @return bool whether everything is done
     */
    public function runInForeground(?int $maxSeconds, callable $onProgress): bool
    {
        $jobWasRunning = $this->isRunning();
        $this->pause();

        $started = time();
        $reported = $started;
        while (($maxSeconds === null || time() - $started < $maxSeconds) && $this->advance()) {
            if (time() - $reported >= self::REPORT_EVERY_SECONDS) {
                $reported = time();
                $onProgress();
            }
        }

        $finished = $this->isComplete();
        if ($jobWasRunning && !$finished) {
            $this->start();
        }

        return $finished;
    }

    /** Everything in a copied folder was made, and last written, by whoever copied it. */
    public function copiedFolder(int $folderId, string $uid): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('storage', 'path')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
        $qb->runAcrossAllShards();
        $result = $qb->executeQuery();
        $folder = $result->fetch();
        $result->closeCursor();
        if ($folder === false) {
            return;
        }

        $mimeFolder = $this->mimeTypes->getId(FileInfo::MIMETYPE_FOLDER);
        $after = 0;
        do {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid')
                ->from('filecache')
                ->where($qb->expr()->eq('storage', $qb->createNamedParameter((int)$folder['storage'], IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter((string)$folder['path']) . '/%')))
                ->andWhere($qb->expr()->neq('mimetype', $qb->createNamedParameter($mimeFolder, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->gt('fileid', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
                ->orderBy('fileid', 'ASC')
                ->setMaxResults(self::BATCH);
            $ids = $this->ids($qb);

            $people = array_fill_keys($ids, $uid);
            $this->index->recordMissingCreators($people);
            $this->index->recordMissingEditors($people);
            $after = $ids === [] ? $after : $ids[count($ids) - 1];
        } while (count($ids) === self::BATCH);
    }

    /** @return int|null the last file id handled, null when the table is done */
    private function fromRevisions(string $table, int $after, bool $creators): ?int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('file_id')
                ->from($table)
                ->where($qb->expr()->gt('file_id', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
                ->orderBy('file_id', 'ASC')
                ->setMaxResults(self::BATCH);
            $ids = $this->ids($qb);
        } catch (\OCP\DB\Exception) {
            // No team folders app, so no such table.
            return null;
        }

        if ($ids === []) {
            return null;
        }

        // A revision can outlive its file, and metadata for a file that is gone
        // would never be cleaned up.
        $existing = $this->existing($ids);
        if ($creators) {
            $this->index->recordMissingCreators($this->authors->firstAuthors($existing));
        } else {
            // Where the newest revision names nobody the column shows the maker,
            // so that is who this filter has to find.
            $this->index->recordMissingEditors($this->authors->lastEditors($existing) + $this->authors->firstAuthors($existing));
        }

        return $ids[count($ids) - 1];
    }

    /** @return int|null the last file id looked at, null when none are left */
    private function fromOwners(int $after, bool $creators): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'storage', 'path', 'mimetype')
            ->from('filecache')
            ->where($qb->expr()->gt('fileid', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
            ->orderBy('fileid', 'ASC')
            ->setMaxResults(self::BATCH);
        $qb->runAcrossAllShards();
        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }
        $result->closeCursor();

        if ($rows === []) {
            return null;
        }

        $homes = $this->homes();
        $folder = $this->mimeTypes->getId(FileInfo::MIMETYPE_FOLDER);
        $owners = [];
        $last = $after;
        foreach ($rows as $row) {
            $last = (int)$row['fileid'];
            $owner = $homes[(int)$row['storage']] ?? null;
            if ($owner !== null && (int)$row['mimetype'] !== $folder && str_starts_with((string)$row['path'], 'files/')) {
                $owners[$last] = $owner;
            }
        }
        if ($creators) {
            $this->index->recordMissingCreators($owners);
        } else {
            $this->index->recordMissingEditors($owners);
        }

        return $last;
    }

    /**
     * @param list<int> $ids
     * @return list<int> those that are still files
     */
    private function existing(array $ids): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
            ->from('filecache')
            ->where($qb->expr()->in('fileid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $qb->runAcrossAllShards();

        return $this->ids($qb);
    }

    /** @return list<int> the query's first column */
    private function ids(IQueryBuilder $qb): array
    {
        $result = $qb->executeQuery();
        $ids = [];
        while (($id = $result->fetchOne()) !== false) {
            $ids[] = (int)$id;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Which storage is whose home: the one mounted at `/<account>/`. Read from
     * the mount table because a storage's own id is hashed for a long account id.
     *
     * @return array<int, string>
     */
    private function homes(): array
    {
        if ($this->homes !== null) {
            return $this->homes;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('storage_id', 'user_id', 'mount_point')->from('mounts');
        $result = $qb->executeQuery();

        $this->homes = [];
        while ($row = $result->fetch()) {
            if ($row['mount_point'] === '/' . $row['user_id'] . '/') {
                $this->homes[(int)$row['storage_id']] = (string)$row['user_id'];
            }
        }
        $result->closeCursor();

        return $this->homes;
    }
}
