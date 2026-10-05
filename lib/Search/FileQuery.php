<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

/**
 * A validated file search: a free-text term plus a list of structured
 * conditions, combined by {@see $matchAny}.
 *
 * The term always ANDs with the condition group, so "match any" scopes only the
 * structured conditions — the same semantics the desktop client uses, and the
 * one users expect from a search box sitting above a filter panel.
 *
 * {@see $scope} is not a condition at all: it decides which folder the search
 * starts from, so no combination of conditions can widen past it.
 *
 * {@see $content} looks inside the files rather than at their names, and is the
 * one part of a query this app cannot answer itself: it is resolved by the
 * full-text index and comes back ranked and windowed, where everything else
 * here is exact and exhaustive.
 *
 * It stays a separate field from {@see $term} because the two are answered by
 * different engines, but they are *alternatives*, not a conjunction: a file
 * matches when its name matches the term or its text matches the content
 * phrase. "Search for invoice, and look inside the files too" is one question
 * with a wider answer, not two questions ANDed into almost nothing — a name
 * that contains the word rarely also has it in the text, so requiring both
 * emptied the result set. The union costs the ranked order the index gives:
 * see {@see SORT_RELEVANCE}, which is why that sort is refused once a term is
 * present.
 */
final class FileQuery
{
    public const MAX_LIMIT = 500;
    public const DEFAULT_LIMIT = 100;

    /**
     * Ceilings on what one request may ask, far above what the page ever sends
     * (it pages in steps of at most 200, and its largest type preset has six
     * mimetypes). They exist for the request the page did not send: a search
     * across several roots holds `offset + limit` rows per root in memory before
     * it cuts the page, and every condition becomes a clause of one SQL query.
     */
    public const MAX_OFFSET = 10000;
    public const MAX_CONDITIONS = 50;
    public const MAX_MIMETYPES = 100;
    public const MAX_TERM_LENGTH = 255;

    /**
     * @param list<FileCondition> $conditions
     * @param list<string> $mimetypes
     */
    private function __construct(
        public readonly string $term,
        public readonly string $content,
        public readonly array $conditions,
        public readonly array $mimetypes,
        public readonly ?int $modifiedAfter,
        public readonly ?int $modifiedBefore,
        public readonly ?FileScope $scope,
        public readonly bool $matchAny,
        public readonly int $limit,
        public readonly int $offset,
        public readonly string $sort,
        public readonly bool $descending,
    ) {
    }

    /** Sortable columns. Anything else falls back to mtime. */
    public const SORTS = ['mtime', 'name', 'size', 'creation_time'];

    /**
     * Not a column: the order the full-text index returned the page in.
     *
     * Only meaningful while {@see $content} is set and {@see $term} is not:
     * there is nothing to rank a result by when nothing was matched against its
     * text, and once names are matched too the rows are a union of a ranked
     * window and an exhaustive set — every name-only row would land at the
     * bottom in file-id order, which is not a ranking of anything.
     */
    public const SORT_RELEVANCE = 'relevance';

    /**
     * @param array<string,mixed> $body
     * @throws \InvalidArgumentException on an unusable condition
     */
    public static function fromArray(array $body): self
    {
        $rawConditions = $body['conditions'] ?? [];
        if (!is_array($rawConditions)) {
            throw new \InvalidArgumentException('"conditions" must be an array');
        }
        if (count($rawConditions) > self::MAX_CONDITIONS) {
            throw new \InvalidArgumentException('at most ' . self::MAX_CONDITIONS . ' conditions');
        }

        $conditions = array_map(
            static fn ($c) => FileCondition::fromArray(is_array($c) ? $c : []),
            array_values($rawConditions)
        );

        $term = trim((string)($body['term'] ?? ''));
        $content = trim((string)($body['content'] ?? ''));
        if (mb_strlen($term) > self::MAX_TERM_LENGTH || mb_strlen($content) > self::MAX_TERM_LENGTH) {
            throw new \InvalidArgumentException('a search term can be at most ' . self::MAX_TERM_LENGTH . ' characters');
        }

        $rawMimetypes = is_array($body['mimetypes'] ?? null) ? $body['mimetypes'] : [];
        if (count($rawMimetypes) > self::MAX_MIMETYPES) {
            throw new \InvalidArgumentException('at most ' . self::MAX_MIMETYPES . ' file types');
        }

        // Preset filters are separate from the condition list on purpose: they AND with
        // everything, so switching the condition group to "match any" cannot accidentally
        // widen the chosen file type or date range into an alternative.
        $mimetypes = array_values(array_filter(array_map(
            static fn ($m) => self::validMimetype((string)$m),
            $rawMimetypes
        )));

        $modifiedAfter = isset($body['modifiedAfter']) ? (int)$body['modifiedAfter'] : null;
        if ($modifiedAfter !== null && $modifiedAfter <= 0) {
            $modifiedAfter = null;
        }

        // The upper bound of a date preset that names a period which has ended.
        // It only ever arrives alongside a lower one, but is validated on its
        // own: a bound that would leave no window at all is no filter, it is a
        // guaranteed empty result set, and dropping it says so.
        $modifiedBefore = isset($body['modifiedBefore']) ? (int)$body['modifiedBefore'] : null;
        if ($modifiedBefore !== null && ($modifiedBefore <= 0 || ($modifiedAfter !== null && $modifiedBefore <= $modifiedAfter))) {
            $modifiedBefore = null;
        }

        // A scope narrows *where* rather than *what*, so it counts as a filter:
        // "everything in this dossier" is a search a user may reasonably run
        // without typing a term.
        $scope = is_array($body['scope'] ?? null) && $body['scope'] !== []
            ? FileScope::fromArray($body['scope'])
            : null;

        if ($term === '' && $content === '' && $conditions === [] && $mimetypes === [] && $modifiedAfter === null && $modifiedBefore === null && $scope === null) {
            throw new \InvalidArgumentException('provide a search term or at least one filter');
        }

        $limit = (int)($body['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Refused rather than clamped: a clamped offset hands "the next page"
        // back as the same page, and a pager would ask for it forever.
        $offset = max(0, (int)($body['offset'] ?? 0));
        if ($offset > self::MAX_OFFSET) {
            throw new \InvalidArgumentException(
                'results beyond the first ' . self::MAX_OFFSET . ' cannot be paged to; narrow the search instead'
            );
        }

        $sort = (string)($body['sort'] ?? 'mtime');
        // Metadata keys are sortable too, and are checked against the registry by
        // the service rather than against a fixed list here.
        $sortable = in_array($sort, self::SORTS, true)
            || MetadataFields::isMetadata($sort)
            || ($sort === self::SORT_RELEVANCE && $content !== '' && $term === '');
        if (!$sortable) {
            $sort = 'mtime';
        }

        return new self(
            term: $term,
            content: $content,
            conditions: $conditions,
            mimetypes: $mimetypes,
            modifiedAfter: $modifiedAfter,
            modifiedBefore: $modifiedBefore,
            scope: $scope,
            matchAny: (bool)($body['matchAny'] ?? false),
            limit: $limit,
            offset: $offset,
            sort: $sort,
            descending: (bool)($body['descending'] ?? true),
        );
    }

    /**
     * SearchBuilder resolves an exact "type/subtype" to a numeric mimetype id and
     * accepts only a trailing "type/%" as a pattern; anything else throws. Reject
     * the rest here rather than letting it surface as a 500.
     *
     * Public so other callers validating a mimetype pattern — admin-configured
     * custom file-type filters, in particular — apply the same rule rather than
     * a second copy of it. See {@see \OCA\DcnFinder\Service\FileTypeFilterSettings}.
     */
    public static function validMimetype(string $mime): ?string
    {
        $mime = trim($mime);
        if ($mime === '') {
            return null;
        }
        if (str_ends_with($mime, '/%')) {
            return substr_count($mime, '%') === 1 ? $mime : null;
        }

        return substr_count($mime, '%') === 0 && substr_count($mime, '/') === 1 ? $mime : null;
    }
}
