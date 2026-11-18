<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OCA\DcnFinder\Db\DbConstants;

/**
 * The "Modified by" and "Created by" filters, and how they stay searchable.
 *
 * Nextcloud cannot search on who wrote or made a file. So Finder keeps its own
 * copy in Nextcloud's indexed file metadata, which the search backend can
 * compare and combine like any column.
 *
 * What is indexed is a hash of the account id, not the id. The index column
 * holds 63 characters and an id may have 64; a value that does not fit is
 * dropped with no more than a warning in the log, and the file can then never
 * be found. Only equality is ever asked, so nothing needs the id itself.
 *
 * The creator's id is kept as well, unindexed, because the Created by column has
 * to show a name and nothing else records who made a file. The last editor needs
 * no such copy: the column reads that from the file's revisions.
 */
final class PersonField
{
    /** The fields' names in a search condition. Their value is an account id. */
    public const MODIFIED_BY = 'modified_by';
    public const CREATED_BY = 'created_by';

    public const MODIFIED_BY_KEY = DbConstants::DB_TABLE_PREFIX . 'modified_by';
    public const CREATED_BY_KEY = DbConstants::DB_TABLE_PREFIX . 'created_by';
    public const CREATOR_ID_KEY = DbConstants::DB_TABLE_PREFIX . 'creator_id';

    /** Field => the indexed metadata key behind it. */
    public const METADATA_KEYS = [
        self::MODIFIED_BY => self::MODIFIED_BY_KEY,
        self::CREATED_BY  => self::CREATED_BY_KEY,
    ];

    /** Finder's bookkeeping, never offered as metadata fields of their own. */
    public const INTERNAL_KEYS = [self::MODIFIED_BY_KEY, self::CREATED_BY_KEY, self::CREATOR_ID_KEY];

    public static function isPersonField(string $field): bool
    {
        return isset(self::METADATA_KEYS[$field]);
    }

    /** What is stored and compared for an account. */
    public static function token(string $uid): string
    {
        return sha1($uid);
    }
}
