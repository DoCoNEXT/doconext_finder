<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Who created and who last changed a file.
 *
 * Nextcloud's filecache has no "author" and no "last editor" column, so both
 * answers are approximations — deliberately chosen ones:
 *
 * - **Created by** is the file's owner (`uid_owner` on its storage). That is the
 *   only creator-like identity Nextcloud keeps, and it is what the Files app
 *   shows as "Owner". In a group folder every file is owned by the folder, so it
 *   says less there — but it never lies about what it is.
 * - **Modified by** is the author of the current revision, recorded by the
 *   Versions app: its VersionAuthorListener writes `{"author": uid}` onto the
 *   revision it creates on every write, and the newest revision row therefore
 *   describes the content on disk now. Without that app — or for a file nobody
 *   has rewritten since it was uploaded — there is nothing to read and the owner
 *   is the honest answer.
 *
 * Both are resolved for a whole result page at once: one query for the
 * revisions, one user lookup per distinct uid. Per-row lookups would turn a
 * 100-row page into a few hundred queries.
 */
class FileAuthorService
{
    private const VERSIONS_APP_ID = 'files_versions';

    /**
     * Where revisions are recorded. Team folders keep their own table, with the
     * same columns; reading only the first left Modified by empty for every file
     * in a team folder.
     */
    private const VERSION_TABLES = ['files_versions', 'group_folders_versions'];

    /** @var array<string,string> uid → display name, for the lifetime of the request. */
    private array $displayNames = [];

    public function __construct(
        private IUserManager $userManager,
        private IAppManager $appManager,
        private IDBConnection $db,
    ) {
    }

    /**
     * A user's display name, falling back to the uid for accounts that are gone
     * — a deleted owner should read as an id, not as a blank cell.
     */
    public function displayName(string $uid): string
    {
        if ($uid === '') {
            return '';
        }

        return $this->displayNames[$uid] ??= ($this->userManager->get($uid)?->getDisplayName() ?? $uid);
    }

    /**
     * The uid that wrote each file's current content, for the ids it knows.
     *
     * Reads the revision tables directly rather than through the Versions app's
     * mapper: the mapper answers one file at a time, and Finder must keep working
     * with the app disabled — which a class dependency would prevent.
     *
     * @param list<int> $fileIds
     * @return array<int,string> fileId → uid, absent where nothing is recorded
     */
    public function lastEditors(array $fileIds): array
    {
        if ($fileIds === [] || !$this->appManager->isEnabledForUser(self::VERSIONS_APP_ID)) {
            return [];
        }

        $editors = [];
        $newest = [];

        foreach (self::VERSION_TABLES as $table) {
            try {
                // Chunked because a page size is the user's to choose and some
                // databases cap the number of bound parameters.
                foreach (array_chunk($fileIds, 500) as $chunk) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('file_id', 'timestamp', 'metadata')
                        ->from($table)
                        ->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));

                    $result = $qb->executeQuery();
                    while ($row = $result->fetch()) {
                        $fileId = (int)$row['file_id'];
                        $timestamp = (int)$row['timestamp'];
                        // Only the newest revision describes the content on disk now.
                        if (isset($newest[$fileId]) && $newest[$fileId] >= $timestamp) {
                            continue;
                        }
                        $metadata = json_decode((string)($row['metadata'] ?? ''), true);
                        $author = is_array($metadata) ? (string)($metadata['author'] ?? '') : '';
                        $newest[$fileId] = $timestamp;
                        if ($author !== '') {
                            $editors[$fileId] = $author;
                        } else {
                            unset($editors[$fileId]);
                        }
                    }
                    $result->closeCursor();
                }
            } catch (\Throwable) {
                // A missing or changed table — no team folders app, say — costs
                // its own files the column, never the other table's or the search.
                continue;
            }
        }

        return $editors;
    }
}
