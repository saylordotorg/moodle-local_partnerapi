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
 * Public library hooks for local_partnerapi.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The idnumber prefix that marks a cohort as a partner affiliation.
 */
const LOCAL_PARTNERAPI_AFFILIATION_PREFIX = 'AFF-';

/**
 * Return the partner-affiliation cohorts a user belongs to.
 *
 * A cohort counts as an affiliation only when its idnumber starts with the
 * `AFF-` prefix, so general cohorts (e.g. "Pre-MBA") are never treated as
 * partners.
 *
 * @param int $userid
 * @return array list of {id, name} cohort records
 */
function local_partnerapi_user_affiliations(int $userid): array {
    global $DB;
    $sql = "SELECT c.id, c.name
              FROM {cohort} c
              JOIN {cohort_members} cm ON cm.cohortid = c.id
             WHERE cm.userid = :userid
               AND " . $DB->sql_like('c.idnumber', ':aff', false) . "
          ORDER BY c.name ASC";
    return array_values($DB->get_records_sql($sql, [
        'userid' => $userid,
        'aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%',
    ]));
}

/**
 * Extend global navigation to register the affiliation chooser page so that
 * Moodle Workplace themes do not 404 it.
 *
 * @param global_navigation $navigation
 */
function local_partnerapi_extend_navigation(global_navigation $navigation) {
    // No-op: we just need the function to exist so Moodle recognizes lib.php
    // hooks. The actual nav node is added in the myprofile callback.
}

/**
 * Add an "Affiliation" category to the user profile page listing the partner
 * cohorts (idnumber starting `AFF-`) the user belongs to, plus a link to the
 * self-select chooser for the current user.
 *
 * Standard Moodle myprofile callback.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_partnerapi_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    $category = new \core_user\output\myprofile\category(
        'local_partnerapi_affiliation',
        get_string('affiliation', 'local_partnerapi'),
        null
    );
    $tree->add_category($category);

    $affiliations = local_partnerapi_user_affiliations((int) $user->id);

    if (!empty($affiliations)) {
        foreach ($affiliations as $a) {
            $tree->add_node(new \core_user\output\myprofile\node(
                'local_partnerapi_affiliation',
                'local_partnerapi_affiliation_' . $a->id,
                format_string($a->name),
                null,
                null
            ));
        }
    } else {
        $tree->add_node(new \core_user\output\myprofile\node(
            'local_partnerapi_affiliation',
            'local_partnerapi_affiliation_none',
            get_string('noaffiliation', 'local_partnerapi'),
            null,
            null
        ));
    }

    // Only the owner can change their own affiliation.
    if ($iscurrentuser && !isguestuser()) {
        $tree->add_node(new \core_user\output\myprofile\node(
            'local_partnerapi_affiliation',
            'local_partnerapi_affiliation_choose',
            get_string('chooseaffiliation', 'local_partnerapi'),
            null,
            new \moodle_url('/local/partnerapi/affiliation.php')
        ));
    }
}
