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
 * DB-backed wiring tests for affiliation-source provenance capture.
 *
 * Verifies that each affiliation write path records the source mandated by
 * design decision D3, and that provenance capture is strictly best-effort:
 * a failure inside provenance::record() never prevents the underlying
 * cohort_add_member() from succeeding (Req 5.3).
 *
 * Design mapping D3 (source per call site) asserted here:
 *   | Call site                              | Recorded source            |
 *   | v1/register/index.php                  | SOURCE_REGISTRATION        |
 *   | affiliation.php (join)                 | SOURCE_SELF                |
 *   | local_partnerapi_user_edit_form_save   | SOURCE_SELF                |
 *   | local_partnerapi_post_signup_requests  | SOURCE_SIGNUP              |
 *   | observer::auto_affiliate               | SOURCE_SIGNUP              |
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/partnerapi/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * DB-backed wiring tests for the five AFF- affiliation paths (design D3) plus
 * the best-effort failure contract (Req 5.3).
 *
 * @covers \local_partnerapi\provenance
 */
final class provenance_wiring_test extends \advanced_testcase {
    /**
     * Reset the DB after each test so dropped tables / inserted rows / config
     * never leak between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create a visible AFF- cohort via the data generator.
     *
     * @param string $idnumber Cohort idnumber; must start with the AFF- prefix.
     * @param string $name Human-readable cohort name.
     * @return \stdClass The created cohort record (has ->id).
     */
    private function make_aff_cohort(string $idnumber = 'AFF-TEST', string $name = 'Test Partner'): \stdClass {
        return $this->getDataGenerator()->create_cohort([
            'idnumber' => $idnumber,
            'name'     => $name,
            'visible'  => 1,
        ]);
    }

    /**
     * Req 1.4 — signup chooser path.
     *
     * local_partnerapi_post_signup_requests() is the post-signup hook fired with
     * the new user/form data object. Build a $data stdClass carrying the user id
     * and the chosen AFF- cohort id (the custom form field
     * local_partnerapi_affiliation), call the hook, and assert the recorded
     * source is SOURCE_SIGNUP per design mapping D3.
     */
    public function test_post_signup_requests_records_signup_partner_choice(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'signup@learner.test']);
        $cohort = $this->make_aff_cohort('AFF-SIGNUP', 'Signup Partner');

        $data = new \stdClass();
        $data->id = $user->id;
        $data->local_partnerapi_affiliation = $cohort->id;

        \local_partnerapi_post_signup_requests($data);

        // Membership was created and provenance recorded as signup_partner_choice.
        $this->assertTrue(
            $this->cohort_member_exists((int) $cohort->id, (int) $user->id),
            'post_signup_requests must add the user to the AFF- cohort'
        );
        $this->assertSame(
            provenance::SOURCE_SIGNUP,
            provenance::get_source((int) $user->id, (int) $cohort->id),
            'signup chooser path must record SOURCE_SIGNUP (D3)'
        );
    }

    /**
     * Req 1.3 — self-service user-edit save path.
     *
     * local_partnerapi_user_edit_form_save() persists the affiliation chosen on
     * the user edit form. Build $user and $usernew carrying the user id and the
     * selected AFF- cohort id (local_partnerapi_affiliations), call the hook, and
     * assert the recorded source is SOURCE_SELF per design mapping D3.
     */
    public function test_user_edit_form_save_records_self_affiliated(): void {
        $user = $this->getDataGenerator()->create_user(['email' => 'edit@learner.test']);
        $cohort = $this->make_aff_cohort('AFF-EDIT', 'Edit Partner');

        $userobj = new \stdClass();
        $userobj->id = $user->id;

        $usernew = new \stdClass();
        $usernew->id = $user->id;
        $usernew->local_partnerapi_affiliations = $cohort->id;

        $this->setUser($user);
        \local_partnerapi_user_edit_form_save($userobj, $usernew);

        $this->assertTrue(
            $this->cohort_member_exists((int) $cohort->id, (int) $user->id),
            'user_edit_form_save must add the user to the selected AFF- cohort'
        );
        $this->assertSame(
            provenance::SOURCE_SELF,
            provenance::get_source((int) $user->id, (int) $cohort->id),
            'user-edit save path must record SOURCE_SELF (D3)'
        );
    }

    /**
     * Req 1.4 — domain auto-affiliation path (observer).
     *
     * Configure a domain_cohort_map mapping example.org to an AFF- cohort, create
     * a user with an example.org email, then drive the observer via a properly
     * formed core user_created event (create_from_userid sets relateduserid, which
     * observer::user_created consumes). Assert the recorded source is SOURCE_SIGNUP
     * per design mapping D3.
     *
     * Reading classes/observer.php + db/events.php confirms the wiring:
     * db/events.php subscribes \core\event\user_created ->
     * \local_partnerapi\observer::user_created, which calls auto_affiliate(
     * $event->relateduserid) and, after cohort_add_member, records SOURCE_SIGNUP.
     */
    public function test_observer_auto_affiliate_records_signup_partner_choice(): void {
        $cohort = $this->make_aff_cohort('AFF-DOMAIN', 'Domain Partner');

        set_config(
            'domain_cohort_map',
            json_encode(['example.org' => (int) $cohort->id]),
            'local_partnerapi'
        );

        $user = $this->getDataGenerator()->create_user(['email' => 'newbie@example.org']);

        // Drive the observer exactly as the event system would. create_from_userid
        // builds a well-formed event whose relateduserid == the user id.
        $event = \core\event\user_created::create_from_userid($user->id);
        observer::user_created($event);

        $this->assertTrue(
            $this->cohort_member_exists((int) $cohort->id, (int) $user->id),
            'observer must auto-affiliate the matching-domain user to the AFF- cohort'
        );
        $this->assertSame(
            provenance::SOURCE_SIGNUP,
            provenance::get_source((int) $user->id, (int) $cohort->id),
            'observer auto-affiliate path must record SOURCE_SIGNUP (D3)'
        );
    }

    /**
     * Req 1.2 / 1.3 — web entry-point paths (register + self-service join).
     *
     * v1/register/index.php and affiliation.php are web entry-point scripts: they
     * include config.php and perform HTTP I/O / output, so they cannot be
     * require()'d inside a PHPUnit process. Per the design's testing strategy we
     * assert the D3 mapping at the unit-testable level — that the exact constants
     * those call sites pass to provenance::record() resolve and store correctly.
     *
     * The web call sites are (verified in source):
     *   - v1/register/index.php:  cohort_add_member($cohortid, $userid);
     *                             provenance::record($userid, $cohortid, SOURCE_REGISTRATION);
     *   - affiliation.php (join): cohort_add_member($cohort->id, $USER->id);
     *                             provenance::record((int)$USER->id, (int)$cohort->id, SOURCE_SELF);
     *
     * Full HTTP-level coverage of these scripts is a deployment smoke step
     * (.kiro/steering/deployment.md), not a PHPUnit concern.
     */
    public function test_register_and_self_service_join_constants_map(): void {
        // Registration form path -> partner_registration_link.
        $reguser = $this->getDataGenerator()->create_user(['email' => 'reg@learner.test']);
        $regcohort = $this->make_aff_cohort('AFF-REG', 'Register Partner');
        \cohort_add_member((int) $regcohort->id, (int) $reguser->id);
        provenance::record((int) $reguser->id, (int) $regcohort->id, provenance::SOURCE_REGISTRATION);

        $this->assertSame(
            provenance::SOURCE_REGISTRATION,
            provenance::get_source((int) $reguser->id, (int) $regcohort->id),
            'v1/register/index.php call site must record SOURCE_REGISTRATION (D3)'
        );

        // Self-service join (affiliation.php) path -> self_affiliated.
        $selfuser = $this->getDataGenerator()->create_user(['email' => 'self@learner.test']);
        $selfcohort = $this->make_aff_cohort('AFF-SELF', 'Self Partner');
        \cohort_add_member((int) $selfcohort->id, (int) $selfuser->id);
        provenance::record((int) $selfuser->id, (int) $selfcohort->id, provenance::SOURCE_SELF);

        $this->assertSame(
            provenance::SOURCE_SELF,
            provenance::get_source((int) $selfuser->id, (int) $selfcohort->id),
            'affiliation.php (join) call site must record SOURCE_SELF (D3)'
        );
    }

    /**
     * Req 5.3 — best-effort: a failure inside provenance::record() must never
     * prevent the underlying affiliation from succeeding, and must not propagate.
     *
     * Approach (documented): drop the provenance table so any DB access inside
     * record() throws. record() wraps its body in try/catch(\Throwable) and logs
     * via debugging(), so it returns normally. We drive the real signup-chooser
     * path (local_partnerapi_post_signup_requests), which calls cohort_add_member()
     * BEFORE record(); we then assert (a) no exception propagated and (b) the
     * cohort membership was still created. The table is recreated in a finally
     * block because Moodle's transaction reset cannot roll back DDL.
     */
    public function test_affiliation_completes_when_provenance_recording_fails(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['email' => 'beste@learner.test']);
        $cohort = $this->make_aff_cohort('AFF-BESTEFFORT', 'Best Effort Partner');

        // Force record() to fail internally by removing the provenance table.
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_partnerapi_provenance');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $this->assertFalse($dbman->table_exists($table), 'provenance table should be dropped for this test');

        $data = new \stdClass();
        $data->id = $user->id;
        $data->local_partnerapi_affiliation = $cohort->id;

        try {
            // Must NOT throw even though provenance recording will fail internally.
            \local_partnerapi_post_signup_requests($data);

            // Consume the debugging message emitted by record()'s catch block.
            $this->assertDebuggingCalled();

            // The affiliation itself still completed (best-effort contract, Req 5.3).
            $this->assertTrue(
                $DB->record_exists('cohort_members', [
                    'cohortid' => (int) $cohort->id,
                    'userid' => (int) $user->id,
                ]),
                'cohort membership must be created even when provenance recording fails'
            );
        } finally {
            self::restore_provenance_table($dbman);
        }
    }

    /**
     * Restore the plugin table after a deliberate DDL failure test.
     *
     * @param \database_manager $dbman Moodle database manager.
     * @return void
     */
    private static function restore_provenance_table(\database_manager $dbman): void {
        $table = new \xmldb_table('local_partnerapi_provenance');
        if ($dbman->table_exists($table)) {
            return;
        }
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('cohortid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('source', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('useridcohortid', XMLDB_INDEX_UNIQUE, ['userid', 'cohortid']);
        $dbman->create_table($table);
    }

    /**
     * Helper: does an AFF- cohort membership exist for (cohortid, userid)?
     *
     * @param int $cohortid
     * @param int $userid
     * @return bool
     */
    private function cohort_member_exists(int $cohortid, int $userid): bool {
        global $DB;
        return $DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid]);
    }
}
