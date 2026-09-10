<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Listener;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted account's saved and recent searches.
 *
 * Nextcloud clears what it stores on a user's behalf — preferences among them,
 * which is why {@see \OCA\DcnFinder\Service\PreferencesService} needs nothing
 * here — but an app's own table is the app's own responsibility. Without this,
 * The searches table keeps rows keyed to a user id that no longer exists:
 * invisible, never read, and still someone's search terms.
 *
 * Listens after the fact rather than before: a deletion that fails part-way
 * should not have taken the searches with it.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
    public function __construct(
        private SavedSearchMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }

        $userId = $event->getUser()->getUID();

        try {
            $removed = $this->mapper->deleteByUser($userId);
        } catch (\Throwable $e) {
            // The account is already gone; failing loudly here would only break
            // whatever else still has to run for this deletion.
            $this->logger->error(AppConstants::LOG_PREFIX . ' Could not remove searches for a deleted account', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return;
        }

        if ($removed > 0) {
            $this->logger->info(AppConstants::LOG_PREFIX . ' Removed searches for a deleted account', [
                'count' => $removed,
                'app'   => AppConstants::APP_ID,
            ]);
        }
    }
}
