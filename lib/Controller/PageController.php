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
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the app's main page (mounts src/main.ts into templates/index.php).
 *
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private InitialStateProvider $initialState,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    #[OpenAPI(OpenAPI::SCOPE_IGNORE)]
    #[FrontpageRoute(verb: 'GET', url: '/')]
    public function index(): TemplateResponse
    {
        // Util::addScript auto-prefixes "<appId>/js/" — never include js/ here.
        Util::addScript(AppConstants::APP_ID, AppConstants::APP_ID . '-main');
        $this->initialState->provide();

        return new TemplateResponse(AppConstants::APP_ID, 'index');
    }
}
