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
        } catch (\InvalidArgumentException $e) {
            // The query builder rejects some operator/field pairings we cannot detect
            // up front. Report its own words rather than a blank failure.
            $this->logger->warning('File search rejected', ['exception' => $e, 'app' => $this->appName]);

            return new DataResponse(
                ['error' => $this->explain($e->getMessage())],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error('File search failed', ['exception' => $e, 'app' => $this->appName]);

            return new DataResponse(['error' => 'search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Turns a query-builder rejection into something the user can act on.
     *
     * The Group folders app filters search results by ACL with
     * `NOT(path IN <forbidden paths>)`. Core's SplitLargeIn optimizer rewrites an
     * IN of more than 1000 values into `OR(IN, IN, …)`, and SearchBuilder refuses a
     * binary operator inside a NOT — so for an account with that many ACL-denied
     * paths, *every* file search fails, including the Files app's own. Nothing the
     * client sends changes this, so say so rather than implying a bad query.
     */
    private function explain(string $message): string
    {
        if (str_contains($message, 'Binary operators inside "not"')) {
            return 'This account cannot be searched on this server: the Group folders app '
                . 'builds an access filter that the search backend rejects when an account '
                . 'has more than 1000 access-denied paths. File search fails the same way in '
                . 'the Files app for this account. Try an account with fewer group folders.';
        }

        return $message;
    }
}
