<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Migration;

use Closure;
use OCA\DcnFinder\Search\PersonField;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Registers the indexed metadata keys behind "Created by" and "Modified by".
 *
 * Nextcloud refuses a search on a key it does not know as indexed, and otherwise
 * learns a key only from the first file saved with it.
 *
 * @psalm-suppress UnusedClass
 */
class Version000500Date20261118120000 extends SimpleMigrationStep
{
    public function __construct(
        private IFilesMetadataManager $metadata,
    ) {
    }

    #[\Override]
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        foreach (PersonField::METADATA_KEYS as $key) {
            $this->metadata->initMetadata(
                $key,
                IMetadataValueWrapper::TYPE_STRING,
                true,
                IMetadataValueWrapper::EDIT_FORBIDDEN,
            );
        }
    }
}
