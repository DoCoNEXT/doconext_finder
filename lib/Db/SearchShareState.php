<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * What one recipient did with one share: hid it from their list, last ran it.
 *
 * Kept apart from the share because a group share reaches many people through
 * one row. A row exists only once there is something to keep.
 *
 * @method int getShareId()
 * @method void setShareId(int $shareId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getHidden()
 * @method void setHidden(int $hidden)
 * @method int getLastRun()
 * @method void setLastRun(int $lastRun)
 */
class SearchShareState extends Entity
{
    // 0 is safe as a default here, unlike SavedSearch's `kind`: a value left
    // out of the INSERT falls back to the column's own default, which is 0.
    protected int $shareId = 0;
    protected string $userId = '';
    protected int $hidden = 0;
    protected int $lastRun = 0;

    public function __construct()
    {
        $this->addType('shareId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('hidden', 'integer');
        $this->addType('lastRun', 'integer');
    }
}
