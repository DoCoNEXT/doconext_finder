<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Migration;

use Closure;
use OCA\DcnFinder\Db\DbConstants;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sharing saved searches with users, groups and teams.
 *
 * Two tables rather than one. A share says who may reach a search, and how;
 * the state says what one recipient did with it — hid it from their own list,
 * last ran it. A group share has many recipients and one row, so what each of
 * them did cannot live on the share.
 *
 * The state is typed columns, not key-value pairs, because both values are
 * queried: `hidden` filters the list and `last_run` orders it. That is the
 * opposite of the stored query, which is JSON precisely because nothing ever
 * filters on it.
 *
 * Index names come from DbConstants::DB_TABLE_PREFIX: they must be unique across
 * the whole Nextcloud schema, and stay under Oracle's 30 characters.
 *
 * @psalm-suppress UnusedClass
 */
class Version000300Date20261015120000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable(DbConstants::DB_TABLENAME_SHARES)) {
            $table = $schema->createTable(DbConstants::DB_TABLENAME_SHARES);
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('search_id', Types::BIGINT, ['notnull' => true]);
            // 'user', 'group' or 'circle' (a Nextcloud Team).
            $table->addColumn('share_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            // A team's id is longer than a user's or a group's 64.
            $table->addColumn('share_with', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Bitmask; see SearchShare::PERMISSION_*.
            $table->addColumn('permissions', Types::INTEGER, ['notnull' => true, 'default' => 1]);
            // Always the owner for now. Stored from the start so that resharing,
            // when it comes, needs no migration to say who passed a search on.
            $table->addColumn('shared_by', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            // One share per recipient per search; sharing again updates it.
            $table->addUniqueIndex(['search_id', 'share_type', 'share_with'], DbConstants::DB_TABLE_PREFIX . 'shr_uniq');
            // "What was shared with me" — looked up by recipient.
            $table->addIndex(['share_type', 'share_with'], DbConstants::DB_TABLE_PREFIX . 'shr_rcp_idx');
        }

        if (!$schema->hasTable(DbConstants::DB_TABLENAME_SHARE_STATE)) {
            $table = $schema->createTable(DbConstants::DB_TABLENAME_SHARE_STATE);
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('share_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // Not BOOLEAN: Nextcloud cannot make a boolean column NOT NULL.
            $table->addColumn('hidden', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('last_run', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['share_id', 'user_id'], DbConstants::DB_TABLE_PREFIX . 'shst_uniq');
            // Account deletion clears a user's state.
            $table->addIndex(['user_id'], DbConstants::DB_TABLE_PREFIX . 'shst_user_idx');
        }

        return $schema;
    }
}
