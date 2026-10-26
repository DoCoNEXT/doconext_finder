<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Db\SearchShare;
use OCA\DcnFinder\Service\SearchShareService;
use OCA\DcnFinder\Service\ShareRecipients;
use OCA\DcnFinder\Service\ShareRefusedException;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Sharing saved searches: who to share with, the shares of one search, and a
 * recipient hiding what was shared with them.
 *
 * The list of searches shared with the user comes with the rest of their
 * searches, from GET /api/searches.
 *
 * @psalm-suppress UnusedClass
 */
class SearchShareController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private SearchShareService $service,
        private ShareRecipients $recipients,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /** Users, groups and teams matching a typed name, for the share dialog. */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/sharees')]
    public function sharees(string $search = ''): DataResponse
    {
        return $this->withUser(fn () => new DataResponse(['sharees' => $this->recipients->search($search)]));
    }

    /**
     * Whether the user may share with this recipient — the same check sharing
     * makes, asked before there is a search to share. The save dialog asks it
     * when someone is picked, so a refusal shows up then, not after saving.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/sharees/check')]
    public function check(string $type = '', string $with = ''): DataResponse
    {
        return $this->withUser(function (string $uid) use ($type, $with) {
            $reason = $this->recipients->refusal($uid, $type, trim($with));

            return new DataResponse($reason === null
                ? ['allowed' => true]
                : ['allowed' => false, 'reason' => $reason, 'error' => ShareRecipients::REFUSALS[$reason]]);
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/searches/{id}/shares')]
    public function index(int $id): DataResponse
    {
        return $this->withUser(fn (string $uid) => $this->answer(
            fn () => ['shares' => $this->service->sharesOf($uid, $id)],
        ));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/searches/{id}/shares')]
    public function create(int $id, string $type = '', string $with = '', bool $can_edit = false): DataResponse
    {
        return $this->withUser(fn (string $uid) => $this->answer(
            fn () => $this->service->share($uid, $id, $type, $with, self::permissions($can_edit)),
        ));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/searches/{id}/shares/{shareId}')]
    public function update(int $id, int $shareId, bool $can_edit = false): DataResponse
    {
        return $this->withUser(fn (string $uid) => $this->answer(
            fn () => $this->service->updatePermissions($uid, $id, $shareId, self::permissions($can_edit)),
        ));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/searches/{id}/shares/{shareId}')]
    public function destroy(int $id, int $shareId): DataResponse
    {
        return $this->withUser(fn (string $uid) => $this->answer(function () use ($uid, $id, $shareId) {
            $this->service->unshare($uid, $id, $shareId);

            return null;
        }));
    }

    /**
     * Removes a search shared with the user from their own list. The share
     * itself stays — for everyone else it reaches, and for the owner.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/searches/{id}/hide')]
    public function hide(int $id): DataResponse
    {
        return $this->withUser(fn (string $uid) => $this->answer(function () use ($uid, $id) {
            $this->service->hide($uid, $id);

            return null;
        }));
    }

    private static function permissions(bool $canEdit): int
    {
        return SearchShare::PERMISSION_READ | ($canEdit ? SearchShare::PERMISSION_UPDATE : 0);
    }

    /**
     * Turns the service's three ways of saying no into status codes. A refusal
     * from the sharing rules carries its reason code, which the page translates.
     *
     * @param callable(): mixed $action null for "done, nothing to return"
     */
    private function answer(callable $action): DataResponse
    {
        try {
            $result = $action();
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (ShareRefusedException $e) {
            return new DataResponse(['error' => $e->getMessage(), 'reason' => $e->reason], Http::STATUS_FORBIDDEN);
        }

        return $result === null
            ? new DataResponse([], Http::STATUS_NO_CONTENT)
            : new DataResponse($result);
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
