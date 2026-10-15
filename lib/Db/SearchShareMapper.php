<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SearchShare>
 * @psalm-suppress UnusedClass
 */
class SearchShareMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, DbConstants::DB_TABLENAME_SHARES, SearchShare::class);
    }

    /** @return SearchShare[] oldest first, the order they were added in */
    public function findBySearch(int $searchId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    /** @throws \OCP\AppFramework\Db\DoesNotExistException */
    public function findOfSearch(int $id, int $searchId): SearchShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    public function findRecipient(int $searchId, string $type, string $with): ?SearchShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($with)));

        return $this->findEntities($qb)[0] ?? null;
    }

    /**
     * Every share that reaches someone with these memberships, optionally for
     * one search only.
     *
     * @param array<string, list<string>> $memberships share type => ids, as
     *        {@see \OCA\DcnFinder\Service\ShareRecipients::membershipsOf()} gives them
     * @return SearchShare[]
     */
    public function findReaching(array $memberships, ?int $searchId = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $either = [];
        foreach ($memberships as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            $either[] = $qb->expr()->andX(
                $qb->expr()->eq('share_type', $qb->createNamedParameter($type)),
                $qb->expr()->in('share_with', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)),
            );
        }
        if ($either === []) {
            return [];
        }

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->orX(...$either));
        if ($searchId !== null) {
            $qb->andWhere($qb->expr()->eq('search_id', $qb->createNamedParameter($searchId, IQueryBuilder::PARAM_INT)));
        }

        return $this->findEntities($qb);
    }

    /**
     * Which of these searches are shared with anyone at all.
     *
     * @param int[] $searchIds
     * @return int[]
     */
    public function sharedAmong(array $searchIds): array
    {
        if ($searchIds === []) {
            return [];
        }
        $shared = [];
        // Chunked: some databases cap the number of values an IN() may hold.
        foreach (array_chunk($searchIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('search_id')
                ->from($this->getTableName())
                ->where($qb->expr()->in('search_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            array_push($shared, ...array_map('intval', $result->fetchFirstColumn()));
            $result->closeCursor();
        }

        return $shared;
    }

    /** @return int[] the ids of the shares removed */
    public function deleteBySearch(int $searchId): array
    {
        $ids = array_map(static fn (SearchShare $s) => $s->getId(), $this->findBySearch($searchId));
        $this->deleteIds($ids);

        return $ids;
    }

    /** @return int[] the ids of the shares removed */
    public function deleteByRecipient(string $type, string $with): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('share_with', $qb->createNamedParameter($with)));
        $result = $qb->executeQuery();
        $ids = array_map('intval', $result->fetchFirstColumn());
        $result->closeCursor();

        $this->deleteIds($ids);

        return $ids;
    }

    /** @param int[] $ids */
    private function deleteIds(array $ids): void
    {
        foreach (array_chunk($ids, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
    }
}
