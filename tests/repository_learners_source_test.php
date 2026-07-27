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
 * DB-backed tests for the affiliation_source field of the learners response.
 *
 * Exercises repository::get_learners() against a real (test) Moodle database to
 * confirm it surfaces the provenance-derived affiliation_source value, returns
 * null when no provenance row exists, resolves the highest-precedence value
 * across multiple AFF- cohorts, and keeps the learner-object shape additive.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Tests that get_learners() exposes affiliation_source from provenance rows.
 *
 * @covers \local_partnerapi\repository
 */
final class repository_learners_source_test extends \advanced_testcase {
    /**
     * The full set of keys every learner object in the response must carry.
     * Pinning this list guards Req 2.4 / 5.4: the change is additive only, so no
     * prior key may be removed or renamed and affiliation_source must be present.
     *
     * @var string[]
     */
    private const LEARNER_KEYS = [
        'id',
        'firstname',
        'lastname',
        'email',
        'lastaccess',
        'cohort_ids',
        'affiliation_join_at',
        'affiliation_source',
    ];

    /**
     * Create an AFF- cohort (idnumber starting with the affiliation prefix) and
     * return its id. The AFF- prefix is what marks a cohort as an affiliation
     * cohort for both get_learners() and provenance::sources_for_users().
     *
     * @param string $suffix Unique suffix appended to the AFF- idnumber.
     * @return int The new cohort id.
     */
    private function create_aff_cohort(string $suffix): int {
        $cohort = $this->getDataGenerator()->create_cohort([
            'name'     => 'Affiliation ' . $suffix,
            'idnumber' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . $suffix,
        ]);
        return (int)$cohort->id;
    }

    /**
     * Locate a single learner row in the get_learners() result by user id.
     *
     * @param array $learners The get_learners() result.
     * @param int $userid The user id to find.
     * @return array|null The matching learner array, or null when absent.
     */
    private function find_learner(array $learners, int $userid): ?array {
        foreach ($learners as $learner) {
            if ((int)$learner['id'] === $userid) {
                return $learner;
            }
        }
        return null;
    }

    /**
     * A seeded provenance row surfaces as the learner's affiliation_source.
     *
     * Req 2.1: the Learners_API includes affiliation_source drawn from the
     * learner's provenance record for their AFF- cohort membership.
     */
    public function test_seeded_provenance_row_is_returned_as_affiliation_source(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $cohortid = $this->create_aff_cohort('SEEDED');
        cohort_add_member($cohortid, $user->id);

        // Seed provenance through the same recorder the write paths use.
        provenance::record((int)$user->id, $cohortid, provenance::SOURCE_REGISTRATION);

        $learners = repository::get_learners([$cohortid]);
        $learner = $this->find_learner($learners, (int)$user->id);

        $this->assertNotNull($learner, 'The seeded learner must appear in the response');
        $this->assertSame(
            provenance::SOURCE_REGISTRATION,
            $learner['affiliation_source'],
            'affiliation_source must equal the stored provenance value (Req 2.1)'
        );
    }

    /**
     * A learner with no provenance row reports a null affiliation_source.
     *
     * Req 2.2: when a learner has no provenance record, the Learners_API returns
     * affiliation_source as null.
     */
    public function test_learner_without_provenance_row_has_null_source(): void {
        $this->resetAfterTest(true);

        $cohortid = $this->create_aff_cohort('NOROW');

        // One member with provenance, one without, in the same AFF- cohort.
        $seeded = $this->getDataGenerator()->create_user();
        $bare = $this->getDataGenerator()->create_user();
        cohort_add_member($cohortid, $seeded->id);
        cohort_add_member($cohortid, $bare->id);
        provenance::record((int)$seeded->id, $cohortid, provenance::SOURCE_SELF);

        $learners = repository::get_learners([$cohortid]);
        $barelearner = $this->find_learner($learners, (int)$bare->id);

        $this->assertNotNull($barelearner, 'The member with no provenance must still appear');
        $this->assertNull(
            $barelearner['affiliation_source'],
            'affiliation_source must be null when no provenance row exists (Req 2.2)'
        );
    }

    /**
     * When a learner has provenance rows for multiple AFF- cohorts, the resolved
     * affiliation_source is the highest-precedence value.
     *
     * Req 2.3 (supportive): the resolved value never downgrades; registration
     * (rank 3) wins over self (rank 1).
     */
    public function test_highest_precedence_source_wins_across_multiple_cohorts(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $selfcohort = $this->create_aff_cohort('SELF');
        $regcohort = $this->create_aff_cohort('REG');

        cohort_add_member($selfcohort, $user->id);
        cohort_add_member($regcohort, $user->id);

        // Seed a weaker source on one cohort and a stronger one on the other.
        provenance::record((int)$user->id, $selfcohort, provenance::SOURCE_SELF);
        provenance::record((int)$user->id, $regcohort, provenance::SOURCE_REGISTRATION);

        $learners = repository::get_learners([$selfcohort, $regcohort]);
        $learner = $this->find_learner($learners, (int)$user->id);

        $this->assertNotNull($learner, 'The learner must appear in the response');
        $this->assertSame(
            provenance::SOURCE_REGISTRATION,
            $learner['affiliation_source'],
            'The highest-precedence source must win across AFF- cohorts (Req 2.3)'
        );
    }

    /**
     * The learner object keeps every prior key and adds affiliation_source.
     *
     * Req 2.4 / 5.4: the change is additive — no field removed or renamed.
     */
    public function test_learner_object_shape_is_additive(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $cohortid = $this->create_aff_cohort('SHAPE');
        cohort_add_member($cohortid, $user->id);
        provenance::record((int)$user->id, $cohortid, provenance::SOURCE_SIGNUP);

        $learners = repository::get_learners([$cohortid]);
        $this->assertNotEmpty($learners, 'The response must contain the member');

        foreach ($learners as $learner) {
            // Every expected key must be present (additive shape).
            foreach (self::LEARNER_KEYS as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $learner,
                    "Learner object must retain the '$key' key (Req 2.4/5.4)"
                );
            }
            // No unexpected keys were introduced beyond the documented contract.
            $this->assertEqualsCanonicalizing(
                self::LEARNER_KEYS,
                array_keys($learner),
                'Learner object keys must match the documented additive contract exactly'
            );
        }
    }
}
