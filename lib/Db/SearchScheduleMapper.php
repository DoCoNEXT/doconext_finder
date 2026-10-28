<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SearchSchedule>
 * @psalm-suppress UnusedClass
 */
class SearchScheduleMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, DbConstants::DB_TABLENAME_SCHEDULES, SearchSchedule::class);
    }

    public function findFor(string $userId, int $searchId): ?SearchSchedule
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb)[0] ?? null;
    }

    /** @throws \OCP\AppFramework\Db\DoesNotExistException */
    public function findById(int $id): SearchSchedule
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /** @return array<int, SearchSchedule> by search id */
    public function findByUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $bySearch = [];
        foreach ($this->findEntities($qb) as $schedule) {
            $bySearch[$schedule->getSearchId()] = $schedule;
        }

        return $bySearch;
    }

    public function countByUser(string $userId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'total'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $total = (int)$result->fetchOne();
        $result->closeCursor();

        return $total;
    }

    /**
     * The schedules whose moment has come, longest overdue first.
     *
     * @return SearchSchedule[]
     */
    public function findDue(int $now, int $limit): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->lte('next_run', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->orderBy('next_run', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /** @return SearchSchedule[] */
    public function findBySearch(int $searchId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    public function deleteByUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
