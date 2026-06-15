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
 * POST /local/partnerapi/v1/register/
 *
 * Creates a new Moodle user account and adds them to the specified cohort.
 * Requires a valid partner API token. Used by the partner dashboard's
 * public registration forms.
 *
 * Body (JSON): {
 *   "username": "...",      // optional; auto-generated from email if omitted
 *   "email": "...",         // required
 *   "firstname": "...",     // required
 *   "lastname": "...",      // required
 *   "password": "...",      // required (min 8 chars)
 *   "cohortid": 3,          // required (must be in client's allowed cohorts)
 *   "city": "...",          // optional
 *   "country": "IN",        // optional (2-letter code)
 *   "customfields": {}      // optional object of shortname -> value
 * }
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/local/partnerapi/lib.php');

use local_partnerapi\client;
use local_partnerapi\util;

// Only POST allowed.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    util::error(405, 'Method not allowed');
}

// Authenticate via token (query param or JSON body).
$token = optional_param('wstoken', '', PARAM_RAW_TRIMMED);
if (empty($token)) {
    // Try reading from the JSON body.
    $rawinput = file_get_contents('php://input');
    $jsonbody = json_decode($rawinput, true);
    $token = $jsonbody['wstoken'] ?? '';
}

$apiclient = client::authenticate($token);
if (!$apiclient) {
    util::error(401, 'Invalid or missing token');
}
$allowedcohorts = client::allowed_cohorts((int)$apiclient->id);

// Parse the JSON body.
if (!isset($jsonbody)) {
    $rawinput = file_get_contents('php://input');
    $jsonbody = json_decode($rawinput, true);
}

if (!is_array($jsonbody)) {
    util::error(400, 'Invalid JSON body');
}

// ─── Validate required fields ────────────────────────────────────────

$email = trim($jsonbody['email'] ?? '');
$firstname = trim($jsonbody['firstname'] ?? '');
$lastname = trim($jsonbody['lastname'] ?? '');
$password = $jsonbody['password'] ?? '';
$cohortid = (int)($jsonbody['cohortid'] ?? 0);

if (empty($email) || !validate_email($email)) {
    util::error(400, 'A valid email address is required');
}
if (empty($firstname)) {
    util::error(400, 'First name is required');
}
if (empty($lastname)) {
    util::error(400, 'Last name is required');
}
if (strlen($password) < 8) {
    util::error(400, 'Password must be at least 8 characters');
}
if ($cohortid <= 0) {
    util::error(400, 'Cohort ID is required');
}

// The cohort must be in the client's allowed set AND be an AFF- cohort.
if (!in_array($cohortid, $allowedcohorts, true)) {
    util::error(403, 'Cohort is not authorized for this client');
}
$cohort = $DB->get_record('cohort', ['id' => $cohortid]);
if (!$cohort || stripos((string)$cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
    util::error(400, 'Invalid or non-affiliation cohort');
}

// ─── Check for duplicate email/username ──────────────────────────────

$username = trim($jsonbody['username'] ?? '');
if (empty($username)) {
    // Auto-generate username from email (lowercase, replace @ and dots).
    $username = strtolower(preg_replace('/[^a-z0-9]/', '', explode('@', $email)[0]));
    // Ensure uniqueness by appending random digits if needed.
    $base = $username;
    while ($DB->record_exists('user', ['username' => $username])) {
        $username = $base . random_int(100, 9999);
    }
}

if ($DB->record_exists('user', ['email' => strtolower($email)])) {
    util::error(409, 'An account with this email already exists');
}
if ($DB->record_exists('user', ['username' => $username])) {
    util::error(409, 'This username is already taken');
}

// ─── Create the user ─────────────────────────────────────────────────

$user = new stdClass();
$user->username = strtolower($username);
$user->email = strtolower($email);
$user->firstname = $firstname;
$user->lastname = $lastname;
$user->password = $password; // user_create_user will hash it
$user->auth = 'manual';
$user->confirmed = 1;
$user->mnethostid = $CFG->mnet_localhost_id;
$user->lang = $CFG->lang ?? 'en';

// Optional standard fields.
if (!empty($jsonbody['city'])) {
    $user->city = trim($jsonbody['city']);
}
if (!empty($jsonbody['country']) && strlen($jsonbody['country']) === 2) {
    $user->country = strtoupper(trim($jsonbody['country']));
}

try {
    $userid = user_create_user($user, true, false);
} catch (Exception $e) {
    util::error(500, 'Account creation failed: ' . $e->getMessage());
}

// ─── Set custom profile fields ───────────────────────────────────────

if (!empty($jsonbody['customfields']) && is_array($jsonbody['customfields'])) {
    require_once($CFG->dirroot . '/user/profile/lib.php');
    foreach ($jsonbody['customfields'] as $shortname => $value) {
        // Only set fields that actually exist (prevent injection of fake fields).
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if ($field) {
            $data = new stdClass();
            $data->userid = $userid;
            $data->fieldid = $field->id;
            $data->data = $value;
            $data->dataformat = 0;
            if ($existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id])) {
                $data->id = $existing->id;
                $DB->update_record('user_info_data', $data);
            } else {
                $DB->insert_record('user_info_data', $data);
            }
        }
    }
}

// ─── Add to cohort ───────────────────────────────────────────────────

cohort_add_member($cohortid, $userid);

// ─── Return success ──────────────────────────────────────────────────

util::send_json([
    'success' => true,
    'userid' => $userid,
    'username' => $user->username,
    'email' => $user->email,
    'cohort' => format_string($cohort->name),
]);
