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
 */
final class FileQuery
{
    public const MAX_LIMIT = 500;
    public const DEFAULT_LIMIT = 100;

    /** @param list<FileCondition> $conditions */
    private function __construct(
        public readonly string $term,
        public readonly array $conditions,
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

        if ($term === '' && $conditions === []) {
            throw new \InvalidArgumentException('provide a search term or at least one condition');
        }

        $limit = (int)($body['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $sort = (string)($body['sort'] ?? 'mtime');
        if (!in_array($sort, self::SORTS, true)) {
            $sort = 'mtime';
        }

        return new self(
            term: $term,
            conditions: $conditions,
            matchAny: (bool)($body['matchAny'] ?? false),
            limit: $limit,
            offset: max(0, (int)($body['offset'] ?? 0)),
            sort: $sort,
            descending: (bool)($body['descending'] ?? true),
        );
    }
}
