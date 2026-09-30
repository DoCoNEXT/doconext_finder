<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OC\Files\Search\SearchBinaryOperator;
use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchOrder;
use OC\Files\Search\SearchQuery;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

/**
 * The only place this app instantiates Nextcloud's core-private search classes.
 *
 * There is no public builder for a file search, only the interfaces
 * Folder::search() accepts, so the app store's "public API only" rule cannot be
 * met here; Nextcloud's own Files, DAV and systemtags apps construct the same
 * classes. Implementing the interfaces ourselves was weighed and rejected: core
 * re-sorts results merged from several storages with our order's
 * sortFileInfo(), and its query optimizer only descends into its own operator
 * class, so our own classes would copy core's comparator and give up its
 * rewrites while the dependency on its semantics stayed.
 *
 * What is accepted instead is kept checked: tests/Platform runs these four
 * against each Nextcloud version info.xml claims, so a changed constructor
 * fails CI rather than a customer's search. Psalm cannot see `OC\` at all —
 * `nextcloud/ocp` ships only OCP — which is why the four are baselined.
 */
final class CoreSearch
{
    /**
     * @param list<ISearchOrder> $orders
     */
    public static function query(
        ISearchOperator $operator,
        int $limit,
        int $offset,
        array $orders,
        ?IUser $user,
    ): ISearchQuery {
        return new SearchQuery($operator, $limit, $offset, $orders, $user);
    }

    public static function orderBy(string $direction, string $field, string $extra = ''): ISearchOrder
    {
        return new SearchOrder($direction, $field, $extra);
    }

    public static function compare(string $type, string $field, mixed $value, string $extra = ''): ISearchComparison
    {
        return new SearchComparison($type, $field, $value, $extra);
    }

    /**
     * @param list<ISearchOperator> $operands
     */
    public static function combine(string $type, array $operands): ISearchOperator
    {
        return new SearchBinaryOperator($type, $operands);
    }
}
