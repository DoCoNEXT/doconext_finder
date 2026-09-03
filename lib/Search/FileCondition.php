<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OCP\Files\Search\ISearchComparison;

/**
 * One structured search condition: {field} {operator} {value}.
 *
 * Fields are the *filecache column* names the search backend understands
 * (see \OC\Files\Cache\SearchBuilder::$fieldTypes) — not DAV property names.
 * We deliberately expose a curated subset rather than the raw list, so an
 * unsupported field is rejected here instead of surfacing as a 500 from the
 * query builder.
 */
final class FileCondition
{
    /** Field → value type, mirroring SearchBuilder::$fieldTypes for the fields we allow. */
    public const FIELDS = [
        'name'          => 'string',
        'path'          => 'string',
        'mimetype'      => 'string',
        'size'          => 'integer',
        'mtime'         => 'integer',
        'creation_time' => 'integer',
        'favorite'      => 'boolean',
        'tagname'       => 'string',
        // Mapped to the storage's uid_owner by SearchBuilder, and equality-only
        // there — it is a join column, not a filecache one.
        'owner'         => 'string',
    ];

    /**
     * Which operators each field actually accepts, mirroring
     * \OC\Files\Cache\SearchBuilder::validateComparison(). Without this the
     * field × operator cross-product offers combinations the query builder
     * rejects with a 500 (e.g. "name gt"), so the check belongs here.
     */
    public const FIELD_OPERATORS = [
        'name'          => ['eq', 'contains'],
        'path'          => ['eq', 'contains'],
        'mimetype'      => ['eq', 'contains'],
        'size'          => ['eq', 'lt', 'lte', 'gt', 'gte'],
        'mtime'         => ['eq', 'lt', 'lte', 'gt', 'gte'],
        'creation_time' => ['eq', 'lt', 'lte', 'gt', 'gte'],
        'favorite'      => ['eq'],
        'tagname'       => ['eq', 'contains'],
        'owner'         => ['eq'],
    ];

    /**
     * Fields the query builder reaches through a join rather than a filecache column.
     * `NOT` on these compares a NULL column and matches nothing, so negation is refused.
     */
    public const JOIN_BACKED = ['favorite', 'tagname', 'owner'];

    /**
     * Metadata is stored as one indexed string column whatever the declared type,
     * so only text comparisons behave predictably across keys.
     */
    public const METADATA_OPERATORS = ['eq', 'contains'];

    /** Public operator name → ISearchComparison constant. */
    public const OPERATORS = [
        'eq'       => ISearchComparison::COMPARE_EQUAL,
        'lt'       => ISearchComparison::COMPARE_LESS_THAN,
        'lte'      => ISearchComparison::COMPARE_LESS_THAN_EQUAL,
        'gt'       => ISearchComparison::COMPARE_GREATER_THAN,
        'gte'      => ISearchComparison::COMPARE_GREATER_THAN_EQUAL,
        'contains' => ISearchComparison::COMPARE_LIKE,
    ];

    private function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly string|int|bool $value,
        public readonly bool $negate,
    ) {
    }

    /**
     * Builds a condition from untrusted input, or throws with a message safe to
     * return to the caller.
     *
     * @param array<string,mixed> $raw
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $field = (string)($raw['field'] ?? '');

        // Metadata fields are validated by the caller against the server's registry,
        // since which keys exist depends on what apps are installed.
        if (MetadataFields::isMetadata($field)) {
            $operator = (string)($raw['operator'] ?? '');
            if (!in_array($operator, self::METADATA_OPERATORS, true)) {
                throw new \InvalidArgumentException(
                    '"' . $field . '" does not support "' . $operator . '"; allowed: '
                    . implode(', ', self::METADATA_OPERATORS)
                );
            }
            $value = trim((string)($raw['value'] ?? ''));
            if ($value === '') {
                throw new \InvalidArgumentException('condition on "' . $field . '" needs a value');
            }
            if ($operator === 'contains') {
                $value = '%' . addcslashes($value, '%_\\') . '%';
            }

            return new self($field, $operator, $value, (bool)($raw['negate'] ?? false));
        }

        if (!isset(self::FIELDS[$field])) {
            throw new \InvalidArgumentException(
                'unknown field "' . $field . '"; allowed: ' . implode(', ', array_keys(self::FIELDS))
            );
        }

        $operator = (string)($raw['operator'] ?? '');
        if (!isset(self::OPERATORS[$operator])) {
            throw new \InvalidArgumentException(
                'unknown operator "' . $operator . '"; allowed: ' . implode(', ', array_keys(self::OPERATORS))
            );
        }
        if (!in_array($operator, self::FIELD_OPERATORS[$field], true)) {
            throw new \InvalidArgumentException(
                '"' . $field . '" does not support "' . $operator . '"; allowed: '
                . implode(', ', self::FIELD_OPERATORS[$field])
            );
        }

        $negate = (bool)($raw['negate'] ?? false);
        if ($negate && in_array($field, self::JOIN_BACKED, true)) {
            throw new \InvalidArgumentException('"' . $field . '" cannot be negated (see FileCondition)');
        }

        $raw_value = $raw['value'] ?? null;
        if ($raw_value === null || $raw_value === '') {
            throw new \InvalidArgumentException('condition on "' . $field . '" needs a value');
        }

        // "favorite" is a presence flag, not a boolean column: SearchBuilder rewrites it to
        // `tag.category = <favorite tag>` and DISCARDS the supplied value, so "favorite eq false"
        // runs the identical query to "eq true" and would silently return favorites.
        //
        // The obvious repair — a negated presence check — does not work either. Tag fields are
        // reached through a join, and negation becomes `tag.category != <favorite>`; for a file
        // with no tag row that column is NULL, and `NULL != x` is NULL, so nothing matches.
        // Verified against the dev server: negating it returns an empty set, not the
        // non-favorites. So "not favorited" is not expressible here and is rejected rather than
        // answered wrongly.
        if ($field === 'favorite') {
            if (!filter_var($raw_value, FILTER_VALIDATE_BOOL)) {
                throw new \InvalidArgumentException(
                    '"favorite" can only match favorited files ("value": true); '
                    . 'the search backend cannot express "not favorited"'
                );
            }

            return new self($field, $operator, true, false);
        }

        // Cast to the column's type; a numeric column compared against a string
        // silently matches nothing, so this is correctness, not tidiness.
        $value = match (self::FIELDS[$field]) {
            'integer' => (int)$raw_value,
            'boolean' => filter_var($raw_value, FILTER_VALIDATE_BOOL),
            default   => (string)$raw_value,
        };

        // Mimetype is special-cased server-side: an exact "mime/type" is resolved to a
        // numeric id, and only a trailing "mime/%" is accepted as a pattern — anything
        // else raises an InvalidArgumentException from SearchBuilder.
        if ($field === 'mimetype' && $operator === 'contains') {
            $mime = (string)$value;
            if (!str_ends_with($mime, '/%') || substr_count($mime, '%') !== 1) {
                throw new \InvalidArgumentException(
                    'mimetype patterns must be "type/subtype" (exact) or "type/%" (wildcard), got "' . $mime . '"'
                );
            }

            return new self($field, $operator, $mime, $negate);
        }

        // "contains" is a LIKE: wrap the term, escaping the wildcards so a literal
        // % or _ in a filename doesn't turn into a pattern.
        if ($operator === 'contains') {
            $value = '%' . addcslashes((string)$value, '%_\\') . '%';
        }

        return new self($field, $operator, $value, $negate);
    }

    public function comparison(): string
    {
        return self::OPERATORS[$this->operator];
    }
}
