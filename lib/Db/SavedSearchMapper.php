<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SavedSearch>
 * @psalm-suppress UnusedClass
 */
class SavedSearchMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'dcn_finder_searches', SavedSearch::class);
    }

    /**
     * @return SavedSearch[] newest first
     */
    public function findByKind(string $userId, string $kind, int $limit = 100): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
            ->orderBy('last_run', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /** The one entry with this exact query, if the user already has it. */
    public function findByFingerprint(string $userId, string $kind, string $fingerprint): ?SavedSearch
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
            ->andWhere($qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);

        return $rows[0] ?? null;
    }

    /** @throws \OCP\AppFramework\Db\DoesNotExistException */
    public function findOwned(int $id, string $userId): SavedSearch
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Trims the user's recents to the newest $keep, so the list cannot grow
     * without bound. Saved searches are never trimmed — the user chose those.
     */
    public function trimRecents(string $userId, int $keep): void
    {
        $recents = $this->findByKind($userId, SavedSearch::KIND_RECENT, $keep + 100);
        foreach (array_slice($recents, $keep) as $stale) {
            $this->delete($stale);
        }
    }
}
