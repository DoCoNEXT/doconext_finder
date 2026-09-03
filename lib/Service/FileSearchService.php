<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\MetadataFields;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\IMetadataQuery;
use OCP\ITagManager;
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
        private ITagManager $tagManager,
        private IFilesMetadataManager $metadataManager,
        private MetadataFields $metadataFields,
    ) {
    }

    /**
     * Marks or unmarks a file as one of the user's favorites.
     *
     * Favorites are per-user tags, so this is already scoped to the caller — but
     * we still resolve the id through the user's own folder, so an id they cannot
     * see cannot be tagged.
     *
     * @throws NotFoundException when the id is not reachable by this user
     */
    public function setFavorite(string $uid, int $fileId, bool $favorite): void
    {
        $node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
        if ($node === null) {
            throw new NotFoundException('No such file: ' . $fileId);
        }

        $tags = $this->tagManager->load('files', [], false, $uid);
        $favorite
            ? $tags->addToFavorites($fileId)
            : $tags->removeFromFavorites($fileId);
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
            [$this->order($query)],
            $user,
        );

        $nodes = $userFolder->search($searchQuery);

        $hasMore = count($nodes) > $query->limit;
        if ($hasMore) {
            $nodes = array_slice($nodes, 0, $query->limit);
        }

        // One lookup for the page rather than one per row: the tag store returns
        // every favorited id for the user, and the page is at most a few hundred.
        $favorites = array_flip($this->tagManager->load('files', [], false, $uid)->getFavorites());

        // Likewise one metadata fetch for the whole page.
        $fileIds = array_map(static fn (Node $n) => $n->getId(), $nodes);
        $metadata = $fileIds === [] ? [] : $this->metadataManager->getMetadataForFiles($fileIds);

        return [
            'results' => array_map(
                fn (Node $n) => $this->toArray(
                    $n,
                    $userFolder->getPath(),
                    isset($favorites[$n->getId()]),
                    $this->metadataFields->values($n->getId(), $metadata),
                ),
                $nodes
            ),
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

        // Preset filters AND with everything, so "match any" on the advanced conditions
        // never widens the chosen type or date range.
        if ($query->mimetypes !== []) {
            $mimes = array_map(
                static fn (string $m) => new SearchComparison(
                    str_ends_with($m, '/%') ? ISearchComparison::COMPARE_LIKE : ISearchComparison::COMPARE_EQUAL,
                    'mimetype',
                    $m,
                ),
                $query->mimetypes,
            );
            $parts[] = count($mimes) === 1
                ? $mimes[0]
                : new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $mimes);
        }

        if ($query->modifiedAfter !== null) {
            $parts[] = new SearchComparison(
                ISearchComparison::COMPARE_GREATER_THAN,
                'mtime',
                $query->modifiedAfter,
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

    /**
     * Sorting on a metadata key needs the same EXTRA marker as filtering; an
     * unknown or unindexed key falls back to modification time rather than
     * failing the search over a column the user cannot see anyway.
     */
    private function order(FileQuery $query): SearchOrder
    {
        $direction = $query->descending ? ISearchOrder::DIRECTION_DESCENDING : ISearchOrder::DIRECTION_ASCENDING;

        if (MetadataFields::isMetadata($query->sort)) {
            $key = MetadataFields::key($query->sort);

            return $this->metadataFields->isFilterable($key)
                ? new SearchOrder($direction, $key, IMetadataQuery::EXTRA)
                : new SearchOrder($direction, 'mtime');
        }

        return new SearchOrder($direction, $query->sort);
    }

    private function toComparison(FileCondition $condition): ISearchOperator
    {
        if (MetadataFields::isMetadata($condition->field)) {
            $key = MetadataFields::key($condition->field);
            if (!$this->metadataFields->exists($key)) {
                throw new \InvalidArgumentException('unknown metadata field "' . $key . '"');
            }
            if (!$this->metadataFields->isFilterable($key)) {
                // Its value is stored but never indexed, so there is nothing to
                // compare against. Say that rather than returning an empty result.
                throw new \InvalidArgumentException(
                    '"' . $key . '" is not indexed on this server and cannot be filtered on'
                );
            }

            $comparison = new SearchComparison(
                $condition->comparison(),
                $key,
                $condition->value,
                IMetadataQuery::EXTRA,
            );

            return $condition->negate
                ? new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_NOT, [$comparison])
                : $comparison;
        }

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

    /**
     * @param array<string,string> $metadata
     * @return array<string,mixed>
     */
    private function toArray(Node $node, string $userFolderPath, bool $favorite, array $metadata): array
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
            'favorite'     => $favorite,
            'metadata'     => $metadata,
        ];
    }
}
