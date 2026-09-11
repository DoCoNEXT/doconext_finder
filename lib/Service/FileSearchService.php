<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileOwner;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\FileScope;
use OCA\DcnFinder\Search\MetadataFields;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\IMetadataQuery;
use OCP\ITagManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Runs structured file searches against the user's home folder — or against a
 * folder inside it, when the query carries a scope.
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
        private FileAuthorService $authors,
        private CoreScope $coreScope,
        private ContentSearchService $contentSearch,
        private IMountProviderCollection $mountProviders,
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
     * @return array{results: list<array<string,mixed>>, hasMore: bool, offset: int, limit: int, truncated: bool}
     * @throws NotFoundException when the user has no accessible home folder
     */
    public function search(string $uid, FileQuery $query): array
    {
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $user = $this->userManager->get($uid);

        // A scope changes where the search starts rather than what it matches, so
        // it never becomes a clause: the query is the same, the folder it runs
        // against is not.
        $searchRoots = [$userFolder];
        $truncated = false;

        if ($query->scope !== null) {
            [$roots, $truncated] = $this->scopeRoots($uid, $userFolder, $query->scope);

            // No reachable root — deleted, never provisioned, or simply not this
            // user's to see. That is an empty result, never the whole account.
            if ($roots === []) {
                return $this->emptyPage($query, $truncated);
            }

            $searchRoots = $roots;
        }

        // The content term is answered by the index rather than the filecache, so
        // it enters the query as the set of files that matched it: scope, type,
        // dates and metadata then filter that set exactly, the way they always
        // have. The other order — index first honouring our filters — is not
        // available: the files provider indexes content and basic attributes,
        // never this app's metadata, and the SQL platform honours no filters at
        // all beyond access rights.
        $ranked = null;
        if ($query->content !== '') {
            $ranked = $this->contentSearch->search($uid, $query->content);

            // Nothing in the index matched. With a term beside it that is simply
            // the empty half of an alternative — the name clause still has to
            // run. Alone, it is an empty result rather than an unfiltered one:
            // dropping the clause would silently turn "mentions X" into
            // "everything".
            if ($ranked['ids'] === [] && $query->term === '') {
                return $this->emptyPage($query, $truncated);
            }
        }

        $operator = $this->buildOperator($query, $ranked['ids'] ?? []);
        $order    = $this->order($query);

        if ($ranked !== null && $query->sort === FileQuery::SORT_RELEVANCE) {
            // Relevance is the index's order, not a filecache column, so the page
            // cannot be cut in SQL. Collecting everything first is safe precisely
            // because the fileid clause already bounds the query to one window.
            [$nodes, $hasMore] = $this->pageByRelevance($searchRoots, $operator, $order, $query, $user, $ranked['ranks']);
        } elseif (count($searchRoots) === 1) {
            // One extra row tells us whether another page exists without a count query.
            $nodes = $searchRoots[0]->search(
                $this->searchQuery($operator, $query->limit + 1, $query->offset, $order, $user)
            );

            $hasMore = count($nodes) > $query->limit;
            if ($hasMore) {
                $nodes = array_slice($nodes, 0, $query->limit);
            }
        } else {
            [$nodes, $hasMore] = $this->searchAcrossRoots($searchRoots, $operator, $order, $query, $user);
        }

        // One lookup for the page rather than one per row: the tag store returns
        // every favorited id for the user, and the page is at most a few hundred.
        $favorites = array_flip($this->tagManager->load('files', [], false, $uid)->getFavorites());

        // Likewise one metadata fetch for the whole page.
        $fileIds = array_map(static fn (Node $n) => $n->getId(), $nodes);
        $metadata = $fileIds === [] ? [] : $this->metadataManager->getMetadataForFiles($fileIds);

        // And one revision lookup: "modified by" is per file, but asking per row
        // would turn a page into hundreds of queries.
        $editors = $this->authors->lastEditors(array_values($fileIds));

        return [
            'results' => array_map(
                fn (Node $n) => $this->toArray(
                    $n,
                    $userFolder->getPath(),
                    isset($favorites[$n->getId()]),
                    $this->metadataFields->values($n->getId(), $metadata),
                    $editors[$n->getId()] ?? '',
                    $ranked['excerpts'][$n->getId()] ?? '',
                ),
                $nodes
            ),
            'hasMore' => $hasMore,
            'offset'  => $query->offset,
            'limit'   => $query->limit,
            // True when the scope had more roots than are worth querying, so the
            // page can say the results are partial instead of quietly lying.
            'truncated' => $truncated,
        ];
    }

    /**
     * The folders a scope resolves to, and whether Core had to cut the list short.
     *
     * Every id is resolved through the user's *own* home folder, so an id they
     * cannot reach simply drops out — the same defensive move {@see setFavorite}
     * makes, and the reason no second access check is needed anywhere else.
     *
     * @return array{0: list<Folder>, 1: bool}
     */
    private function scopeRoots(string $uid, Folder $userFolder, FileScope $scope): array
    {
        $resolved = $this->coreScope->roots($scope);

        $folders = [];
        foreach ($resolved['roots'] as $fileId) {
            $node = $userFolder->getFirstNodeById($fileId);
            if ($node instanceof Folder) {
                $folders[] = $node;
            }
        }

        return [$folders, $resolved['truncated']];
    }

    /**
     * Runs the same query against several roots and merges the answers.
     *
     * The tempting alternative — one query with `path LIKE '<root>/%'` OR-ed per
     * root — is quietly wrong, which is worth writing down because it looks
     * right. Group folders are mounted through a jailed cache: the *raw* path in
     * the filecache carries the jail root, while getInternalPath() reports the
     * path relative to it. A group folder's own root therefore reports an empty
     * internal path, and a folder inside one reports `Dossiers` where the row
     * stored `files/Dossiers`. Both produce a clause that silently matches
     * nothing — measured, not guessed. Searching from the node instead makes
     * Nextcloud add the correct jail filter itself.
     *
     * The cost is one query per root, so every root has to offer a whole page:
     * the page asked for may sit entirely inside any one of them.
     *
     * @param list<Folder> $roots
     * @return array{0: list<Node>, 1: bool} the page, and whether more follows
     */
    private function searchAcrossRoots(
        array $roots,
        ISearchOperator $operator,
        ISearchOrder $order,
        FileQuery $query,
        ?IUser $user,
    ): array {
        $reach = $query->offset + $query->limit + 1;

        $found = [];
        foreach ($roots as $root) {
            foreach ($root->search($this->searchQuery($operator, $reach, 0, $order, $user)) as $node) {
                // Nested roots overlap: a sub-dossier inside its parent's folder
                // is reached twice, and the same file must not be listed twice.
                $found[$node->getId()] = $node;
            }
        }

        $nodes = array_values($found);
        usort($nodes, $this->comparator($query));

        return [
            array_slice($nodes, $query->offset, $query->limit),
            count($nodes) > $query->offset + $query->limit,
        ];
    }

    /**
     * The one place this app instantiates \OC\Files\Search\SearchQuery.
     *
     * That class is core-private: there is no public builder for a search query,
     * only the interface {@see ISearchQuery} that Folder::search() accepts. Psalm
     * cannot see `OC\` at all — `nextcloud/ocp` ships only OCP — so every use is
     * a baselined UndefinedClass. Keeping it to a single site keeps that
     * accepted exception one line long instead of one per call.
     */
    private function searchQuery(
        ISearchOperator $operator,
        int $limit,
        int $offset,
        ISearchOrder $order,
        ?IUser $user,
    ): ISearchQuery {
        return new SearchQuery($operator, $limit, $offset, [$order], $user);
    }

    /**
     * Pages a content search by the index's own ranking.
     *
     * Every root is read whole rather than cut to a page, which is affordable
     * only because the operator already carries the fileid window: the query can
     * return at most {@see ContentSearchService::WINDOW} rows however wide the
     * scope is. Files the index matched but the filters rejected simply never
     * come back, so the page is exact within that window.
     *
     * @param list<Folder> $roots
     * @param array<int,int> $ranks position in the index's answer, by file id
     * @return array{0: list<Node>, 1: bool} the page, and whether more follows
     */
    private function pageByRelevance(
        array $roots,
        ISearchOperator $operator,
        ISearchOrder $order,
        FileQuery $query,
        ?IUser $user,
        array $ranks,
    ): array {
        $found = [];
        foreach ($roots as $root) {
            foreach ($root->search($this->searchQuery($operator, ContentSearchService::WINDOW, 0, $order, $user)) as $node) {
                // Nested roots overlap; the same file must not be listed twice.
                $found[$node->getId()] = $node;
            }
        }

        $nodes = array_values($found);

        // Rank 0 is the best match, so ascending rank is descending relevance —
        // which is what "descending" means to a user reading a sort control.
        $sign = $query->descending ? 1 : -1;
        $last = count($ranks);
        usort($nodes, static function (Node $a, Node $b) use ($ranks, $sign, $last): int {
            $byRank = $sign * (($ranks[$a->getId()] ?? $last) <=> ($ranks[$b->getId()] ?? $last));

            // The file id breaks ties so two pages of one result set cut at the
            // same place. Ranks are unique, so this only settles rows the index
            // never returned — which cannot happen while the fileid clause is on,
            // and costs nothing if it ever does.
            return $byRank !== 0 ? $byRank : ($a->getId() <=> $b->getId());
        });

        return [
            array_slice($nodes, $query->offset, $query->limit),
            count($nodes) > $query->offset + $query->limit,
        ];
    }

    /**
     * Orders the merged rows.
     *
     * Deliberately the same comparison the database made, not a nicer one: each
     * root was cut to its first N rows *by the database's ordering*, so a merge
     * that ordered differently would promote rows past the cut and drop rows
     * that belonged. Sorting `name` case-insensitively here did exactly that —
     * a file could appear on two consecutive pages, which is how this was found.
     * Nextcloud's own cross-mount merge in Folder::search() compares the same
     * plain way, for the same reason.
     *
     * The file id breaks ties so that two pages of the same result set cut at
     * the same place; without it, equally-named files reshuffle between them.
     *
     * Sorting on a metadata key falls back to modification time: those values
     * are fetched per page, after the merge, so there is nothing to sort on yet.
     * {@see order()} makes the same substitution for unindexed keys.
     */
    private function comparator(FileQuery $query): callable
    {
        $direction = $query->descending ? -1 : 1;

        $key = match ($query->sort) {
            'name'          => static fn (Node $n) => $n->getName(),
            'size'          => static fn (Node $n) => $n->getSize(),
            'creation_time' => static fn (Node $n) => $n->getCreationTime(),
            default         => static fn (Node $n) => $n->getMTime(),
        };

        return static fn (Node $a, Node $b) => ($direction * ($key($a) <=> $key($b)))
            ?: ($a->getId() <=> $b->getId());
    }

    /**
     * @return array{results: list<array<string,mixed>>, hasMore: bool, offset: int, limit: int, truncated: bool}
     */
    private function emptyPage(FileQuery $query, bool $truncated): array
    {
        return [
            'results'   => [],
            'hasMore'   => false,
            'offset'    => $query->offset,
            'limit'     => $query->limit,
            'truncated' => $truncated,
        ];
    }

    /**
     * (term OR content) AND (conditions joined by and/or). Every part is
     * optional, but FileQuery guarantees at least one is present.
     *
     * @param list<int> $contentIds file ids the full-text index matched, if any
     */
    private function buildOperator(FileQuery $query, array $contentIds = []): ISearchOperator
    {
        $parts = [];

        // The two ways of matching what was typed are alternatives: looking
        // inside the files widens the search rather than narrowing it. ANDing
        // them asks for a file whose *name* says "invoice" and whose *text* also
        // says it, which is almost none of them.
        $text = [];

        if ($query->term !== '') {
            $text[] = new SearchComparison(
                ISearchComparison::COMPARE_LIKE,
                'name',
                '%' . addcslashes($query->term, '%_\\') . '%',
            );
        }

        // `fileid` is one of the filecache columns SearchBuilder exposes, and the
        // only one that accepts `in` — which is what lets the index's answer be
        // an ordinary clause here instead of a second pass in PHP.
        if ($contentIds !== []) {
            $text[] = new SearchComparison(ISearchComparison::COMPARE_IN, 'fileid', $contentIds);
        }

        if (count($text) === 1) {
            $parts[] = $text[0];
        } elseif (count($text) > 1) {
            $parts[] = new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $text);
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
    private function order(FileQuery $query): ISearchOrder
    {
        $direction = $query->descending ? ISearchOrder::DIRECTION_DESCENDING : ISearchOrder::DIRECTION_ASCENDING;

        // Relevance has no column to sort on; the rows are reordered by score
        // after they come back. The database still needs a valid, stable order
        // to cut its own window by.
        if ($query->sort === FileQuery::SORT_RELEVANCE) {
            return new SearchOrder($direction, 'mtime');
        }

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

        // "Created by" is asked as a storage, never as core's own `owner` field —
        // see homeStorageId() for why.
        [$type, $field, $value] = $condition->field === 'owner'
            ? [ISearchComparison::COMPARE_EQUAL, 'storage', $this->homeStorageId((string)$condition->value)]
            : [$condition->comparison(), $condition->field, $condition->value];

        $comparison = new SearchComparison($type, $field, $value);

        // SearchBuilder only supports a comparison directly inside "not" — never a
        // nested binary operator — so negation is applied per condition, not per group.
        return $condition->negate
            ? new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_NOT, [$comparison])
            : $comparison;
    }

    /**
     * The storage that answers "Created by <uid>": that user's home.
     *
     * Core's own `owner` field looks like the answer and is not. SearchBuilder
     * turns it into `uid_owner`, a column of the *share* table, and joins that
     * table in on `fileid = file_source`. So it matches only files the person has
     * shared — alice's 13 files answered 2 — and returns each file once per share:
     * a Deck attachment, shared once for every card it hangs on, came back nine
     * times, and since selection follows the file id, clicking one row lit up all
     * nine.
     *
     * The storage column needs no join, and it is what the Created by column
     * already reports: a home mount's owner is its user, and a share of a file in
     * that home reports the same user. Through a share it still matches, because
     * the row keeps the storage it lives on. What it does not reach is a file
     * someone shared out of a team folder — which the column only names for the
     * recipients anyway.
     */
    private function homeStorageId(string $uid): int
    {
        $user = $this->userManager->get($uid);

        // Storage ids start at 1: an unknown person matches nothing rather than
        // dropping the clause, which would widen the search to everyone.
        return $user === null
            ? -1
            : (int)$this->mountProviders->getHomeMountForUser($user)->getNumericStorageId();
    }

    /**
     * @param array<string,string> $metadata
     * @param string $editor uid that wrote the current revision, '' when unknown
     * @return array<string,mixed>
     */
    private function toArray(
        Node $node,
        string $userFolderPath,
        bool $favorite,
        array $metadata,
        string $editor,
        string $excerpt = '',
    ): array {
        $path = $node->getPath();
        $relative = str_starts_with($path, $userFolderPath)
            ? ltrim(substr($path, strlen($userFolderPath)), '/')
            : ltrim($path, '/');

        // The owner is the closest thing Nextcloud keeps to a creator; with no
        // recorded editor the owner is also the only person known to have
        // written the file. See FileAuthorService for why.
        //
        // Only ask storages that keep one, though: the rest answer with whoever
        // is asking, which would put the reader's own name in every row of a
        // team folder. See FileOwner.
        $owner = FileOwner::of(
            $node->getMountPoint()->getMountType(),
            $node->getOwner()?->getUID() ?? '',
        );

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
            'owner'        => $owner,
            'createdBy'    => $this->authors->displayName($owner),
            'modifiedBy'   => $this->authors->displayName($editor !== '' ? $editor : $owner),
            'metadata'     => $metadata,
            // The passage the content term matched, when there was one. Empty on
            // every other search, and on a file the index matched somewhere it
            // could not quote — an excerpt is an explanation, not a promise.
            'excerpt'      => $excerpt,
        ];
    }
}
