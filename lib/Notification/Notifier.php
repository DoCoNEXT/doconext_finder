<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Notification;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Service\ScheduleNotifications;
use OCA\DcnFinder\Service\ScheduleService;
use OCA\DcnFinder\Service\SearchShareService;
use OCA\DcnFinder\Service\ShareNotifications;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders "Alice shared the search Quotes this year with you", and "12 new or
 * changed files in Dossiers since yesterday, 08:00".
 *
 * Rendered when it is shown, not when it was sent, so it always names the
 * search as it is called now — and a search that has since been deleted or
 * unshared makes the notification go away instead of linking to nothing.
 *
 * The one place Finder translates in PHP: a notification is put into words by
 * the server, in the recipient's language, before any page of Finder's is open.
 *
 * @psalm-suppress UnusedClass  registered in Application::register()
 */
class Notifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IAppConfig $appConfig,
        private SearchShareService $shares,
        private ScheduleService $schedules,
        private IDateTimeFormatter $dateTimeFormatter,
    ) {
    }

    #[\Override]
    public function getID(): string
    {
        return AppConstants::APP_ID;
    }

    #[\Override]
    public function getName(): string
    {
        $name = $this->appConfig->getValueString(AppConstants::APP_ID, AppConstants::DISPLAY_NAME_KEY, '');

        return $name !== '' ? $name : AppConstants::DEFAULT_DISPLAY_NAME;
    }

    #[\Override]
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== AppConstants::APP_ID) {
            throw new UnknownNotificationException();
        }

        $l = $this->l10nFactory->get(AppConstants::APP_ID, $languageCode);

        return match ($notification->getSubject()) {
            ShareNotifications::SUBJECT_SHARED => $this->shared($notification, $l),
            ScheduleNotifications::SUBJECT_CHANGES => $this->changes($notification, $l),
            default => throw new UnknownNotificationException(),
        };
    }

    private function shared(INotification $notification, IL10N $l): INotification
    {
        $searchId = (int)$notification->getObjectId();
        try {
            $search = $this->shares->sharedSearch($notification->getUser(), $searchId);
        } catch (DoesNotExistException) {
            // Deleted, unshared, or the recipient left the group it came
            // through: nothing left to point at.
            throw new AlreadyProcessedException();
        }

        $owner = $search->getUserId();
        $ownerName = $this->userManager->getDisplayName($owner) ?? $owner;
        $name = $search->getName() ?? '';

        $subject = $l->t('{user} shared the search {search} with you');
        $notification->setRichSubject($subject, [
            'user'   => ['type' => 'user', 'id' => $owner, 'name' => $ownerName],
            'search' => ['type' => 'highlight', 'id' => (string)$searchId, 'name' => $name],
        ]);
        $notification->setParsedSubject(str_replace(['{user}', '{search}'], [$ownerName, $name], $subject));

        return $this->linked($notification, $searchId);
    }

    /**
     * How many files a scheduled search found, never which: the line goes to a
     * phone and into reminder mails, and a file name is often the sensitive
     * part. The search's own name does go — the user chose it, and the
     * schedule dialog says it will.
     */
    private function changes(INotification $notification, IL10N $l): INotification
    {
        $found = $this->schedules->describable($notification->getUser(), (int)$notification->getObjectId());
        if ($found === null) {
            // Seen, unscheduled, or the search is no longer theirs.
            throw new AlreadyProcessedException();
        }
        ['schedule' => $schedule, 'search' => $search, 'zone' => $zone] = $found;

        $since = $this->dateTimeFormatter->formatDateTimeRelativeDay($schedule->getSeenAt(), 'long', 'short', $zone, $l);
        $count = $schedule->getUnseen();
        $subject = $count > ScheduleService::COUNT_LIMIT
            ? $l->t('More than {count} new or changed files in {search} since {since}')
            : $l->n('%n new or changed file in {search} since {since}', '%n new or changed files in {search} since {since}', $count);
        $subject = str_replace(['{count}', '{since}'], [(string)ScheduleService::COUNT_LIMIT, $since], $subject);
        $name = $search->getName() ?? '';

        $notification->setRichSubject($subject, [
            'search' => ['type' => 'highlight', 'id' => (string)$search->getId(), 'name' => $name],
        ]);
        $notification->setParsedSubject(str_replace('{search}', $name, $subject));

        return $this->linked($notification, $search->getId());
    }

    /**
     * Opens the app on the search and runs it — see src/services/links.ts. The
     * page itself works out whether to show only what is new.
     */
    private function linked(INotification $notification, int $searchId): INotification
    {
        $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
            AppConstants::APP_ID . '.page.index',
            ['search' => $searchId],
        ));
        $notification->setIcon($this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(AppConstants::APP_ID, 'app-dark.svg'),
        ));

        return $notification;
    }
}
