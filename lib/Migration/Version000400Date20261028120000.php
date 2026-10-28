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
 * Scheduled searches: "tell me every weekday at eight what is new in this".
 *
 * One row per search per user, rather than a column on the search or on the
 * share state. The search row is the owner's, and a schedule on a shared search
 * belongs to the recipient who set it; the share state exists only for shares.
 * One table covers the owner's own search and a recipient's alike.
 *
 * Typed columns, like the share state, because the background job queries
 * `next_run` and the lists read the rest per row.
 *
 * Index names come from DbConstants::DB_TABLE_PREFIX: they must be unique across
 * the whole Nextcloud schema, and stay under Oracle's 30 characters.
 *
 * @psalm-suppress UnusedClass
 */
class Version000400Date20261028120000 extends SimpleMigrationStep
{
    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable(DbConstants::DB_TABLENAME_SCHEDULES)) {
            $table = $schema->createTable(DbConstants::DB_TABLENAME_SCHEDULES);
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('search_id', Types::BIGINT, ['notnull' => true]);
            // Whose schedule it is, and so whose permissions the search runs with.
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            // 'daily', 'weekdays' or 'weekly'; see SearchSchedule::FREQUENCIES.
            $table->addColumn('frequency', Types::STRING, ['notnull' => true, 'length' => 16]);
            // ISO day of the week, 1 = Monday, for 'weekly'; 0 otherwise.
            $table->addColumn('weekday', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // HH:MM on the user's own clock, not UTC: eight o'clock stays eight
            // o'clock across a change of daylight saving time.
            $table->addColumn('time', Types::STRING, ['notnull' => true, 'length' => 5]);
            // The next moment it is due, in Unix seconds — what the job asks for.
            $table->addColumn('next_run', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // What counts as new: changed after the user last looked.
            $table->addColumn('seen_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('last_run', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // How many the last run found since seen_at; 0 once the user looked.
            $table->addColumn('unseen', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            // One schedule per user per search; setting it again changes it.
            $table->addUniqueIndex(['search_id', 'user_id'], DbConstants::DB_TABLE_PREFIX . 'sch_uniq');
            // The background job's one question: what is due.
            $table->addIndex(['next_run'], DbConstants::DB_TABLE_PREFIX . 'sch_next_idx');
            // A user's lists, and account deletion.
            $table->addIndex(['user_id'], DbConstants::DB_TABLE_PREFIX . 'sch_user_idx');
        }

        return $schema;
    }
}
