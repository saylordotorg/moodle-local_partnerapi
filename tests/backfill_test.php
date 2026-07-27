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
 * DB-backed tests for the idempotent provenance backfill.
 *
 * Exercises local_partnerapi_run_backfill(): existing AFF- cohort members
 * whose email domain matches the configured domain_cohort_map are recorded as
 * signup_partner_choice; non-matching members get no provenance row; re-running
 * the backfill is idempotent and never downgrades a stronger pre-existing
 * source.
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
 * Tests for local_partnerapi_run_backfill().
 *
 * @covers ::local_partnerapi_run_backfill
 */
final class backfill_test extends \advanced_testcase {
    /**
     * Seed one AFF- cohort, point domain_cohort_map at it for `acme.org`, and
     * add three members: a domain match, a case-insensitive domain match, and a
     * non-match. All three are members of the same AFF- cohort.
     *
     * @return array{
     *     affcohortid:int,
     *     usermatchid:int,
     *     usermatchcaseid:int,
     *     usernomatchid:int
     * } the seeded ids used across the assertions.
     */
    private function seed(): array {
        $generator = $this->getDataGenerator();

        // 1. An AFF- cohort and members. Configure the mapping only after the
        // users exist so the user_created observer cannot pre-populate the
        // provenance being tested by the backfill itself.
        $cohort = $generator->create_cohort(['idnumber' => 'AFF-ACME', 'name' => 'Acme']);
        $affcohortid = (int) $cohort->id;

        // 2. Members of the AFF- cohort.
        // Domain matches exactly.
        $usermatch = $generator->create_user(['email' => 'alice@acme.org']);
        // Domain matches case-insensitively (uppercased).
        $usermatchcase = $generator->create_user(['email' => 'bob@ACME.ORG']);
        // Domain does NOT match the mapping.
        $usernomatch = $generator->create_user(['email' => 'carol@other.com']);

        cohort_add_member($affcohortid, (int) $usermatch->id);
        cohort_add_member($affcohortid, (int) $usermatchcase->id);
        cohort_add_member($affcohortid, (int) $usernomatch->id);
        set_config('domain_cohort_map', json_encode(['acme.org' => $affcohortid]), 'local_partnerapi');

        return [
            'affcohortid'     => $affcohortid,
            'usermatchid'     => (int) $usermatch->id,
            'usermatchcaseid' => (int) $usermatchcase->id,
            'usernomatchid'   => (int) $usernomatch->id,
        ];
    }

    /**
     * Matching members get signup_partner_choice (case-insensitively); a member
     * whose email domain does not match the mapping gets no provenance row.
     *
     * Requirements: 3.2 (domain match -> signup_partner_choice),
     *               3.3 (undeterminable source -> no row).
     */
    public function test_backfill_records_matches_and_skips_nonmatches(): void {
        global $DB;
        $this->resetAfterTest(true);

        $ids = $this->seed();

        $count = local_partnerapi_run_backfill();

        // Req 3.2: exact-domain match recorded as signup_partner_choice.
        $this->assertSame(
            provenance::SOURCE_SIGNUP,
            provenance::get_source($ids['usermatchid'], $ids['affcohortid']),
            'A member whose email domain matches the mapping must be recorded as signup_partner_choice'
        );

        // Req 3.2: case-insensitive domain match recorded as signup_partner_choice.
        $this->assertSame(
            provenance::SOURCE_SIGNUP,
            provenance::get_source($ids['usermatchcaseid'], $ids['affcohortid']),
            'Domain matching must be case-insensitive (bob@ACME.ORG matches acme.org)'
        );

        // Req 3.3: non-matching member gets no provenance row at all.
        $this->assertNull(
            provenance::get_source($ids['usernomatchid'], $ids['affcohortid']),
            'A member whose email domain does not match must have no recorded source'
        );
        $this->assertFalse(
            $DB->record_exists('local_partnerapi_provenance', [
                'userid'   => $ids['usernomatchid'],
                'cohortid' => $ids['affcohortid'],
            ]),
            'No provenance row may exist for a non-matching (userid, cohortid) pair'
        );

        // Only the two matching members were recorded.
        $this->assertSame(2, $count, 'Backfill must report exactly the two matching members recorded');
        $this->assertSame(
            2,
            $DB->count_records('local_partnerapi_provenance'),
            'Exactly two provenance rows must exist after the backfill'
        );
    }

    /**
     * Re-running the backfill makes no change to existing provenance rows, and a
     * stronger pre-existing source is never downgraded by the signup backfill.
     *
     * Requirements: 3.4 (idempotent, no downgrade).
     */
    public function test_backfill_is_idempotent_and_never_downgrades(): void {
        global $DB;
        $this->resetAfterTest(true);

        $ids = $this->seed();

        // First run establishes the baseline rows.
        $firstcount = local_partnerapi_run_backfill();
        $this->assertSame(2, $firstcount);

        // Capture the full row set (id => source/timecreated/timemodified).
        $before = $DB->get_records(
            'local_partnerapi_provenance',
            null,
            'id ASC',
            'id, userid, cohortid, source, timecreated, timemodified'
        );

        // Req 3.4: a second run changes nothing — same rows, same values.
        local_partnerapi_run_backfill();
        $after = $DB->get_records(
            'local_partnerapi_provenance',
            null,
            'id ASC',
            'id, userid, cohortid, source, timecreated, timemodified'
        );

        $this->assertEquals(
            $before,
            $after,
            'Re-running the backfill must leave the provenance row set unchanged (idempotent)'
        );
        $this->assertSame(
            array_keys($before),
            array_keys($after),
            'Idempotent re-run must not insert, delete, or re-key any provenance row'
        );

        // No-downgrade: manually upgrade userMatch to the highest-precedence
        // source, then re-run the backfill (which only emits signup_partner_choice).
        provenance::record($ids['usermatchid'], $ids['affcohortid'], provenance::SOURCE_REGISTRATION);
        $this->assertSame(
            provenance::SOURCE_REGISTRATION,
            provenance::get_source($ids['usermatchid'], $ids['affcohortid']),
            'Precondition: the row was upgraded to partner_registration_link'
        );

        local_partnerapi_run_backfill();

        // Req 3.4: signup backfill must not downgrade the stronger stored value.
        $this->assertSame(
            provenance::SOURCE_REGISTRATION,
            provenance::get_source($ids['usermatchid'], $ids['affcohortid']),
            'The backfill must not downgrade a stronger pre-existing source (partner_registration_link)'
        );

        // The other matching member is still signup_partner_choice (unchanged).
        $this->assertSame(
            provenance::SOURCE_SIGNUP,
            provenance::get_source($ids['usermatchcaseid'], $ids['affcohortid']),
            'Other matching members remain signup_partner_choice across re-runs'
        );

        // Still exactly two rows; the non-match never gained a row.
        $this->assertSame(
            2,
            $DB->count_records('local_partnerapi_provenance'),
            'Row count must remain two across all re-runs'
        );
    }

    /**
     * An empty or invalid domain_cohort_map config records nothing and returns 0
     * — the backfill exits cleanly without creating provenance rows.
     *
     * Requirements: 3.3 (no determinable source -> no row); edge case from the
     * design's Error Handling table (config empty / invalid JSON).
     */
    public function test_backfill_empty_or_invalid_config_records_nothing(): void {
        global $DB;
        $this->resetAfterTest(true);

        // Seed members so there is data the backfill *could* act on if it ran.
        $this->seed();

        // Empty config: nothing to do.
        set_config('domain_cohort_map', '', 'local_partnerapi');
        $this->assertSame(
            0,
            local_partnerapi_run_backfill(),
            'Empty domain_cohort_map must record nothing'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_partnerapi_provenance'),
            'No provenance rows may be created when the config is empty'
        );

        // Invalid JSON: parsed defensively, nothing to do.
        set_config('domain_cohort_map', 'not json', 'local_partnerapi');
        $this->assertSame(
            0,
            local_partnerapi_run_backfill(),
            'Invalid (non-JSON) domain_cohort_map must record nothing'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_partnerapi_provenance'),
            'No provenance rows may be created when the config is invalid JSON'
        );
    }
}
