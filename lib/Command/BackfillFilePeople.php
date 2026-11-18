<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Command;

use OCA\DcnFinder\Service\FilePeopleBackfill;
use OCA\DcnFinder\Service\FilePeopleSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills in "Created by" and "Modified by" for existing files in one go, for an
 * instance where the background job's minute per run would take weeks.
 *
 * Starts where the settings page or an earlier run stopped, and can be stopped
 * at any moment: each batch saves its position.
 *
 *   occ dcn-finder:people:backfill
 *   occ dcn-finder:people:backfill --max-minutes=480
 *   occ dcn-finder:people:backfill --status
 *
 * @psalm-api Registered as an occ command in appinfo/info.xml.
 */
class BackfillFilePeople extends Command
{
    public function __construct(
        private FilePeopleBackfill $backfill,
        private FilePeopleSettings $settings,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('dcn-finder:people:backfill')
            ->setDescription('Fill in Created by and Modified by for existing files')
            ->addOption('max-minutes', null, InputOption::VALUE_REQUIRED, 'Stop after this many minutes; run it again to continue')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Only show how far it has got');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('status') === true) {
            $this->report($output);

            return self::SUCCESS;
        }

        if (!$this->settings->isEnabled()) {
            $output->writeln('<error>Created by and Modified by are turned off. Turn them on in the Finder admin settings first.</error>');

            return self::FAILURE;
        }

        $minutes = $input->getOption('max-minutes');
        if ($minutes !== null && (!is_numeric($minutes) || (int)$minutes < 1)) {
            $output->writeln('<error>--max-minutes must be a whole number of at least 1</error>');

            return self::INVALID;
        }

        $finished = $this->backfill->runInForeground(
            $minutes === null ? null : 60 * (int)$minutes,
            fn () => $this->report($output),
        );

        $this->report($output);
        $output->writeln($finished ? 'Finished.' : 'Stopped. Run this again to continue.');

        return self::SUCCESS;
    }

    private function report(OutputInterface $output): void
    {
        $status = $this->backfill->status();
        if (!$this->settings->isEnabled()) {
            $output->writeln('turned off');

            return;
        }

        $output->writeln($status['state'] . ': ' . $status['percent'] . '%');
    }
}
