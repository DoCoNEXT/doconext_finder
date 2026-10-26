<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Tells someone a search was shared with them, and takes that back once it no
 * longer holds.
 *
 * Only for a share with one person. A group or team share would notify every
 * member at once — a burst nobody asked for, and for a large group a real
 * load — while the search is waiting in their list either way.
 *
 * Best-effort throughout: a notification that cannot be sent must never undo
 * or fail the share it is about.
 */
class ShareNotifications
{
    public const SUBJECT_SHARED = 'search_shared';
    public const OBJECT_TYPE = 'search';

    public function __construct(
        private INotificationManager $manager,
        private LoggerInterface $logger,
    ) {
    }

    public function shared(string $owner, string $recipient, int $searchId): void
    {
        try {
            $notification = $this->about($searchId)
                ->setUser($recipient)
                ->setDateTime(new \DateTime())
                ->setSubject(self::SUBJECT_SHARED, ['sharedBy' => $owner]);
            $this->manager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Could not notify about a shared search', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);
        }
    }

    /**
     * Removes the notification about a search — for one recipient, or for
     * everyone when the search itself is gone.
     */
    public function withdraw(int $searchId, ?string $recipient = null): void
    {
        try {
            $notification = $this->about($searchId);
            if ($recipient !== null) {
                $notification->setUser($recipient);
            }
            $this->manager->markProcessed($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Could not withdraw a search notification', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);
        }
    }

    private function about(int $searchId): INotification
    {
        return $this->manager->createNotification()
            ->setApp(AppConstants::APP_ID)
            ->setObject(self::OBJECT_TYPE, (string)$searchId);
    }
}
