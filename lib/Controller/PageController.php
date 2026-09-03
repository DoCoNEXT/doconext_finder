<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Service\InitialStateProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the app's main page (mounts src/main.ts into templates/index.php).
 *
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller
{
    /** Optional companion app providing rich file previews. */
    private const PREVIEW_APP_ID = 'doconext_files_preview';

    public function __construct(
        string $appName,
        IRequest $request,
        private InitialStateProvider $initialState,
        private IAppManager $appManager,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    #[FrontpageRoute(verb: 'GET', url: '/')]
    public function index(): TemplateResponse
    {
        // Util::addScript/addStyle auto-prefix "<appId>/js/" and "<appId>/css/" —
        // never include js/ or css/ here.
        //
        // The stylesheet is NOT optional: without it the page renders with no app
        // styles at all (no content container, so the Nextcloud background shows
        // through). Both Settings classes already add theirs; the main page was
        // the one place the template omitted it.
        Util::addScript(AppConstants::APP_ID, AppConstants::APP_ID . '-main');
        Util::addStyle(AppConstants::APP_ID, AppConstants::APP_ID . '-main');

        // Rich previews are a progressive enhancement. When the Files Preview app
        // is installed we load its bundle, which registers a <doconext-file-preview>
        // custom element; without it the details panel falls back to Nextcloud's
        // own thumbnail. Only the app id is referenced — no classes, no dependency,
        // so Finder still installs and runs on its own.
        if ($this->appManager->isEnabledForUser(self::PREVIEW_APP_ID)) {
            Util::addScript(self::PREVIEW_APP_ID, self::PREVIEW_APP_ID . '-main');
            Util::addStyle(self::PREVIEW_APP_ID, self::PREVIEW_APP_ID . '-main');
        }
        $this->initialState->provide();

        return new TemplateResponse(AppConstants::APP_ID, 'index');
    }
}
