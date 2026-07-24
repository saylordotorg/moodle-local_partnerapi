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
 * GET /local/partnerapi/v1/timeincourse?userids[]=...&since=...&until=...
 *
 * Returns estimated time-on-task per (user, course) in seconds, scoped to the
 * client's allowed cohorts. Work is bounded by a date window and user-id page.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Bootstrap includes config.php.
require(__DIR__ . '/../bootstrap.php');

defined('MOODLE_INTERNAL') || die();

use local_partnerapi\repository;
use local_partnerapi\util;

$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 100, PARAM_INT);
if ($page < 0 || $perpage < 1 || $perpage > util::MAX_USERIDS) {
    util::error(400, get_string('error:invalidpagination', 'local_partnerapi', util::MAX_USERIDS));
}

$until = optional_param('until', time(), PARAM_INT);
$since = optional_param('since', max(0, $until - (90 * DAYSECS)), PARAM_INT);
if ($since < 0 || $until <= 0 || $since > $until || ($until - $since) > (366 * DAYSECS)) {
    util::error(400, get_string('error:invalidtimerange', 'local_partnerapi'));
}

$requested = util::userids_from_request(1000);
$requested = array_slice($requested, $page * $perpage, $perpage);
$scoped = repository::scope_userids($requested, $allowedcohorts);

util::send_json(repository::get_time_in_course($scoped, $since, $until));
