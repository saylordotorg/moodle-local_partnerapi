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
 * Partner client token authentication and cohort-scope resolution.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves and authenticates partner API clients.
 */
class client {

    /**
     * Authenticate a presented token.
     *
     * @param string $token the value sent as the wstoken parameter
     * @return \stdClass|null the client record, or null when the token is unknown/suspended
     */
    public static function authenticate(string $token): ?\stdClass {
        global $DB;

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $record = $DB->get_record('local_partnerapi_clients', ['token' => $token, 'suspended' => 0]);
        return $record ?: null;
    }

    /**
     * Return the set of cohort ids a client is authorized to read.
     *
     * @param int $clientid
     * @return int[] list of Moodle cohort ids (may be empty)
     */
    public static function allowed_cohorts(int $clientid): array {
        global $DB;

        $ids = $DB->get_fieldset_select('local_partnerapi_cohorts', 'cohortid', 'clientid = ?', [$clientid]);
        return array_values(array_unique(array_map('intval', $ids)));
    }
}
