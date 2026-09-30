<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Settings;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Section under Settings → Administration, labelled "DoCoNEXT <display name>"
 * so the DoCoNEXT apps read as one family there, as Core's "DoCoNEXT Core"
 * does. The top menu keeps the bare display name.
 */
class AdminSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IAppConfig $appConfig,
    ) {
    }

    #[\Override]
    public function getID(): string
    {
        return AppConstants::APP_ID;
    }

    #[\Override]
    public function getName(): string
    {
        $name = $this->appConfig->getValueString(AppConstants::APP_ID, AppConstants::DISPLAY_NAME_KEY, '');

        $name = $name !== '' ? $name : AppConstants::DEFAULT_DISPLAY_NAME;

        return str_starts_with($name, AppConstants::COMPANY_DISPLAY_NAME . ' ')
            ? $name
            : AppConstants::COMPANY_DISPLAY_NAME . ' ' . $name;
    }

    #[\Override]
    public function getPriority(): int
    {
        return 75;
    }

    #[\Override]
    public function getIcon(): string
    {
        // Settings sidebar icons are recolored via filter → use the BLACK-filled
        // app-dark.svg here (app.svg is white-filled, for the top app-menu).
        return $this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg');
    }
}
