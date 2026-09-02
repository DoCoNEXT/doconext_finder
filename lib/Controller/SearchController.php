<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Service\FileSearchService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * File search over the user's own files.
 *
 * POST rather than GET because a search is a structured document (a condition
 * list), not a handful of scalars — the same reason WebDAV uses a SEARCH body.
 *
 * Routing: #[FrontpageRoute] → /apps/doconext_finder/… (NOT #[ApiRoute], which
 * is OCS). Declaration order is route registration order; keep literal paths
 * above parametric ones.
 *
 * @psalm-suppress UnusedClass
 */
class SearchController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private FileSearchService $service,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Describes what this server can be asked to filter on, so the UI builds its
     * field and operator menus from the backend rather than a hardcoded copy.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/search/fields')]
    public function fields(): DataResponse
    {
        return new DataResponse([
            'fields'    => FileCondition::FIELDS,
            'operators' => FileCondition::FIELD_OPERATORS,
            'sorts'     => FileQuery::SORTS,
            'maxLimit'  => FileQuery::MAX_LIMIT,
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/search')]
    public function search(): DataResponse
    {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $query = FileQuery::fromArray($this->request->getParams());
        } catch (\InvalidArgumentException $e) {
            // Validation messages are written for the caller and carry no internals.
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new DataResponse($this->service->search($uid, $query));
        } catch (\Throwable $e) {
            $this->logger->error('File search failed', ['exception' => $e, 'app' => $this->appName]);

            return new DataResponse(['error' => 'search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
