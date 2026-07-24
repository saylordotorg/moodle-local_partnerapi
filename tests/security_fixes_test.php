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
 * Regression tests for the security-review fixes.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/local/partnerapi/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Security-review regression coverage.
 *
 * @covers \local_partnerapi\observer
 * @covers \local_partnerapi\repository
 * @covers ::local_partnerapi_can_edit_affiliation
 * @covers ::local_partnerapi_get_self_service_affiliation
 */
final class security_fixes_test extends \advanced_testcase {
    /**
     * Create an affiliation cohort.
     *
     * @param string $suffix Unique idnumber suffix.
     * @param array<string, mixed> $overrides Cohort field overrides.
     * @return stdClass Created cohort.
     */
    private function create_affiliation(string $suffix, array $overrides = []): \stdClass {
        return $this->getDataGenerator()->create_cohort(array_merge([
            'name' => 'Affiliation ' . $suffix,
            'idnumber' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . $suffix,
            'visible' => 1,
        ], $overrides));
    }

    /**
     * Hidden cohorts are not valid self-service targets.
     *
     * @return void
     */
    public function test_hidden_affiliation_is_not_self_service_eligible(): void {
        $this->resetAfterTest(true);
        $visible = $this->create_affiliation('VISIBLE');
        $hidden = $this->create_affiliation('HIDDEN', ['visible' => 0]);

        $this->assertNotNull(local_partnerapi_get_self_service_affiliation((int) $visible->id));
        $this->assertNull(local_partnerapi_get_self_service_affiliation((int) $hidden->id));
    }

    /**
     * Domain affiliation is constrained to the registration client scope.
     *
     * @return void
     */
    public function test_registration_domain_mapping_cannot_cross_client_scope(): void {
        global $DB;

        $this->resetAfterTest(true);
        $partnera = $this->create_affiliation('A');
        $partnerb = $this->create_affiliation('B');
        $user = $this->getDataGenerator()->create_user(['email' => 'learner@partner-b.example']);
        set_config(
            'domain_cohort_map',
            json_encode(['partner-b.example' => (int) $partnerb->id]),
            'local_partnerapi'
        );

        observer::begin_registration_scope([(int) $partnera->id]);
        try {
            $event = \core\event\user_created::create_from_userid((int) $user->id);
            observer::user_created($event);
        } finally {
            observer::end_registration_scope();
        }

        $this->assertFalse($DB->record_exists('cohort_members', [
            'cohortid' => $partnerb->id,
            'userid' => $user->id,
        ]));

        cohort_add_member((int) $partnera->id, (int) $user->id);
        observer::user_created($event);
        $this->assertFalse($DB->record_exists('cohort_members', [
            'cohortid' => $partnerb->id,
            'userid' => $user->id,
        ]));
    }

    /**
     * Editing another user's affiliation requires cohort assignment rights.
     *
     * @return void
     */
    public function test_profile_edit_affiliation_permission_is_enforced(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->setUser($user);
        $this->assertTrue(local_partnerapi_can_edit_affiliation((int) $user->id));
        $this->assertFalse(local_partnerapi_can_edit_affiliation((int) $other->id));

        $this->setAdminUser();
        $this->assertTrue(local_partnerapi_can_edit_affiliation((int) $other->id));
    }

    /**
     * Hidden item and per-user grades are excluded from partner exports.
     *
     * @return void
     */
    public function test_hidden_grades_are_not_exported(): void {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $visibleitem = new \grade_item((object) [
            'courseid' => $course->id,
            'itemtype' => 'manual',
            'itemname' => 'Released grade',
            'grademax' => 100,
        ], false);
        $visibleitem->insert();
        $visiblegrade = new \grade_grade((object) [
            'itemid' => $visibleitem->id,
            'userid' => $user->id,
            'finalgrade' => 90,
        ], false);
        $visiblegrade->insert();

        $hiddenitem = new \grade_item((object) [
            'courseid' => $course->id,
            'itemtype' => 'manual',
            'itemname' => 'Hidden item',
            'grademax' => 100,
            'hidden' => 1,
        ], false);
        $hiddenitem->insert();
        $hiddengrade = new \grade_grade((object) [
            'itemid' => $hiddenitem->id,
            'userid' => $user->id,
            'finalgrade' => 80,
        ], false);
        $hiddengrade->insert();

        $userhiddenitem = new \grade_item((object) [
            'courseid' => $course->id,
            'itemtype' => 'manual',
            'itemname' => 'User hidden grade',
            'grademax' => 100,
        ], false);
        $userhiddenitem->insert();
        $userhiddengrade = new \grade_grade((object) [
            'itemid' => $userhiddenitem->id,
            'userid' => $user->id,
            'finalgrade' => 70,
            'hidden' => 1,
        ], false);
        $userhiddengrade->insert();

        $grades = repository::get_grades([(int) $user->id]);
        $this->assertCount(1, $grades);
        $this->assertSame('Released grade', $grades[0]['item_name']);
        $this->assertSame(90.0, $grades[0]['grade']);
        $this->assertTrue($DB->record_exists('grade_grades', ['id' => $hiddengrade->id]));
    }

    /**
     * Quiz attempts are withheld until the configured review phase.
     *
     * @return void
     */
    public function test_quiz_review_timing_is_respected(): void {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
            'timeclose' => time() + HOURSECS,
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
        ]);
        quiz_add_quiz_question((int) $question->id, $quiz);
        $attempt = $quizgenerator->create_attempt((int) $quiz->id, (int) $user->id);
        $DB->set_field('quiz', 'sumgrades', 10, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'grade', 100, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewattempt', 0x00010, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewmarks', 0x00010, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewmaxmarks', 0x00010, ['id' => $quiz->id]);
        $DB->set_field('quiz_attempts', 'state', 'finished', ['id' => $attempt->id]);
        $DB->set_field('quiz_attempts', 'timefinish', time() - 300, ['id' => $attempt->id]);
        $DB->set_field('quiz_attempts', 'sumgrades', 5, ['id' => $attempt->id]);

        $this->assertSame([], repository::get_quiz_attempts([(int) $user->id]));

        $DB->set_field('quiz', 'reviewattempt', 0x00100, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewmarks', 0x00100, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewmaxmarks', 0x00100, ['id' => $quiz->id]);
        $released = repository::get_quiz_attempts([(int) $user->id]);
        $this->assertCount(1, $released);
        $this->assertSame(50.0, $released[0]['score']);
    }
}
