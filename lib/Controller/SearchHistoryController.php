<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Service\SearchHistoryService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Saved searches and recents.
 *
 * Route declaration order is route registration order, so the literal
 * /api/searches/recent sits above the parametric /api/searches/{id}; otherwise
 * {id} swallows "recent".
 *
 * @psalm-suppress UnusedClass
 */
class SearchHistoryController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private SearchHistoryService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/searches')]
    public function index(): DataResponse
    {
        return $this->withUser(fn (string $uid) => new DataResponse([
            'saved'   => $this->service->list($uid, SavedSearch::KIND_SAVED),
            'recents' => $this->service->list($uid, SavedSearch::KIND_RECENT),
        ]));
    }

    /**
     * Records that a search ran.
     *
     * The client posts this rather than the search endpoint recording it, because
     * what has to be stored is the *interface* state that reproduces the search —
     * which preset was chosen, not the timestamp it resolved to. Storing the
     * resolved value would freeze "Last 7 days" to the week it was first run.
     *
     * @param array<string,mixed> $query
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/searches/recent')]
    public function recordRecent(array $query = []): DataResponse
    {
        return $this->withUser(function (string $uid) use ($query) {
            if ($query !== []) {
                $this->service->recordRecent($uid, $query);
            }

            return new DataResponse([], Http::STATUS_NO_CONTENT);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/searches/recent')]
    public function clearRecents(): DataResponse
    {
        return $this->withUser(function (string $uid) {
            $this->service->clearRecents($uid);

            return new DataResponse([], Http::STATUS_NO_CONTENT);
        });
    }

    /**
     * @param array<string,mixed> $query
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/searches')]
    public function create(string $name = '', string $description = '', array $query = []): DataResponse
    {
        if ($query === []) {
            return new DataResponse(['error' => 'query is required'], Http::STATUS_BAD_REQUEST);
        }

        return $this->withUser(function (string $uid) use ($name, $description, $query) {
            try {
                return new DataResponse($this->service->save($uid, $name, $description, $query));
            } catch (\RuntimeException $e) {
                // The ceiling on saved searches. Its message is written for the
                // person who hit it, so it passes through as-is.
                return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
            }
        });
    }

    /**
     * Declared before the bare `/{id}` PUT: the more specific path first, per
     * the app's routing convention.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/searches/{id}/query')]
    public function replaceQuery(int $id, array $query = []): DataResponse
    {
        if ($query === []) {
            return new DataResponse(['error' => 'query is required'], Http::STATUS_BAD_REQUEST);
        }

        return $this->withUser(function (string $uid) use ($id, $query) {
            try {
                return new DataResponse($this->service->replaceQuery($uid, $id, $query));
            } catch (DoesNotExistException) {
                return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
            }
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/searches/{id}')]
    public function rename(int $id, string $name = '', string $description = ''): DataResponse
    {
        return $this->withUser(function (string $uid) use ($id, $name, $description) {
            try {
                return new DataResponse($this->service->rename($uid, $id, $name, $description));
            } catch (DoesNotExistException) {
                return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
            }
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/searches/{id}/run')]
    public function touch(int $id): DataResponse
    {
        return $this->withUser(function (string $uid) use ($id) {
            try {
                $this->service->touch($uid, $id);
            } catch (DoesNotExistException) {
                return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
            }

            return new DataResponse([], Http::STATUS_NO_CONTENT);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/searches/{id}')]
    public function destroy(int $id): DataResponse
    {
        return $this->withUser(function (string $uid) use ($id) {
            try {
                $this->service->delete($uid, $id);
            } catch (DoesNotExistException) {
                return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
            }

            return new DataResponse([], Http::STATUS_NO_CONTENT);
        });
    }

    /** @param callable(string): DataResponse $handler */
    private function withUser(callable $handler): DataResponse
    {
        $uid = $this->userSession->getUser()?->getUID();

        return $uid === null
            ? new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED)
            : $handler($uid);
    }
}
