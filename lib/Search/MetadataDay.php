<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\FilesMetadata\IMetadataQuery;

/**
 * "On", "before" and "after" a day, for a metadata key that holds a date or a
 * moment.
 *
 * Both are stored as text, and the comparison stays one of text: a date as
 * `2026-06-24`, a moment as UTC with a fixed `+00:00`, which Core guarantees
 * and which makes text order time order. What differs is the day. A date is
 * the same day for everyone. A moment falls on a day only on somebody's
 * clock, so a day becomes the UTC instants between two of the searcher's
 * midnights — 23 or 25 hours apart when the clock changes in between. A
 * prefix match on `2026-06-24` would use UTC's midnights instead, and miss a
 * mail sent at 01:00 in Amsterdam.
 *
 * Every bound is the start of a day: "on or before the 24th" is "before the
 * 25th starts".
 */
final class MetadataDay
{
    /** The operators a day can be asked with; "contains" stays a text match. */
    public const OPERATORS = ['eq', 'lt', 'lte', 'gt', 'gte'];

    /**
     * @param 'date'|'datetime' $kind what the key holds, as Core says
     * @param string            $day  `Y-m-d`
     * @throws InvalidQueryException for a value that is no calendar day
     */
    public static function operator(string $key, string $kind, string $operator, string $day, bool $negate, \DateTimeZone $zone): ISearchOperator
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new InvalidQueryException('"' . $key . '" compares with a day, as YYYY-MM-DD; got "' . $day . '"');
        }
        $start = self::bound($date, $kind);
        $next  = self::bound($date->modify('+1 day'), $kind);

        $compare = static fn (string $type, string $value): ISearchComparison
            => CoreSearch::compare($type, $key, $value, IMetadataQuery::EXTRA);

        if ($operator === 'eq') {
            if ($kind === 'date') {
                $on = $compare(ISearchComparison::COMPARE_EQUAL, $start);
                return $negate ? CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [$on]) : $on;
            }
            // Spelled out rather than NOT(AND(...)): SearchBuilder takes only a
            // plain comparison inside "not".
            return $negate
                ? CoreSearch::combine(ISearchBinaryOperator::OPERATOR_OR, [
                    $compare(ISearchComparison::COMPARE_LESS_THAN, $start),
                    $compare(ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $next),
                ])
                : CoreSearch::combine(ISearchBinaryOperator::OPERATOR_AND, [
                    $compare(ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $start),
                    $compare(ISearchComparison::COMPARE_LESS_THAN, $next),
                ]);
        }

        $comparison = match ($operator) {
            'lt'    => $compare(ISearchComparison::COMPARE_LESS_THAN, $start),
            'lte'   => $compare(ISearchComparison::COMPARE_LESS_THAN, $next),
            'gt'    => $compare(ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $next),
            'gte'   => $compare(ISearchComparison::COMPARE_GREATER_THAN_EQUAL, $start),
            default => throw new InvalidQueryException('"' . $key . '" does not support "' . $operator . '"'),
        };

        return $negate ? CoreSearch::combine(ISearchBinaryOperator::OPERATOR_NOT, [$comparison]) : $comparison;
    }

    /** Midnight starting $day on the searcher's clock, in the form the key stores. */
    private static function bound(\DateTimeImmutable $day, string $kind): string
    {
        return $kind === 'date'
            ? $day->format('Y-m-d')
            : $day->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
