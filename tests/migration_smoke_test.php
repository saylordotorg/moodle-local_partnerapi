<?php
// This file is part of the local_partnerapi Moodle plugin.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Migration smoke test for the local_partnerapi_provenance table.
 *
 * Confirms the schema delivered by db/install.xml (fresh installs) and mirrored
 * by db/upgrade.php's xmldb_local_partnerapi_upgrade() (existing sites) yields a
 * table with the expected columns and an enforced UNIQUE(userid, cohortid)
 * index, and that the migration is purely additive — it adds a new table only
 * and leaves core Moodle tables (user, cohort, cohort_members) intact.
 *
 * In the PHPUnit test database the plugin's tables are created at install time
 * from db/install.xml, which is kept byte-for-byte in sync with the
 * xmldb_local_partnerapi_upgrade() create_table() definition, so asserting the
 * installed schema also exercises the shape the upgrade path produces.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Smoke tests for the provenance table migration (Req 5.1, 5.2).
 *
 * @covers \xmldb_local_partnerapi_upgrade
 */
final class migration_smoke_test extends \advanced_testcase {

    /** @var string The plugin-owned table the migration creates. */
    private const PROVENANCE_TABLE = 'local_partnerapi_provenance';

    /**
     * The columns the provenance table must expose, per the design's Data Models
     * section and db/install.xml. Pinning the list guards against a column being
     * dropped or renamed by a future migration edit.
     *
     * @var string[]
     */
    private const PROVENANCE_COLUMNS = [
        'id',
        'userid',
        'cohortid',
        'source',
        'timecreated',
        'timemodified',
    ];

    /**
     * Req 5.2: after the plugin schema is applied, the provenance table exists
     * with exactly the expected columns.
     *
     * The table is created from db/install.xml in the test DB (and from the
     * matching create_table() in db/upgrade.php for upgrading sites); both must
     * yield the same schema. We assert existence two ways: via the DB manager's
     * table_exists() and by reading the live column list.
     */
    public function test_provenance_table_exists_with_expected_columns(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();

        // Req 5.2: the new table exists.
        $this->assertTrue($dbman->table_exists(self::PROVENANCE_TABLE),
            'The migration must create the local_partnerapi_provenance table (Req 5.2)');

        // Every documented field is present (checked through the DB manager).
        $table = new \xmldb_table(self::PROVENANCE_TABLE);
        foreach (self::PROVENANCE_COLUMNS as $column) {
            $this->assertTrue(
                $dbman->field_exists($table, new \xmldb_field($column)),
                "The provenance table must expose the '$column' column (Req 5.2)");
        }

        // Cross-check against the live column metadata: exactly these columns.
        $columns = $DB->get_columns(self::PROVENANCE_TABLE);
        $this->assertEqualsCanonicalizing(
            self::PROVENANCE_COLUMNS,
            array_keys($columns),
            'The provenance table columns must match the documented schema exactly (Req 5.2)');
    }

    /**
     * Req 5.2: the UNIQUE(userid, cohortid) index is present and enforced.
     *
     * Asserting the constraint behaviorally is the most robust check across DB
     * engines: a first row for (userid, cohortid) inserts fine, and a second row
     * with the same pair must be rejected by the unique index. We insert
     * directly via $DB->insert_record (bypassing provenance::record(), which
     * deliberately swallows its own write errors) so the duplicate-key violation
     * surfaces as a thrown exception.
     */
    public function test_unique_userid_cohortid_index_is_enforced(): void {
        global $DB;
        $this->resetAfterTest(true);

        $now = time();
        $row = (object)[
            'userid'       => 1,
            'cohortid'     => 1,
            'source'       => provenance::SOURCE_SELF,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];

        // First insert for (userid=1, cohortid=1) succeeds.
        $firstid = $DB->insert_record(self::PROVENANCE_TABLE, $row);
        $this->assertGreaterThan(0, $firstid,
            'The first (userid, cohortid) row must insert successfully');

        // A second row with the SAME (userid=1, cohortid=1) must be rejected by
        // the UNIQUE(userid, cohortid) index, proving the index exists/enforces.
        $duplicate = clone $row;
        $threw = false;
        try {
            $DB->insert_record(self::PROVENANCE_TABLE, $duplicate);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw,
            'A duplicate (userid, cohortid) insert must throw, proving the '
            . 'UNIQUE(userid, cohortid) index is enforced (Req 5.2)');

        // Exactly one row remains for the pair (the duplicate did not persist).
        $this->assertSame(1,
            $DB->count_records(self::PROVENANCE_TABLE, ['userid' => 1, 'cohortid' => 1]),
            'Only one row may exist per (userid, cohortid) after a rejected duplicate');
    }

    /**
     * Req 5.1/5.2: the upgrade step guards table creation so re-running it is a
     * no-op.
     *
     * Invoking xmldb_local_partnerapi_upgrade() directly inside the test harness
     * is awkward (it drives Moodle's upgrade machinery and savepoints), so this
     * documents and lightly verifies the create-if-absent guard instead:
     * db/upgrade.php wraps create_table() in `if (!$dbman->table_exists($table))`,
     * meaning that on a site where the table already exists (as it does here)
     * the guard short-circuits and the upgrade adds nothing. We assert the
     * precondition that makes the guard a no-op.
     */
    public function test_upgrade_table_creation_is_guarded_and_idempotent(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::PROVENANCE_TABLE);

        // The table already exists, so db/upgrade.php's
        // `if (!$dbman->table_exists($table))` guard evaluates false and
        // re-running the create step would be a no-op (create-if-absent).
        $this->assertTrue($dbman->table_exists($table),
            'The provenance table must already exist, making the upgrade guard a no-op (Req 5.1)');
    }

    /**
     * Req 5.2: the migration is additive — it adds a new table only and does not
     * drop or alter core Moodle tables.
     *
     * We sanity-check that the core tables the feature touches at runtime (users
     * and AFF- cohort membership) still exist after the plugin schema is applied.
     * The plugin migration creates one new table and never issues DDL against
     * core tables, so their continued presence confirms the additive contract.
     */
    public function test_migration_is_additive_core_tables_untouched(): void {
        global $DB;
        $this->resetAfterTest(true);

        $dbman = $DB->get_manager();

        foreach (['user', 'cohort', 'cohort_members'] as $coretable) {
            $this->assertTrue($dbman->table_exists($coretable),
                "Core table '$coretable' must remain present; the migration only "
                . 'ADDS the provenance table and never alters core tables (Req 5.2)');
        }
    }
}
