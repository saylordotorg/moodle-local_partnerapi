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
 * Privacy provider for local_partnerapi.
 *
 * @package    local_partnerapi
 * @category   privacy
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for affiliation provenance and partner exports.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored provenance and data disclosed to authorized partners.
     *
     * @param collection $collection Privacy metadata collection.
     * @return collection Updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_partnerapi_provenance',
            [
                'userid' => 'privacy:metadata:provenance:userid',
                'cohortid' => 'privacy:metadata:provenance:cohortid',
                'source' => 'privacy:metadata:provenance:source',
                'timecreated' => 'privacy:metadata:provenance:timecreated',
                'timemodified' => 'privacy:metadata:provenance:timemodified',
            ],
            'privacy:metadata:provenance'
        );

        $collection->add_external_location_link(
            'partner',
            [
                'identity' => 'privacy:metadata:partner:identity',
                'affiliation' => 'privacy:metadata:partner:affiliation',
                'learning' => 'privacy:metadata:partner:learning',
                'assessment' => 'privacy:metadata:partner:assessment',
                'activity' => 'privacy:metadata:partner:activity',
            ],
            'privacy:metadata:partner'
        );

        return $collection;
    }

    /**
     * Get contexts containing provenance for a user.
     *
     * @param int $userid User id.
     * @return contextlist Contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('local_partnerapi_provenance', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Export a user's affiliation provenance.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            return;
        }

        $sql = "SELECT p.id, p.cohortid, p.source, p.timecreated, p.timemodified,
                       c.name AS cohortname, c.idnumber AS cohortidnumber
                  FROM {local_partnerapi_provenance} p
             LEFT JOIN {cohort} c ON c.id = p.cohortid
                 WHERE p.userid = :userid
              ORDER BY p.timecreated, p.id";
        $records = $DB->get_records_sql($sql, ['userid' => $contextlist->get_user()->id]);
        $export = [];

        foreach ($records as $record) {
            $export[] = (object) [
                'cohortid' => (int) $record->cohortid,
                'cohortname' => $record->cohortname,
                'cohortidnumber' => $record->cohortidnumber,
                'source' => $record->source,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
            ];
        }

        writer::with_context(\context_system::instance())->export_data(
            [get_string('privacy:path:provenance', 'local_partnerapi')],
            (object) ['affiliations' => $export]
        );
    }

    /**
     * Delete all provenance records in a context.
     *
     * @param \context $context Context being deleted.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            $DB->delete_records('local_partnerapi_provenance');
        }
    }

    /**
     * Delete provenance for a user in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            $DB->delete_records(
                'local_partnerapi_provenance',
                ['userid' => $contextlist->get_user()->id]
            );
        }
    }

    /**
     * Add users with provenance data to a system-context user list.
     *
     * @param userlist $userlist User list for the context.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            'SELECT DISTINCT userid FROM {local_partnerapi_provenance}',
            []
        );
    }

    /**
     * Delete provenance for an approved list of users.
     *
     * @param approved_userlist $userlist Approved users and context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $userids = $userlist->get_userids();
        if (!($userlist->get_context() instanceof \context_system) || empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        $DB->delete_records_select('local_partnerapi_provenance', "userid $insql", $params);
    }
}
