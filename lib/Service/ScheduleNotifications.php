<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Tells someone a scheduled search found something new, and takes that back
 * once they have looked.
 *
 * One notification per schedule. A run that finds more replaces it rather than
 * adding a second, so a weekend of daily runs leaves one line, not three. What
 * it says is worked out when it is shown (see Notifier), from the schedule as
 * it is then — this only says *that* there is something.
 *
 * Best-effort throughout, as ShareNotifications is: a notification that cannot
 * be sent must not fail the run or the click it is about.
 */
class ScheduleNotifications
{
    public const SUBJECT_CHANGES = 'search_changes';
    public const OBJECT_TYPE = 'schedule';

    public function __construct(
        private INotificationManager $manager,
        private LoggerInterface $logger,
    ) {
    }

    public function changed(string $userId, int $scheduleId): void
    {
        try {
            $this->manager->markProcessed($this->about($scheduleId)->setUser($userId));
            $notification = $this->about($scheduleId)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setSubject(self::SUBJECT_CHANGES);
            $this->manager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Could not notify about a scheduled search', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);
        }
    }

    public function withdraw(int $scheduleId): void
    {
        try {
            $this->manager->markProcessed($this->about($scheduleId));
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Could not withdraw a scheduled search notification', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);
        }
    }

    private function about(int $scheduleId): INotification
    {
        return $this->manager->createNotification()
            ->setApp(AppConstants::APP_ID)
            ->setObject(self::OBJECT_TYPE, (string)$scheduleId);
    }
}
