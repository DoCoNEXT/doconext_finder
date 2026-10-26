<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Listener;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Service\SearchShareService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes the shares of saved searches with a group that no longer exists.
 *
 * Harmless if left — a share is resolved against group membership when read,
 * and nobody is a member of a deleted group — until a new group is created
 * under the same id and inherits searches nobody shared with it.
 *
 * There is no counterpart for teams: OCP raises no event when one is deleted,
 * and a team's id is random, so it cannot be reused the same way.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener
{
    public function __construct(
        private SearchShareService $shares,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function handle(Event $event): void
    {
        if (!$event instanceof GroupDeletedEvent) {
            return;
        }

        try {
            $this->shares->forgetGroup($event->getGroup()->getGID());
        } catch (\Throwable $e) {
            // The group is already gone; see UserDeletedListener.
            $this->logger->error(AppConstants::LOG_PREFIX . ' Could not remove search shares for a deleted group', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);
        }
    }
}
