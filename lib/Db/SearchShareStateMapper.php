<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SearchShareState>
 * @psalm-suppress UnusedClass
 */
class SearchShareStateMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, DbConstants::DB_TABLENAME_SHARE_STATE, SearchShareState::class);
    }

    /**
     * @param int[] $shareIds
     * @return array<int, SearchShareState> by share id
     */
    public function findForUser(string $userId, array $shareIds): array
    {
        $states = [];
        foreach (array_chunk($shareIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            foreach ($this->findEntities($qb) as $state) {
                $states[$state->getShareId()] = $state;
            }
        }

        return $states;
    }

    /** Brings a share back into every list it was hidden from. */
    public function unhide(int $shareId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('hidden', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /** @param int[] $shareIds */
    public function deleteByShares(array $shareIds): void
    {
        foreach (array_chunk($shareIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->getTableName())
                ->where($qb->expr()->in('share_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $qb->executeStatement();
        }
    }

    public function deleteByUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
