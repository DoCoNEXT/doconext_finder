<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Search\CoreSearch;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileOwner;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\FileScope;
use OCA\DcnFinder\Search\MetadataFields;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
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
        $orders   = $this->order($query);

        if ($ranked !== null && $query->sort === FileQuery::SORT_RELEVANCE) {
            // Relevance is the index's order, not a filecache column, so the page
            // cannot be cut in SQL. Collecting everything first is safe precisely
            // because the fileid clause already bounds the query to one window.
            [$nodes, $hasMore] = $this->pageByRelevance($searchRoots, $operator, $orders, $query, $user, $ranked['ranks']);
        } elseif (count($searchRoots) === 1) {
            // One extra row tells us whether another page exists without a count query.
            $rooted = $this->withoutRoot($operator, $searchRoots[0]);
            $nodes = $searchRoots[0]->search(
                CoreSearch::query($rooted, $query->limit + 1, $query->offset, $orders, $user)
            );

            $hasMore = count($nodes) > $query->limit;
            if ($hasMore) {
                $nodes = array_slice($nodes, 0, $query->limit);
            } elseif (count($nodes) === $query->limit) {
                // The extra row was asked for and did not arrive, which is
                // what the last page looks like — but also what a page looks
                // like when Folder::search dropped a row after the database had
                // already counted it, and {@see withoutRoot} only settles the
                // one such row we know by name. A page that is exactly full is
                // therefore worth one more question rather than a guess that
                // hides everything after it.
                $hasMore = $searchRoots[0]->search(
                    CoreSearch::query($rooted, 1, $query->offset + $query->limit, $orders, $user)
                ) !== [];
            }
        } else {
            [$nodes, $hasMore] = $this->searchAcrossRoots($searchRoots, $operator, $orders, $query, $user);
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
        array $orders,
        FileQuery $query,
        ?IUser $user,
    ): array {
        $reach = $query->offset + $query->limit + 1;

        $found = [];
        foreach ($roots as $root) {
            $rooted = $this->withoutRoot($operator, $root);
            foreach ($root->search(CoreSearch::query($rooted, $reach, 0, $orders, $user)) as $node) {
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
     * The same query, with the folder being searched kept out of it.
     *
     * Folder::search() removes that folder from its answer — you asked what is
     * *in* it — but only after the database has applied the limit. The page then
     * comes back one row short of what was asked for, which reads as "no more
     * rows", and the next page starts one row before this one ended, so a file
     * appears on both. Measured: the first page said "end" with four pages still
     * to come, and one file came back twice.
     *
     * Excluding it up front makes the rows the database counts the rows the
     * caller gets. A single comparison inside "not" is all SearchBuilder takes,
     * and that is exactly what this is.
     */
    private function withoutRoot(ISearchOperator $operator, Folder $root): ISearchOperator
    {
        return CoreSearch::combine(ISearchBinaryOperator::OPERATOR_AND, [
            $operator,
            CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [
                CoreSearch::compare(ISearchComparison::COMPARE_EQUAL, 'fileid', $root->getId()),
            ]),
        ]);
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
        array $orders,
        FileQuery $query,
        ?IUser $user,
        array $ranks,
    ): array {
        $found = [];
        foreach ($roots as $root) {
            $rooted = $this->withoutRoot($operator, $root);
            foreach ($root->search(CoreSearch::query($rooted, ContentSearchService::WINDOW, 0, $orders, $user)) as $node) {
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
            $text[] = CoreSearch::compare(
                ISearchComparison::COMPARE_LIKE,
                'name',
                '%' . addcslashes($query->term, '%_\\') . '%',
            );
        }

        // `fileid` is one of the filecache columns SearchBuilder exposes, and the
        // only one that accepts `in` — which is what lets the index's answer be
        // an ordinary clause here instead of a second pass in PHP.
        if ($contentIds !== []) {
            $text[] = CoreSearch::compare(ISearchComparison::COMPARE_IN, 'fileid', $contentIds);
        }

        if (count($text) === 1) {
            $parts[] = $text[0];
        } elseif (count($text) > 1) {
            $parts[] = CoreSearch::combine(ISearchBinaryOperator::OPERATOR_OR, $text);
        }

        // Preset filters AND with everything, so "match any" on the advanced conditions
        // never widens the chosen type or date range.
        if ($query->mimetypes !== []) {
            $mimes = array_map(
                fn (string $m) => CoreSearch::compare(
                    str_ends_with($m, '/%') ? ISearchComparison::COMPARE_LIKE : ISearchComparison::COMPARE_EQUAL,
                    'mimetype',
                    $m,
                ),
                $query->mimetypes,
            );
            $parts[] = count($mimes) === 1
                ? $mimes[0]
                : CoreSearch::combine(ISearchBinaryOperator::OPERATOR_OR, $mimes);
        }

        // The two ends of the date filter's window, each optional: most presets
        // are open-ended, and only a period that has ended carries an upper bound.
        $window = [
            [ISearchComparison::COMPARE_GREATER_THAN, $query->modifiedAfter],
            [ISearchComparison::COMPARE_LESS_THAN, $query->modifiedBefore],
        ];
        foreach ($window as [$operator, $bound]) {
            if ($bound !== null) {
                $parts[] = CoreSearch::compare($operator, 'mtime', $bound);
            }
        }

        $conditions = array_map(fn (FileCondition $c) => $this->toComparison($c), $query->conditions);

        if (count($conditions) === 1) {
            $parts[] = $conditions[0];
        } elseif (count($conditions) > 1) {
            $parts[] = CoreSearch::combine(
                $query->matchAny ? ISearchBinaryOperator::OPERATOR_OR : ISearchBinaryOperator::OPERATOR_AND,
                $conditions,
            );
        }

        return count($parts) === 1
            ? $parts[0]
            : CoreSearch::combine(ISearchBinaryOperator::OPERATOR_AND, $parts);
    }

    /**
     * Sorting on a metadata key needs the same EXTRA marker as filtering; an
     * unknown or unindexed key falls back to modification time rather than
     * failing the search over a column the user cannot see anyway.
     */
    private function order(FileQuery $query): array
    {
        $direction = $query->descending ? ISearchOrder::DIRECTION_DESCENDING : ISearchOrder::DIRECTION_ASCENDING;

        // Relevance has no column to sort on; the rows are reordered by score
        // after they come back. The database still needs a valid, stable order
        // to cut its own window by.
        if ($query->sort === FileQuery::SORT_RELEVANCE) {
            return $this->ordered(CoreSearch::orderBy($direction, 'mtime'));
        }

        if (MetadataFields::isMetadata($query->sort)) {
            $key = MetadataFields::key($query->sort);

            return $this->ordered($this->metadataFields->isFilterable($key)
                ? CoreSearch::orderBy($direction, $key, IMetadataQuery::EXTRA)
                : CoreSearch::orderBy($direction, 'mtime'));
        }

        return $this->ordered(CoreSearch::orderBy($direction, $query->sort));
    }

    /**
     * The chosen order, and then the file id — which settles every tie.
     *
     * Without it a page is only as determined as its sort column. Rows that
     * compare equal may come back in any order SQL likes, and it need not be
     * the same order twice: a file can then sit on two consecutive pages while
     * another sits on none. That is no edge case here — on the dev instance
     * 1503 of 2055 files share a name with another, one of them 110 times, and
     * files created in one go share an mtime to the second.
     *
     * Core sorts the merged pages in PHP by the same list, and its comparator
     * knows `fileid` too, so the tie is settled the same way on both paths.
     *
     * @return list<ISearchOrder>
     */
    private function ordered(ISearchOrder $order): array
    {
        return [$order, CoreSearch::orderBy(ISearchOrder::DIRECTION_ASCENDING, 'fileid')];
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

            // A field that may name several people is one string holding all of
            // them, so "is" has to find an id inside that list rather than equal
            // it. See namesPrincipal().
            $principal = $this->metadataFields->principalField($key);
            if ($principal !== null && $principal['multi'] && $condition->operator === 'eq') {
                if ($condition->negate) {
                    // It would become NOT(OR(...)), and SearchBuilder takes only a
                    // plain comparison inside "not". Say so rather than fail deep
                    // in the query builder.
                    throw new \InvalidArgumentException(
                        '"' . $key . '" can name several people, and "is not" cannot be asked of such a field'
                    );
                }

                return $this->namesPrincipal($key, (string)$condition->value);
            }

            $comparison = CoreSearch::compare(
                $condition->comparison(),
                $key,
                $condition->value,
                IMetadataQuery::EXTRA,
            );

            return $condition->negate
                ? CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [$comparison])
                : $comparison;
        }

        $comparison = CoreSearch::compare(
            $condition->comparison(),
            $condition->field,
            $condition->value,
        );

        // SearchBuilder only supports a comparison directly inside "not" — never a
        // nested binary operator — so negation is applied per condition, not per group.
        return $condition->negate
            ? CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [$comparison])
            : $comparison;
    }

    /**
     * "This field names <id>", where the field may name several people.
     *
     * Core writes those as one comma-separated string — `alice, bob` — because
     * the index column is too narrow for the objects they come from. So the id
     * has to be found *within* the value, and a plain "contains" would let `ann`
     * match `joanne`. These are the four places an id can sit in such a list:
     * alone, first, last, or between two others, each anchored on the separator
     * Core writes.
     */
    private function namesPrincipal(string $key, string $id): ISearchOperator
    {
        // The id is a pattern from here on: a literal % or _ in it would
        // otherwise match anything.
        $quoted = addcslashes($id, '%_\\');

        $patterns = [
            [ISearchComparison::COMPARE_EQUAL, $id],
            [ISearchComparison::COMPARE_LIKE, $quoted . ', %'],
            [ISearchComparison::COMPARE_LIKE, '%, ' . $quoted],
            [ISearchComparison::COMPARE_LIKE, '%, ' . $quoted . ', %'],
        ];

        return CoreSearch::combine(
            ISearchBinaryOperator::OPERATOR_OR,
            array_map(
                fn (array $pattern) => CoreSearch::compare($pattern[0], $key, $pattern[1], IMetadataQuery::EXTRA),
                $patterns,
            ),
        );
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
