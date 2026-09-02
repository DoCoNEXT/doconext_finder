<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Example domain entity — a per-user "note". Delete this (and NoteMapper /
 * NoteService / NoteController / the migration) once you scaffold your own.
 *
 * DB columns are snake_case; the Entity maps them to camelCase properties
 * automatically (created_at → createdAt). Declare each property + its
 * @method hints so the magic getters/setters are typed.
 *
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Note extends Entity
{
    protected string $userId = '';
    protected string $title = '';
    protected string $content = '';
    protected string $createdAt = '';

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('title', 'string');
        $this->addType('content', 'string');
        $this->addType('createdAt', 'string');
    }

    /** camelCase array for API responses (API bodies are snake_case; returns are camelCase). */
    public function toArray(): array
    {
        return [
            'id'        => $this->getId(),
            'title'     => $this->getTitle(),
            'content'   => $this->getContent(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
