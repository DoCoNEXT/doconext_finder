<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use LogicException;
use OCP\Files\Node;
use OCP\Files\Search\ISearchOrder;
use OCP\FilesMetadata\IMetadataQuery;

/**
 * Orders result rows in PHP the way the database ordered them.
 *
 * Two places need it. Folder::search() hands back a page whose rows the
 * database cut correctly but which it then re-sorts itself when the folder
 * spans several storages — with a comparator that knows neither
 * `creation_time` nor metadata keys and so leaves those pages in file-id
 * order. And a search across several roots merges pages that were each cut by
 * the database. Both are only right if PHP compares exactly as the SQL did,
 * which is why this reads the same {@see ISearchOrder} list the query was built
 * with instead of deciding the order a second time.
 *
 * Strings compare byte-wise, as the `utf8mb4_bin` columns Nextcloud creates
 * on MySQL do; `<=>` would compare "100" and "99" as numbers. A row without the
 * metadata value sorts where the database puts NULL, which is not the same
 * everywhere: the smallest value on MySQL and SQLite, the largest on
 * PostgreSQL and Oracle.
 */
final class ResultOrder
{
    /**
     * The fields Folder::search() re-sorts a multi-storage page by itself.
     *
     * Its comparator reads the filecache row, so it sorts a team folder's root
     * by the name the database sorted it by ("files"), where a Node reports the
     * mount name the user sees; re-sorting those here would move rows across
     * the page cut. tests/Platform checks core still sorts by each of these.
     */
    public const CORE_SORTED_FIELDS = ['fileid', 'name', 'size', 'mtime'];

    /**
     * Whether a page Folder::search() returned is already in the database's
     * order — true unless an order names a field core cannot compare.
     *
     * @param list<ISearchOrder> $orders
     */
    public static function coreSortsBy(array $orders): bool
    {
        foreach ($orders as $order) {
            if ($order->getExtra() !== '' || !in_array($order->getField(), self::CORE_SORTED_FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<ISearchOrder> $orders
     * @return callable(Node, Node): int
     */
    public static function comparator(array $orders, bool $nullIsSmallest): callable
    {
        $keys = array_map(static fn (ISearchOrder $order) => [
            self::valueOf($order),
            $order->getDirection() === ISearchOrder::DIRECTION_DESCENDING ? -1 : 1,
        ], $orders);

        return static function (Node $a, Node $b) use ($keys, $nullIsSmallest): int {
            foreach ($keys as [$value, $direction]) {
                $cmp = self::compare($value($a), $value($b), $nullIsSmallest);
                if ($cmp !== 0) {
                    return $direction * $cmp;
                }
            }

            return 0;
        };
    }

    /**
     * @return callable(Node): (string|int|float|bool|null)
     */
    private static function valueOf(ISearchOrder $order): callable
    {
        $field = $order->getField();

        if ($order->getExtra() === IMetadataQuery::EXTRA) {
            return static function (Node $n) use ($field): string|int|float|bool|null {
                $value = $n->getMetadata()[$field] ?? null;

                return is_scalar($value) ? $value : null;
            };
        }

        return match ($field) {
            'fileid'        => static fn (Node $n) => $n->getId(),
            'name'          => static fn (Node $n) => $n->getName(),
            'size'          => static fn (Node $n) => $n->getSize(),
            'mtime'         => static fn (Node $n) => $n->getMTime(),
            'creation_time' => static fn (Node $n) => $n->getCreationTime(),
            default         => throw new LogicException("No PHP ordering for '$field'; the database would sort by it and this merge would not."),
        };
    }

    private static function compare(string|int|float|bool|null $a, string|int|float|bool|null $b, bool $nullIsSmallest): int
    {
        if ($a === null || $b === null) {
            if ($a === $b) {
                return 0;
            }

            return ($a === null) === $nullIsSmallest ? -1 : 1;
        }

        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }
}
