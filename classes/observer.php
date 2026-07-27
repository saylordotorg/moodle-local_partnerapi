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

// phpcs:disable moodle.Commenting.ValidTags.Invalid -- PHPMD suppression tag.
/**
 * Applies configured email-domain affiliation rules to user events.
 *
 * The registration scope is read and written across event callbacks; PHPMD
 * does not resolve those static accesses and reports the field as unused.
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateField)
 */
class observer {
    // phpcs:enable moodle.Commenting.ValidTags.Invalid
    /** @var int[]|null Cohorts allowed while an API registration event is handled. */
    private static $registrationscope = null;
    /**
     * Handle user_created event — auto-affiliate by email domain.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        self::auto_affiliate((int) $event->relateduserid);
    }

    /**
     * Handle user_loggedin event — auto-affiliate by email domain.
     * Catches users who registered before the domain mapping was configured.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        self::auto_affiliate((int) $event->userid);
    }

    /**
     * Restrict domain-derived affiliations during partner API registration.
     *
     * @param int[] $cohortids Cohorts authorized for the authenticated client.
     * @return void
     */
    public static function begin_registration_scope(array $cohortids): void {
        $cohortids = array_map('intval', $cohortids);
        self::$registrationscope = array_values(array_unique(array_filter($cohortids)));
    }

    /**
     * Clear the temporary partner API registration scope.
     *
     * @return void
     */
    public static function end_registration_scope(): void {
        self::$registrationscope = null;
    }

    /**
     * Resolve configured AFF- cohorts for an email address.
     *
     * @param string $email Email address to resolve.
     * @return int[] Matching, valid affiliation cohort ids.
     */
    public static function mapped_affiliations_for_email(string $email): array {
        global $DB;

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return [];
        }

        $domain = strtolower(trim($parts[1]));
        $cohortid = (int) (self::get_domain_mappings()[$domain] ?? 0);
        if ($cohortid <= 0) {
            return [];
        }

        $cohort = $DB->get_record('cohort', ['id' => $cohortid], 'id, idnumber', IGNORE_MISSING);
        if (!$cohort || stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
            return [];
        }

        return [(int) $cohort->id];
    }

    /**
     * Check a user's email mapping and add one eligible AFF- membership.
     *
     * @param int $userid
     * @return void
     */
    private static function auto_affiliate(int $userid): void {
        global $DB;

        if ($userid <= 0) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, email');
        if (!$user || empty($user->email)) {
            return;
        }

        // Never let a later login overwrite or add to an explicit affiliation.
        if (self::has_affiliation($userid)) {
            return;
        }

        require_once(__DIR__ . '/../../../cohort/lib.php');

        foreach (self::mapped_affiliations_for_email((string) $user->email) as $cohortid) {
            if (self::$registrationscope !== null && !in_array($cohortid, self::$registrationscope, true)) {
                continue;
            }
            if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
                cohort_add_member($cohortid, $userid);
                provenance::record($userid, $cohortid, provenance::SOURCE_SIGNUP);
            }
        }
    }

    /**
     * Check whether a user already belongs to any AFF- cohort.
     *
     * @param int $userid User id to check.
     * @return bool Whether an affiliation membership already exists.
     */
    private static function has_affiliation(int $userid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {cohort_members} cm
                  JOIN {cohort} c ON c.id = cm.cohortid
                 WHERE cm.userid = :userid
                   AND " . $DB->sql_like('c.idnumber', ':aff', false);

        return $DB->record_exists_sql($sql, [
            'userid' => $userid,
            'aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%',
        ]);
    }

    /**
     * Load the domain→cohort mapping from the plugin config (JSON).
     *
     * Format: {"cnu.in.edu": 3, "cnu.edu": 3, "acme.org": 5}.
     *
     * @return array<string, int> Domain to cohort id mapping.
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
