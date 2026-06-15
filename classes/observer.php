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
 * Event observer for auto-affiliation by email domain.
 *
 * When a user is created or logs in, this observer checks their email domain
 * against the configured domain→cohort mappings. If a match is found and the
 * user is not already a member of that cohort, they are automatically added.
 *
 * Only AFF- cohorts are eligible for auto-affiliation.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class observer {

    /**
     * Handle user_created event — auto-affiliate by email domain.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event) {
        self::auto_affiliate($event->relateduserid);
    }

    /**
     * Handle user_loggedin event — auto-affiliate by email domain.
     * Catches users who registered before the domain mapping was configured.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        self::auto_affiliate($event->userid);
    }

    /**
     * Check the user's email domain against configured mappings and add them
     * to the matching AFF- cohort(s) if they're not already a member.
     *
     * @param int $userid
     */
    private static function auto_affiliate(int $userid) {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, email');
        if (!$user || empty($user->email)) {
            return;
        }

        $parts = explode('@', $user->email);
        if (count($parts) !== 2) {
            return;
        }
        $domain = strtolower(trim($parts[1]));
        if (empty($domain)) {
            return;
        }

        // Load the domain→cohort mappings from the plugin config.
        $mappings = self::get_domain_mappings();
        if (empty($mappings)) {
            return;
        }

        require_once(__DIR__ . '/../../../cohort/lib.php');

        foreach ($mappings as $configDomain => $cohortId) {
            $configDomain = strtolower(trim($configDomain));
            if ($configDomain === $domain) {
                // Verify the cohort exists and is an AFF- cohort.
                $cohort = $DB->get_record('cohort', ['id' => (int) $cohortId], 'id, idnumber');
                if (!$cohort) {
                    continue;
                }
                if (stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
                    continue;
                }
                // Add the user if not already a member.
                if (!$DB->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $userid])) {
                    cohort_add_member($cohort->id, $userid);
                }
            }
        }
    }

    /**
     * Load the domain→cohort mapping from the plugin config (JSON).
     *
     * Format: {"cnu.in.edu": 3, "cnu.edu": 3, "acme.org": 5}
     *
     * @return array<string, int> domain → cohort id
     */
    private static function get_domain_mappings(): array {
        $json = get_config('local_partnerapi', 'domain_cohort_map');
        if (empty($json)) {
            return [];
        }
        $map = json_decode($json, true);
        return is_array($map) ? $map : [];
    }
}
