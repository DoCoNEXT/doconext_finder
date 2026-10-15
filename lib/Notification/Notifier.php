<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Notification;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Service\SearchShareService;
use OCA\DcnFinder\Service\ShareNotifications;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders "Alice shared the search Leases with you".
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
        if ($notification->getApp() !== AppConstants::APP_ID
            || $notification->getSubject() !== ShareNotifications::SUBJECT_SHARED) {
            throw new UnknownNotificationException();
        }

        $searchId = (int)$notification->getObjectId();
        try {
            $search = $this->shares->sharedSearch($notification->getUser(), $searchId);
        } catch (DoesNotExistException) {
            // Deleted, unshared, or the recipient left the group it came
            // through: nothing left to point at.
            throw new AlreadyProcessedException();
        }

        $l = $this->l10nFactory->get(AppConstants::APP_ID, $languageCode);
        $owner = $search->getUserId();
        $ownerName = $this->userManager->getDisplayName($owner) ?? $owner;
        $name = $search->getName() ?? '';

        $subject = $l->t('{user} shared the search {search} with you');
        $notification->setRichSubject($subject, [
            'user'   => ['type' => 'user', 'id' => $owner, 'name' => $ownerName],
            'search' => ['type' => 'highlight', 'id' => (string)$searchId, 'name' => $name],
        ]);
        $notification->setParsedSubject(str_replace(['{user}', '{search}'], [$ownerName, $name], $subject));
        // Opens the app on the search and runs it — see src/services/links.ts.
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
