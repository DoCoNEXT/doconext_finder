<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\NoteService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin HTTP layer for notes — validates presence, delegates to NoteService,
 * returns DataResponse. Extends ApiController so CORS preflight is handled.
 *
 * Routing: #[FrontpageRoute] → /apps/<appId>/…  (NOT #[ApiRoute], which is OCS).
 * Request bodies are snake_case; responses are camelCase (see Note::toArray()).
 *
 * @psalm-suppress UnusedClass
 */
class NoteController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private NoteService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/notes')]
    public function index(): DataResponse
    {
        return new DataResponse($this->service->findAll($this->uid()));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/notes')]
    public function create(string $title = '', string $content = ''): DataResponse
    {
        if (trim($title) === '') {
            return new DataResponse(['error' => 'title is required'], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse($this->service->create($this->uid(), $title, $content));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/notes/{id}')]
    public function destroy(int $id): DataResponse
    {
        try {
            $this->service->delete($id, $this->uid());
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse([], Http::STATUS_NO_CONTENT);
    }

    private function uid(): string
    {
        return $this->userSession->getUser()?->getUID() ?? '';
    }
}
