<?php

declare(strict_types=1);

namespace OCA\DcnFinder\BackgroundJob;

use OCA\DcnFinder\Service\FilePeopleBackfill;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Names the maker of the files in a copied folder.
 *
 * Nextcloud announces the copy of the folder and not of what is in it. A folder
 * can hold any number of files, so this is not done inside the request that
 * copied it.
 */
class RecordCopiedFolder extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private FilePeopleBackfill $backfill,
    ) {
        parent::__construct($time);
    }

    /** @param array{folderId: int, uid: string} $argument */
    #[\Override]
    protected function run($argument): void
    {
        $this->backfill->copiedFolder($argument['folderId'], $argument['uid']);
    }
}
