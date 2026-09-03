<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\PreferencesService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user interface preferences (grid columns, grouping).
 *
 * @psalm-suppress UnusedClass
 */
class PreferencesController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private PreferencesService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/preferences')]
    public function show(): DataResponse
    {
        $uid = $this->userSession->getUser()?->getUID();

        return $uid === null
            ? new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED)
            : new DataResponse($this->service->get($uid));
    }

    /**
     * Returns what was actually stored, not what was sent: unknown columns are
     * dropped and missing ones appended, so the client adopts the server's view
     * instead of holding a copy that quietly disagrees.
     *
     * @param list<array<string,mixed>> $columns
     * @param list<string> $grouping ordered grouping levels for the search results
     * @param list<string> $favoritesGrouping the same, for the favorites list
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/preferences')]
    public function update(
        array $columns = [],
        array $grouping = [],
        array $favoritesGrouping = [],
        int $pageSize = 50,
        string $sort = 'mtime',
        bool $descending = true,
        string $doubleClick = 'open',
        bool $sidebarPinned = false,
    ): DataResponse {
        $uid = $this->userSession->getUser()?->getUID();

        return $uid === null
            ? new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED)
            : new DataResponse($this->service->set($uid, [
                'columns'           => $columns,
                'grouping'          => $grouping,
                'favoritesGrouping' => $favoritesGrouping,
                'pageSize'          => $pageSize,
                'sort'              => $sort,
                'descending'        => $descending,
                'doubleClick'       => $doubleClick,
                'sidebarPinned'     => $sidebarPinned,
            ]));
    }
}
