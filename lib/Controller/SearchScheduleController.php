<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\ScheduleService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The caller's own schedule on one saved search — theirs or one shared with
 * them. A schedule on a shared search reaches nobody else.
 *
 * @psalm-suppress UnusedClass
 */
class SearchScheduleController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ScheduleService $service,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Sets the schedule, or changes it.
     *
     * @param string $frequency daily, weekdays or weekly
     * @param int $weekday ISO day of the week for weekly, 1 = Monday
     * @param string $time HH:MM on the user's own clock
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/api/searches/{id}/schedule')]
    public function set(int $id, string $frequency = '', int $weekday = 0, string $time = ''): DataResponse
    {
        return $this->withUser(function (string $uid) use ($id, $frequency, $weekday, $time) {
            try {
                return new DataResponse($this->service->set($uid, $id, $frequency, $weekday, $time));
            } catch (DoesNotExistException) {
                return new DataResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
            } catch (\InvalidArgumentException $e) {
                return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
            } catch (\RuntimeException) {
                // Worded on the page, which knows the number; see SearchApi.ts.
                return new DataResponse([
                    'error'  => 'You can be notified about at most ' . ScheduleService::MAX_SCHEDULES . ' searches.',
                    'reason' => 'max_schedules',
                ], Http::STATUS_CONFLICT);
            }
        });
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/api/searches/{id}/schedule')]
    public function remove(int $id): DataResponse
    {
        return $this->withUser(function (string $uid) use ($id) {
            $this->service->remove($uid, $id);

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
