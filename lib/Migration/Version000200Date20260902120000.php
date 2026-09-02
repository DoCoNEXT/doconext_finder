<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Replaces the scaffolding's example table with the searches table.
 *
 * One table holds both saved searches and recents: they carry the same payload
 * and differ only in whether the user named and kept one. Splitting them would
 * duplicate the schema and the mapper for no gain.
 *
 * The query itself is stored as JSON rather than as columns. It is a nested,
 * evolving document (a condition list, presets, sort), it is only ever read
 * back whole to re-run it, and it is never filtered on — so columns would buy
 * nothing and turn every filter addition into a migration.
 *
 * IMPORTANT: index names must be globally unique across the whole Nextcloud
 * schema, not just this table — hence the app prefix.
 *
 * @psalm-suppress UnusedClass
 */
class Version000200Date20260902120000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // The example table the app template ships; nothing referenced it.
        if ($schema->hasTable('dcn_finder_notes')) {
            $schema->dropTable('dcn_finder_notes');
        }

        if (!$schema->hasTable('dcn_finder_searches')) {
            $table = $schema->createTable('dcn_finder_searches');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // 'saved' (named, kept) or 'recent' (auto-captured, capped).
            $table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('description', Types::STRING, ['notnull' => false, 'length' => 1024]);
            // The whole query as JSON; see the class docblock.
            $table->addColumn('query', Types::TEXT, ['notnull' => true]);
            // Identity of the query, for de-duplicating recents without parsing JSON.
            $table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('last_run', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            // Every read is "this user's searches of this kind, newest first".
            $table->addIndex(['user_id', 'kind', 'last_run'], 'dcn_finder_srch_ukl_idx');
            $table->addIndex(['user_id', 'kind', 'fingerprint'], 'dcn_finder_srch_ukf_idx');
        }

        return $schema;
    }
}
