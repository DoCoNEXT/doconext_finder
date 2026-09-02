<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\FileSearchService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Toggling a file's favorite flag.
 *
 * Nextcloud has no OCS/REST favorites route — the Files app drives this through
 * WebDAV PROPPATCH. Going through \OCP\ITagManager directly keeps the frontend
 * on one API instead of two.
 *
 * @psalm-suppress UnusedClass
 */
class FavoriteController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private FileSearchService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/files/{fileId}/favorite')]
    public function add(int $fileId): DataResponse
    {
        return $this->set($fileId, true);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/files/{fileId}/favorite')]
    public function remove(int $fileId): DataResponse
    {
        return $this->set($fileId, false);
    }

    private function set(int $fileId, bool $favorite): DataResponse
    {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->service->setFavorite($uid, $fileId, $favorite);
        } catch (NotFoundException) {
            return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse(['favorite' => $favorite]);
    }
}
