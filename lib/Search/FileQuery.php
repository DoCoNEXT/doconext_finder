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
 */
final class FileQuery
{
    public const MAX_LIMIT = 500;
    public const DEFAULT_LIMIT = 100;

    /**
     * @param list<FileCondition> $conditions
     * @param list<string> $mimetypes
     */
    private function __construct(
        public readonly string $term,
        public readonly array $conditions,
        public readonly array $mimetypes,
        public readonly ?int $modifiedAfter,
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
     * @param array<string,mixed> $body
     * @throws \InvalidArgumentException on an unusable condition
     */
    public static function fromArray(array $body): self
    {
        $rawConditions = $body['conditions'] ?? [];
        if (!is_array($rawConditions)) {
            throw new \InvalidArgumentException('"conditions" must be an array');
        }

        $conditions = array_map(
            static fn ($c) => FileCondition::fromArray(is_array($c) ? $c : []),
            array_values($rawConditions)
        );

        $term = trim((string)($body['term'] ?? ''));

        // Preset filters are separate from the condition list on purpose: they AND with
        // everything, so switching the condition group to "match any" cannot accidentally
        // widen the chosen file type or date range into an alternative.
        $mimetypes = array_values(array_filter(array_map(
            static fn ($m) => self::validMimetype((string)$m),
            is_array($body['mimetypes'] ?? null) ? $body['mimetypes'] : []
        )));

        $modifiedAfter = isset($body['modifiedAfter']) ? (int)$body['modifiedAfter'] : null;
        if ($modifiedAfter !== null && $modifiedAfter <= 0) {
            $modifiedAfter = null;
        }

        // A scope narrows *where* rather than *what*, so it counts as a filter:
        // "everything in this dossier" is a search a user may reasonably run
        // without typing a term.
        $scope = is_array($body['scope'] ?? null) && $body['scope'] !== []
            ? FileScope::fromArray($body['scope'])
            : null;

        if ($term === '' && $conditions === [] && $mimetypes === [] && $modifiedAfter === null && $scope === null) {
            throw new \InvalidArgumentException('provide a search term or at least one filter');
        }

        $limit = (int)($body['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $sort = (string)($body['sort'] ?? 'mtime');
        // Metadata keys are sortable too, and are checked against the registry by
        // the service rather than against a fixed list here.
        if (!in_array($sort, self::SORTS, true) && !MetadataFields::isMetadata($sort)) {
            $sort = 'mtime';
        }

        return new self(
            term: $term,
            conditions: $conditions,
            mimetypes: $mimetypes,
            modifiedAfter: $modifiedAfter,
            scope: $scope,
            matchAny: (bool)($body['matchAny'] ?? false),
            limit: $limit,
            offset: max(0, (int)($body['offset'] ?? 0)),
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
