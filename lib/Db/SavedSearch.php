<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A stored search: either one the user named and kept ("saved") or one captured
 * automatically after it ran ("recent").
 *
 * DB columns are snake_case; Entity maps them to camelCase properties
 * (last_run → lastRun).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getQuery()
 * @method void setQuery(string $query)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method int getLastRun()
 * @method void setLastRun(int $lastRun)
 */
class SavedSearch extends Entity
{
    public const KIND_SAVED = 'saved';
    public const KIND_RECENT = 'recent';

    /**
     * Declared defaults must NOT collide with values we actually write.
     *
     * QBMapper only includes fields the Entity marked as updated, and the magic
     * setter marks nothing when the assigned value equals what is already there.
     * With `kind` defaulted to KIND_SAVED, setKind(KIND_SAVED) was a no-op and the
     * column was left out of the INSERT entirely — the database then rejected the
     * row for having no value for a NOT NULL column. Keep these neutral.
     */
    protected string $userId = '';
    protected string $kind = '';
    protected ?string $name = null;
    protected ?string $description = null;
    protected string $query = '';
    protected string $fingerprint = '';
    protected int $lastRun = 0;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('kind', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('query', 'string');
        $this->addType('fingerprint', 'string');
        $this->addType('lastRun', 'integer');
    }

    /** @return array<string,mixed> camelCase, as the API returns it. */
    public function toArray(): array
    {
        return [
            'id'          => $this->getId(),
            'kind'        => $this->getKind(),
            'name'        => $this->getName(),
            'description' => $this->getDescription(),
            // Stored as JSON text; handed back as a structure the client can re-run.
            'query'       => json_decode($this->getQuery(), true) ?: new \stdClass(),
            'lastRun'     => $this->getLastRun(),
        ];
    }
}
