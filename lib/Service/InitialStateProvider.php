<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;

/**
 * Emits the bootstrap config the frontend reads synchronously via loadState
 * (src/constants.ts) — no round-trip, no loading flicker.
 *
 * Call provide() from EVERY page/settings handler that loads one of the app's
 * bundles (PageController, AdminSettings, PersonalSettings); constants.ts fails
 * loudly if the state is missing.
 */
class InitialStateProvider
{
    public function __construct(
        private IInitialState $initialState,
        private IAppConfig $appConfig,
    ) {
    }

    public function provide(): void
    {
        $displayName = $this->appConfig->getValueString(AppConstants::APP_ID, AppConstants::DISPLAY_NAME_KEY, '');

        $this->initialState->provideInitialState('config', [
            'appId'       => AppConstants::APP_ID,
            'appName'     => AppConstants::DEFAULT_DISPLAY_NAME,
            'displayName' => $displayName,
        ]);
    }
}
