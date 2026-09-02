<?php

declare(strict_types=1);

namespace OCA\DcnFinder\AppInfo;

/**
 * Single source of truth for the app id + its user-facing name.
 *
 * The product is identified everywhere by a configurable DISPLAY name (so the
 * customer can rebrand it) with a functional fallback — the internal app id is
 * never shown to users.
 */
final class AppConstants
{
    public const APP_ID = 'doconext_finder';

    /** app-config key holding the admin-set display name (see Settings). */
    public const DISPLAY_NAME_KEY = 'display_name';

    /** Fallback shown when no custom display name is configured. */
    public const DEFAULT_DISPLAY_NAME = 'DoCoNEXT Finder';
}
