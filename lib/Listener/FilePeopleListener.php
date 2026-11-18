<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Listener;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\BackgroundJob\RecordCopiedFolder;
use OCA\DcnFinder\Search\FileOwner;
use OCA\DcnFinder\Service\FilePeopleIndex;
use OCA\DcnFinder\Service\FilePeopleSettings;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Keeps "Created by" and "Modified by" searchable: a new file says who made it,
 * a copy counts as new, every write says who made it, and a move keeps both.
 *
 * With nobody signed in, as on a public upload link, it falls back the way the
 * columns do: to the owner where the storage knows one, and otherwise to no name.
 *
 * Never lets a failure through. This runs inside someone's upload, and a search
 * field is not worth losing a file over.
 *
 * @template-implements IEventListener<NodeWrittenEvent|NodeCreatedEvent|NodeCopiedEvent|BeforeNodeRenamedEvent|NodeRenamedEvent>
 */
class FilePeopleListener implements IEventListener
{
    public function __construct(
        private FilePeopleIndex $index,
        private FilePeopleSettings $settings,
        private IUserSession $userSession,
        private IJobList $jobs,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function handle(Event $event): void
    {
        try {
            if (!$this->settings->isEnabled()) {
                return;
            }

            match (true) {
                $event instanceof NodeWrittenEvent => $this->written($event->getNode()),
                $event instanceof NodeCreatedEvent => $this->created($event->getNode()),
                $event instanceof NodeCopiedEvent => $this->copied($event->getTarget()),
                $event instanceof BeforeNodeRenamedEvent => $this->aboutToMove($event->getSource()),
                $event instanceof NodeRenamedEvent => $this->moved($event->getTarget()),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record who made or changed a file', [
                'app' => AppConstants::APP_ID,
                'exception' => $e,
            ]);
        }
    }

    private function written(Node $node): void
    {
        if (!$node instanceof File) {
            return;
        }

        $person = $this->personBehind($node);
        if ($person !== '') {
            $this->index->recordEditor($node->getId(), $person);
        } else {
            $this->index->clearEditor($node->getId());
        }
    }

    private function created(Node $node): void
    {
        $person = $this->personBehind($node);
        if ($node instanceof File && $person !== '') {
            $this->index->recordCreator($node->getId(), $person);
        }
    }

    /** A copied file is a new file. A copied folder brings files nobody announces. */
    private function copied(Node $node): void
    {
        $this->created($node);

        $person = $this->personBehind($node);
        if ($node instanceof Folder && $person !== '') {
            $this->jobs->add(RecordCopiedFolder::class, ['folderId' => $node->getId(), 'uid' => $person]);
        }
    }

    private function aboutToMove(Node $node): void
    {
        if ($node instanceof File && $node->getId() > 0) {
            $this->index->rememberBeforeMove($node->getId());
        }
    }

    private function moved(Node $node): void
    {
        if ($node instanceof File) {
            $this->index->restoreAfterMove($node->getId());
        }
    }

    private function personBehind(Node $node): string
    {
        return $this->userSession->getUser()?->getUID() ?? FileOwner::of(
            $node->getMountPoint()->getMountType(),
            $node->getOwner()?->getUID() ?? '',
        );
    }
}
