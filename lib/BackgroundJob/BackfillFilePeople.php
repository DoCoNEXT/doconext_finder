<?php

declare(strict_types=1);

namespace OCA\DcnFinder\BackgroundJob;

use OCA\DcnFinder\Service\FilePeopleBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Makes existing files findable under "Created by" and "Modified by", a minute
 * of work per run, once an admin has started it on the settings page.
 *
 * Not time-sensitive, so a server with a maintenance window keeps this work to
 * that window. Until started, while paused and once done, a run only reads that
 * there is nothing to do: new files and writes are recorded by the listener.
 *
 * @psalm-suppress UnusedClass  registered in appinfo/info.xml
 */
class BackfillFilePeople extends TimedJob
{
    private const SECONDS_PER_RUN = 60;

    public function __construct(
        ITimeFactory $time,
        private FilePeopleBackfill $backfill,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    #[\Override]
    protected function run($argument): void
    {
        $until = $this->time->getTime() + self::SECONDS_PER_RUN;

        // Asked again each round: pausing, or the occ command, stops this at once.
        while ($this->backfill->isRunning() && $this->backfill->advance() && $this->time->getTime() < $until) {
            // Each step saves where it got to, so stopping anywhere is safe.
        }
    }
}
