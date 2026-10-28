<?php

declare(strict_types=1);

namespace OCA\DcnFinder\BackgroundJob;

use OCA\DcnFinder\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Runs the scheduled searches whose moment has come.
 *
 * Every quarter of an hour, so "08:00" is answered by a quarter past at the
 * latest — on an instance whose background jobs run from system cron. Under
 * AJAX mode it is as punctual as the visitors.
 *
 * Time-sensitive on purpose: an insensitive job may be held back to the
 * maintenance window, and a morning summary that arrives at three at night is
 * not one.
 *
 * Due schedules are taken on in batches; what one pass leaves is first in line
 * for the next, since the oldest due is taken first.
 *
 * @psalm-suppress UnusedClass  registered in appinfo/info.xml
 */
class RunScheduledSearches extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private ScheduleService $schedules,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    #[\Override]
    protected function run($argument): void
    {
        $this->schedules->runDue($this->time->getTime());
    }
}
