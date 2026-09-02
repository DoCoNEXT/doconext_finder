<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the example dcn_finder_notes table.
 *
 * Naming: VersionXXXXXXDateYYYYMMDDHHMMSS. The version prefix must sort after
 * the previous migration; the date suffix keeps files unambiguous.
 *
 * IMPORTANT: index / FK / constraint names must be globally UNIQUE across the
 * whole Nextcloud schema (not just this table) — a duplicate name aborts every
 * app's enable/upgrade. Prefix them with the app's DB prefix.
 *
 * @psalm-suppress UnusedClass
 */
class Version000100Date20260101000000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('dcn_finder_notes')) {
            return null;
        }

        $table = $schema->createTable('dcn_finder_notes');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('content', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::STRING, ['notnull' => true, 'length' => 32]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'dcn_finder_notes_uid_idx');

        return $schema;
    }
}
