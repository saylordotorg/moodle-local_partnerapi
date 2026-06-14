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
 * Shared bootstrap for Partner API v1 endpoints.
 *
 * Loads Moodle without a session, authenticates the wstoken, and exposes:
 *   - $apiclient        the authenticated client record
 *   - $allowedcohorts   int[] of cohort ids the client may read
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_partnerapi\client;
use local_partnerapi\util;

// Read-only API: only GET is permitted.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    util::error(405, 'Method not allowed');
}

$token = optional_param('wstoken', '', PARAM_RAW_TRIMMED);
$apiclient = client::authenticate($token);
if (!$apiclient) {
    util::error(401, 'Invalid or missing token');
}

$allowedcohorts = client::allowed_cohorts((int)$apiclient->id);
