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
 * Privacy API tests for affiliation provenance.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_partnerapi\privacy\provider;

/**
 * Verifies provenance metadata, export, and deletion.
 *
 * @covers \local_partnerapi\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Seed one provenance row.
     *
     * @return array{user:\stdClass, cohort:\stdClass} Seeded records.
     */
    private function seed_provenance(): array {
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Privacy Partner',
            'idnumber' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . 'PRIVACY',
        ]);
        provenance::record((int) $user->id, (int) $cohort->id, provenance::SOURCE_SELF);
        return ['user' => $user, 'cohort' => $cohort];
    }

    /**
     * Metadata declares the provenance table and partner disclosure.
     *
     * @return void
     */
    public function test_metadata_describes_stored_and_external_data(): void {
        $collection = provider::get_metadata(new collection('local_partnerapi'));
        $this->assertCount(2, $collection->get_collection());
    }

    /**
     * Provenance is included in a user's system-context export.
     *
     * @return void
     */
    public function test_provenance_is_exported(): void {
        $this->resetAfterTest(true);
        $seed = $this->seed_provenance();
        $systemcontext = \context_system::instance();

        $contexts = provider::get_contexts_for_userid((int) $seed['user']->id);
        $this->assertSame([$systemcontext->id], array_map('intval', $contexts->get_contextids()));
        $this->export_context_data_for_user(
            (int) $seed['user']->id,
            $systemcontext,
            'local_partnerapi'
        );

        $data = writer::with_context($systemcontext)->get_data([
            get_string('privacy:path:provenance', 'local_partnerapi'),
        ]);
        $this->assertCount(1, $data->affiliations);
        $this->assertSame(provenance::SOURCE_SELF, $data->affiliations[0]->source);
        $this->assertSame((int) $seed['cohort']->id, $data->affiliations[0]->cohortid);
    }

    /**
     * An approved erasure removes only the selected user's provenance.
     *
     * @return void
     */
    public function test_provenance_is_deleted_for_user(): void {
        global $DB;

        $this->resetAfterTest(true);
        $first = $this->seed_provenance();
        $second = $this->seed_provenance();
        $contextlist = new approved_contextlist(
            $first['user'],
            'local_partnerapi',
            [\context_system::instance()->id]
        );

        provider::delete_data_for_user($contextlist);
        $this->assertFalse($DB->record_exists('local_partnerapi_provenance', [
            'userid' => $first['user']->id,
        ]));
        $this->assertTrue($DB->record_exists('local_partnerapi_provenance', [
            'userid' => $second['user']->id,
        ]));
    }
}
