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
 * GET /local/partnerapi/v1/quizzes?userids[]=...
 *
 * Returns quiz attempts for the requested users, scoped to the client's
 * allowed cohorts.
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

$requested = util::userids_from_request();
$scoped = repository::scope_userids($requested, $allowedcohorts);

util::send_json(repository::get_quiz_attempts($scoped));
