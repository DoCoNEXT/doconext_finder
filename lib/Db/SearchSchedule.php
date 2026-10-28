<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * When one user wants to hear what is new in one saved search — their own, or
 * one shared with them. A schedule on a shared search is the recipient's alone.
 *
 * @method int getSearchId()
 * @method void setSearchId(int $searchId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFrequency()
 * @method void setFrequency(string $frequency)
 * @method int getWeekday()
 * @method void setWeekday(int $weekday)
 * @method string getTime()
 * @method void setTime(string $time)
 * @method int getNextRun()
 * @method void setNextRun(int $nextRun)
 * @method int getSeenAt()
 * @method void setSeenAt(int $seenAt)
 * @method int getLastRun()
 * @method void setLastRun(int $lastRun)
 * @method int getUnseen()
 * @method void setUnseen(int $unseen)
 * @method int getCreated()
 * @method void setCreated(int $created)
 */
class SearchSchedule extends Entity
{
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKDAYS = 'weekdays';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCIES = [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKDAYS, self::FREQUENCY_WEEKLY];

    // Neutral defaults, for the reason SavedSearch gives: a setter that assigns
    // the default marks nothing, and the column is then left out of the INSERT.
    protected int $searchId = 0;
    protected string $userId = '';
    protected string $frequency = '';
    protected int $weekday = 0;
    protected string $time = '';
    protected int $nextRun = 0;
    protected int $seenAt = 0;
    protected int $lastRun = 0;
    protected int $unseen = 0;
    protected int $created = 0;

    public function __construct()
    {
        $this->addType('searchId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('frequency', 'string');
        $this->addType('weekday', 'integer');
        $this->addType('time', 'string');
        $this->addType('nextRun', 'integer');
        $this->addType('seenAt', 'integer');
        $this->addType('lastRun', 'integer');
        $this->addType('unseen', 'integer');
        $this->addType('created', 'integer');
    }

    /** @return array<string,mixed> camelCase, as the API returns it. */
    public function toArray(): array
    {
        return [
            'frequency' => $this->getFrequency(),
            'weekday'   => $this->getWeekday(),
            'time'      => $this->getTime(),
            'nextRun'   => $this->getNextRun(),
            'seenAt'    => $this->getSeenAt(),
            'unseen'    => $this->getUnseen(),
        ];
    }
}
