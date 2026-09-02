<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Db\Note;
use OCA\DcnFinder\Db\NoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Business logic for notes. The Service layer sits between the thin Controller
 * (HTTP) and the Mapper (DB): it validates, orchestrates, and returns plain
 * camelCase arrays. Controllers never touch the Mapper directly.
 */
class NoteService
{
    public function __construct(
        private NoteMapper $mapper,
        private ITimeFactory $time,
    ) {
    }

    /** @return array<int, array> */
    public function findAll(string $userId): array
    {
        return array_map(
            static fn (Note $n): array => $n->toArray(),
            $this->mapper->findAllForUser($userId),
        );
    }

    public function create(string $userId, string $title, string $content): array
    {
        $note = new Note();
        $note->setUserId($userId);
        $note->setTitle(trim($title));
        $note->setContent($content);
        $note->setCreatedAt($this->time->getDateTime()->format('Y-m-d H:i:s'));

        return $this->mapper->insert($note)->toArray();
    }

    /** @throws DoesNotExistException when the note doesn't exist for this user */
    public function delete(int $id, string $userId): void
    {
        $this->mapper->delete($this->mapper->findForUser($id, $userId));
    }
}
