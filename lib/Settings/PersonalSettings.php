<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Settings;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Service\InitialStateProvider;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings
{
    public function __construct(
        private InitialStateProvider $initialState,
    ) {
    }

    #[\Override]
    public function getForm(): TemplateResponse
    {
        Util::addScript(AppConstants::APP_ID, AppConstants::APP_ID . '-personal-settings');
        Util::addStyle(AppConstants::APP_ID, AppConstants::APP_ID . '-personal-settings');
        $this->initialState->provide();

        return new TemplateResponse(AppConstants::APP_ID, 'personal/settings');
    }

    #[\Override]
    public function getSection(): string
    {
        return AppConstants::APP_ID;
    }

    #[\Override]
    public function getPriority(): int
    {
        return 50;
    }
}
