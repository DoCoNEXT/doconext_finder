<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileQuery;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\IUserManager;

/**
 * Runs structured file searches against the user's home folder.
 *
 * This talks to \OCP\Files\Folder::search() — the same engine the WebDAV DASL
 * backend drives, but reached directly. That matters: DASL only exposes the
 * properties \OCA\DAV\Files\FileSearchBackend chooses to declare, whereas the
 * operator tree accepts every filecache column in SearchBuilder::$fieldTypes
 * (path, favorite and tagname among them) and arbitrary and/or/not nesting.
 *
 * Known gap: Folder::search() returns a page of nodes and no total count, so
 * paging uses the "ask for one extra row" trick. A true total is reachable
 * server-side with a dedicated count query against the filecache, but that
 * needs internals below the public Files API — deliberately not done here.
 */
class FileSearchService
{
    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
    ) {
    }

    /**
     * @return array{results: list<array<string,mixed>>, hasMore: bool, offset: int, limit: int}
     * @throws NotFoundException when the user has no accessible home folder
     */
    public function search(string $uid, FileQuery $query): array
    {
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $user = $this->userManager->get($uid);

        // One extra row tells us whether another page exists without a count query.
        $searchQuery = new SearchQuery(
            $this->buildOperator($query),
            $query->limit + 1,
            $query->offset,
            [new SearchOrder(
                $query->descending ? ISearchOrder::DIRECTION_DESCENDING : ISearchOrder::DIRECTION_ASCENDING,
                $query->sort,
            )],
            $user,
        );

        $nodes = $userFolder->search($searchQuery);

        $hasMore = count($nodes) > $query->limit;
        if ($hasMore) {
            $nodes = array_slice($nodes, 0, $query->limit);
        }

        return [
            'results' => array_map(fn (Node $n) => $this->toArray($n, $userFolder->getPath()), $nodes),
            'hasMore' => $hasMore,
            'offset'  => $query->offset,
            'limit'   => $query->limit,
        ];
    }

    /**
     * Term AND (conditions joined by and/or). Both halves are optional, but
     * FileQuery guarantees at least one is present.
     */
    private function buildOperator(FileQuery $query): ISearchOperator
    {
        $parts = [];

        if ($query->term !== '') {
            $parts[] = new SearchComparison(
                ISearchComparison::COMPARE_LIKE,
                'name',
                '%' . addcslashes($query->term, '%_\\') . '%',
            );
        }

        $conditions = array_map(fn (FileCondition $c) => $this->toComparison($c), $query->conditions);

        if (count($conditions) === 1) {
            $parts[] = $conditions[0];
        } elseif (count($conditions) > 1) {
            $parts[] = new SearchBinaryOperator(
                $query->matchAny ? ISearchBinaryOperator::OPERATOR_OR : ISearchBinaryOperator::OPERATOR_AND,
                $conditions,
            );
        }

        return count($parts) === 1
            ? $parts[0]
            : new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_AND, $parts);
    }

    private function toComparison(FileCondition $condition): ISearchOperator
    {
        $comparison = new SearchComparison(
            $condition->comparison(),
            $condition->field,
            $condition->value,
        );

        // SearchBuilder only supports a comparison directly inside "not" — never a
        // nested binary operator — so negation is applied per condition, not per group.
        return $condition->negate
            ? new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_NOT, [$comparison])
            : $comparison;
    }

    /** @return array<string,mixed> */
    private function toArray(Node $node, string $userFolderPath): array
    {
        $path = $node->getPath();
        $relative = str_starts_with($path, $userFolderPath)
            ? ltrim(substr($path, strlen($userFolderPath)), '/')
            : ltrim($path, '/');

        return [
            'fileid'       => $node->getId(),
            'name'         => $node->getName(),
            'path'         => $relative,
            'mimetype'     => $node->getMimetype(),
            'isFolder'     => $node->getType() === FileInfo::TYPE_FOLDER,
            'size'         => $node->getSize(),
            'mtime'        => $node->getMTime(),
            'creationTime' => $node->getCreationTime(),
            'permissions'  => $node->getPermissions(),
        ];
    }
}
