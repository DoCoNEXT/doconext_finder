<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCA\DcnFinder\AppInfo\AppConstants;

/**
 * Single source of truth for every table name this app owns.
 *
 * Two rules make a central list worth having:
 *
 * - A table name must never be spelled out by hand. Migrations, mappers and
 *   any hand-written query all name the same table, and the day the prefix
 *   changes they must all change together or the app silently queries a table
 *   that no longer exists.
 * - Index / FK / constraint names must be globally unique across the whole
 *   Nextcloud schema, not just within the table — a duplicate aborts every
 *   app's enable/upgrade. Build them from {@see DB_TABLE_PREFIX} so they
 *   cannot collide with another app's.
 *
 * Keep the names short. Oracle caps identifiers at 30 characters, which is why
 * the prefix uses the abbreviations rather than the full company and app name:
 * `dcn_finder_` leaves 19 characters for the table, and less for an index —
 * hence `srch` rather than `searches` in the two index names.
 */
final class DbConstants
{
    /**
     * Full table prefix — use this in every createTable() call in migrations
     * and in every ->from() in a query.
     *
     * Derived from {@see AppConstants} so a rebrand changes one file. Results
     * in table names like:
     *   dcn_finder_searches   (stored by Nextcloud as oc_dcn_finder_searches)
     */
    public const DB_TABLE_PREFIX = AppConstants::COMPANY_NAME_ABBREV . '_' . AppConstants::APP_NAME_ABBREV . '_';

    // Bare table names, private: nothing outside this class may use one
    // without the prefix.
    private const SEARCHES = 'searches';

    // Resolve all table names from the central prefix constant.
    public const DB_TABLENAME_SEARCHES = self::DB_TABLE_PREFIX . self::SEARCHES;

    /**
     * The example table the app template shipped, dropped by the migration that
     * created the searches table. Nothing ever referenced it.
     *
     * Spelled out rather than composed onto DB_TABLE_PREFIX, which is the one
     * place that would be wrong: this is not a table the app has, it is a name
     * the app once had. Composing it would let a rebrand carry it along to
     * `dcn_<new>_notes` — a table that never existed under that name, so the
     * drop would quietly stop matching anything. Historical names are frozen.
     */
    public const DB_TABLENAME_LEGACY_NOTES = 'dcn_finder_notes';
}
