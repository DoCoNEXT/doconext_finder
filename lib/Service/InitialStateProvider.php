<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\App\IAppManager;
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
    /** Optional companion app providing rich file previews. */
    private const PREVIEW_APP_ID = 'doconext_files_preview';

    public function __construct(
        private IInitialState $initialState,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private BridgeSettings $bridgeSettings,
        private CoreScope $coreScope,
        private FileTypeFilterSettings $fileTypeFilterSettings,
        private ContentSearchService $contentSearch,
    ) {
    }

    public function provide(): void
    {
        $displayName = $this->appConfig->getValueString(AppConstants::APP_ID, AppConstants::DISPLAY_NAME_KEY, '');

        $this->initialState->provideInitialState('config', [
            'appId'       => AppConstants::APP_ID,
            'appName'     => AppConstants::DEFAULT_DISPLAY_NAME,
            'displayName' => $displayName,
            // Whether the page loaded the Files Preview bundle, so the frontend
            // knows to wait for its custom element instead of guessing.
            'richPreview' => $this->appManager->isEnabledForUser(self::PREVIEW_APP_ID),
            // Which files go to DoCoNEXT Bridge instead of the desktop client. The
            // rule is the server's so every frontend answers the same way.
            'bridgeExtensions' => $this->bridgeSettings->extensions(),
            // The search page's "Type" filter categories, admin-configured order
            // and selection. Builtin labels/mimetypes still come from the
            // frontend registry; this only says which ids to show and in what
            // order, plus any admin-authored custom categories.
            'fileTypeFilters' => $this->fileTypeFilterSettings->list(),
            // Whether DoCoNEXT Core can name entity types and entities to narrow
            // a search by. The folder level of the scope picker never depends on
            // this. Read synchronously so the fields are either there from the
            // first paint or not at all — a control that appears a beat later
            // reads as a glitch.
            'coreScope' => $this->coreScope->hasEntityVocabulary(),
            // Whether this server has a full-text index, and so whether the
            // search box can offer to look inside files rather than only at
            // their names. Synchronous for the same reason as coreScope: the
            // choice belongs in the first paint or not at all.
            'contentSearch' => $this->contentSearch->isAvailable(),
        ]);
    }
}
