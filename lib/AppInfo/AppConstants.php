<?php

declare(strict_types=1);

namespace OCA\DcnFinder\AppInfo;

/**
 * Single source of truth for the app's identity: who ships it, what it is
 * called internally, and what it is called on screen.
 *
 * The identity is spelled out in parts (company + app, each with an
 * abbreviation) and everything else is composed from them, exactly as
 * DoCoNEXT Core does it. Nothing else in the app may spell an app id, a log
 * prefix or a table prefix out by hand: a rebrand is then a change to the four
 * strings at the top of this file, and {@see \OCA\DcnFinder\Db\DbConstants}
 * follows along.
 *
 * The abbreviations exist because two identifiers have a length budget: log
 * lines are grepped by eye, and DB object names are capped at 30 characters on
 * Oracle — see DbConstants.
 *
 * The product is identified on screen by a configurable DISPLAY name (so the
 * customer can rebrand it) with a functional fallback — the internal app id is
 * never shown to users.
 */
final class AppConstants
{
    /** Company name, as it appears in the app id. */
    public const COMPANY_NAME = 'doconext';

    /** Company name as people read it: the admin-menu prefix. */
    public const COMPANY_DISPLAY_NAME = 'DoCoNEXT';

    /** Company abbreviation, for the identifiers with a length budget. */
    public const COMPANY_NAME_ABBREV = 'dcn';

    /** App name, as it appears in the app id (the part after the company). */
    public const APP_NAME = 'finder';

    /**
     * App abbreviation, for the identifiers with a length budget. Equal to
     * APP_NAME here — 'finder' is already short enough — but kept separate
     * because the composed identifiers below are built from the abbreviations.
     */
    public const APP_NAME_ABBREV = 'finder';

    /**
     * The app's unique id — must equal <id> in appinfo/info.xml and the app's
     * directory name exactly. Used by Nextcloud internally (routing, app
     * registry, app-config namespace, asset paths).
     */
    public const APP_ID = self::COMPANY_NAME . '_' . self::APP_NAME;

    /**
     * Prefix for this app's log messages, so they are easy to grep in
     * nextcloud.log. Derived from the abbreviations, so a rebrand only changes
     * the constants above. Currently renders `[dcn-finder]`.
     */
    public const LOG_PREFIX = '[' . self::COMPANY_NAME_ABBREV . '-' . self::APP_NAME_ABBREV . ']';

    /** app-config key holding the admin-set display name (see Settings). */
    public const DISPLAY_NAME_KEY = 'display_name';

    /** Fallback shown when no custom display name is configured. */
    public const DEFAULT_DISPLAY_NAME = 'Finder';

    /**
     * app-config namespace + key for which file extensions "Open in local app"
     * hands to DoCoNEXT Bridge instead of to the Nextcloud desktop client.
     *
     * Filed under 'doconext_bridge' rather than {@see APP_ID}: this setting
     * describes the Bridge's own routing behaviour, not something of Finder's —
     * Finder only happens to be where it is edited today. \OCP\IAppConfig takes
     * the app id as a plain argument with no ownership check, so any app that
     * agrees on this namespace + key reads and writes the same value; that is
     * how DoCoNEXT Core is meant to reach it too, without depending on Finder's
     * identity. It also means nothing has to change if a dedicated Bridge app
     * ever exists to own this outright — this already is its namespace.
     */
    public const BRIDGE_APP_ID = 'doconext_bridge';
    public const BRIDGE_EXTENSIONS_KEY = 'extensions';

    /**
     * Email formats, because they are the case the desktop client cannot serve: a
     * .msg opens in no mail client outside Windows without being converted first.
     * An admin can extend this as other such formats turn up.
     */
    public const DEFAULT_BRIDGE_EXTENSIONS = 'eml,msg';

    /**
     * app-config key for the ordered list of categories the search page's "Type"
     * filter offers. See {@see \OCA\DcnFinder\Service\FileTypeFilterSettings}.
     */
    public const FILE_TYPE_FILTERS_KEY = 'file_type_filters';

    /**
     * The built-in categories, in their default order. Only the id is stored
     * server-side — label and mimetype list are translated client-side, in
     * src/filters/presets.ts, and must stay in sync with this list.
     */
    public const BUILTIN_FILE_TYPE_IDS = [
        'files', 'documents', 'spreadsheets', 'presentations', 'pdf',
        'images', 'video', 'audio', 'email', 'archives', 'folders',
    ];
}
