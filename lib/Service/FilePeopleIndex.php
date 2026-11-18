<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Search\PersonField;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use Psr\Log\LoggerInterface;

/**
 * Writes who made and who last changed a file into the metadata the search
 * backend indexes. See {@see PersonField} for why it lives there.
 */
class FilePeopleIndex
{
    /** @var array<int, array<string, string>> file id => our values, while it moves */
    private array $moving = [];

    public function __construct(
        private IFilesMetadataManager $metadata,
        private LoggerInterface $logger,
    ) {
    }

    /** This account wrote the file just now. */
    public function recordEditor(int $fileId, string $uid): void
    {
        $metadata = $this->metadata->getMetadata($fileId, true);
        $metadata->setString(PersonField::MODIFIED_BY_KEY, PersonField::token($uid), true);
        $this->metadata->saveMetadata($metadata);
    }

    /** This account made the file. A file has one maker, so the first one stays. */
    public function recordCreator(int $fileId, string $uid): void
    {
        $metadata = $this->metadata->getMetadata($fileId, true);
        if (!$metadata->hasKey(PersonField::CREATED_BY_KEY)) {
            $this->setCreator($metadata, $uid);
            $this->metadata->saveMetadata($metadata);
        }
    }

    /**
     * Someone unknown wrote the file, so whoever is on record no longer is.
     *
     * Emptied rather than removed: dropping a key leaves its row in the index
     * table, and the file would go on being found under the previous name.
     */
    public function clearEditor(int $fileId): void
    {
        try {
            $metadata = $this->metadata->getMetadata($fileId);
        } catch (FilesMetadataNotFoundException) {
            return;
        }

        if ($metadata->hasKey(PersonField::MODIFIED_BY_KEY)) {
            $metadata->setString(PersonField::MODIFIED_BY_KEY, '', true);
            $this->metadata->saveMetadata($metadata);
        }
    }

    /**
     * A file is about to move. Between two storages it keeps its id but Nextcloud
     * deletes its metadata, which would make its maker unknown for good.
     */
    public function rememberBeforeMove(int $fileId): void
    {
        try {
            $metadata = $this->metadata->getMetadata($fileId);
        } catch (FilesMetadataNotFoundException) {
            return;
        }

        foreach (PersonField::INTERNAL_KEYS as $key) {
            if ($metadata->hasKey($key)) {
                $this->moving[$fileId][$key] = $metadata->getString($key);
            }
        }
    }

    /** Puts back what the move took. After a move within a storage nothing is gone. */
    public function restoreAfterMove(int $fileId): void
    {
        $remembered = $this->moving[$fileId] ?? [];
        unset($this->moving[$fileId]);
        if ($remembered === []) {
            return;
        }

        $metadata = $this->metadata->getMetadata($fileId, true);
        foreach ($remembered as $key => $value) {
            if (!$metadata->hasKey($key)) {
                $metadata->setString($key, $value, $key !== PersonField::CREATOR_ID_KEY);
            }
        }
        $this->metadata->saveMetadata($metadata);
    }

    /**
     * Records these editors, except on files that already have one: what was
     * written as it happened is newer than anything a backfill can know.
     *
     * @param array<int, string> $uids file id => account id
     */
    public function recordMissingEditors(array $uids): void
    {
        $this->recordMissing($uids, PersonField::MODIFIED_BY_KEY, static function (IFilesMetadata $metadata, string $uid): void {
            $metadata->setString(PersonField::MODIFIED_BY_KEY, PersonField::token($uid), true);
        });
    }

    /** @param array<int, string> $uids file id => account id */
    public function recordMissingCreators(array $uids): void
    {
        $this->recordMissing($uids, PersonField::CREATED_BY_KEY, $this->setCreator(...));
    }

    private function setCreator(IFilesMetadata $metadata, string $uid): void
    {
        $metadata->setString(PersonField::CREATED_BY_KEY, PersonField::token($uid), true);
        $metadata->setString(PersonField::CREATOR_ID_KEY, $uid);
    }

    /**
     * One file that cannot be saved does not stop the others.
     *
     * @param array<int, string> $uids
     * @param callable(IFilesMetadata, string): void $set
     */
    private function recordMissing(array $uids, string $key, callable $set): void
    {
        if ($uids === []) {
            return;
        }

        $known = $this->metadata->getMetadataForFiles(array_keys($uids));
        foreach ($uids as $fileId => $uid) {
            $metadata = $known[$fileId] ?? $this->metadata->getMetadata($fileId, true);
            if ($metadata->hasKey($key)) {
                continue;
            }

            try {
                $set($metadata, $uid);
                $this->metadata->saveMetadata($metadata);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not record who made or changed a file', [
                    'fileId' => $fileId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
