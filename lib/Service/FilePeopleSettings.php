<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\IAppConfig;

/**
 * Whether Finder keeps track of who makes and changes files.
 *
 * Off until an admin turns it on: it stores account information with every file
 * and adds work to every upload, which is not something an app should start
 * doing because it was installed. While off nothing is recorded, the two filters
 * are not offered and the Created by column shows the owner. What was stored
 * earlier stays, so turning it back on loses nothing; changes in between are not
 * on record.
 */
class FilePeopleSettings
{
    private const CONFIG_ENABLED = 'file_people_enabled';

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->appConfig->getValueBool(AppConstants::APP_ID, self::CONFIG_ENABLED, false);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->appConfig->setValueBool(AppConstants::APP_ID, self::CONFIG_ENABLED, $enabled);
    }
}
