<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One saved search shared with one user, group or team.
 *
 * The share points at the search; it is not a copy of it. Everyone it reaches
 * runs the same row, so an edit made with the edit permission is seen by all.
 *
 * @method int getSearchId()
 * @method void setSearchId(int $searchId)
 * @method string getShareType()
 * @method void setShareType(string $shareType)
 * @method string getShareWith()
 * @method void setShareWith(string $shareWith)
 * @method int getPermissions()
 * @method void setPermissions(int $permissions)
 * @method string getSharedBy()
 * @method void setSharedBy(string $sharedBy)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class SearchShare extends Entity
{
    public const TYPE_USER = 'user';
    public const TYPE_GROUP = 'group';
    /** A Nextcloud Team — the Circles app, which still names it so. */
    public const TYPE_CIRCLE = 'circle';

    public const TYPES = [self::TYPE_USER, self::TYPE_GROUP, self::TYPE_CIRCLE];

    /** See and run. Every share carries it. */
    public const PERMISSION_READ = 1;
    /** Rename, change the description, replace the query. */
    public const PERMISSION_UPDATE = 2;

    /**
     * Every bit a share may carry today. Deleting is deliberately not one: a
     * recipient removes a search from their own list, only the owner removes
     * it for everyone. Resharing will be the next bit.
     */
    public const PERMISSION_ALL = self::PERMISSION_READ | self::PERMISSION_UPDATE;

    // Neutral defaults, for the reason SavedSearch explains.
    protected int $searchId = 0;
    protected string $shareType = '';
    protected string $shareWith = '';
    protected int $permissions = 0;
    protected string $sharedBy = '';
    protected int $created = 0;

    public function __construct()
    {
        $this->addType('searchId', 'integer');
        $this->addType('shareType', 'string');
        $this->addType('shareWith', 'string');
        $this->addType('permissions', 'integer');
        $this->addType('sharedBy', 'string');
        $this->addType('created', 'integer');
    }

    public function can(int $permission): bool
    {
        return ($this->getPermissions() & $permission) === $permission;
    }
}
