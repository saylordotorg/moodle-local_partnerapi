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
 * CLI: create (or update) a Partner API client with a scoped token.
 *
 * Usage:
 *   php local/partnerapi/cli/create_client.php --name="Chandigarh" --cohorts=23360,36144
 *   php local/partnerapi/cli/create_client.php --name="Chandigarh" --cohorts=23360,36144 --token=EXISTING
 *
 * Prints the token on success. Re-running with the same --name replaces that
 * client's cohort scope (and keeps the token unless --token is given).
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'name'    => '',
        'cohorts' => '',
        'token'   => '',
        'help'    => false,
    ],
    ['h' => 'help']
);

if ($options['help'] || $options['name'] === '' || $options['cohorts'] === '') {
    cli_writeln("Create or update a Partner API client with a cohort-scoped token.\n");
    cli_writeln("Options:");
    cli_writeln("  --name=NAME         Required. Human-readable client name (unique).");
    cli_writeln("  --cohorts=IDS       Required. Comma-separated Moodle cohort ids, e.g. 23360,36144");
    cli_writeln("  --token=VALUE       Optional. Use a specific token instead of generating one.");
    cli_writeln("  -h, --help          Show this help.\n");
    exit(0);
}

$name = trim($options['name']);

$cohortids = [];
foreach (explode(',', $options['cohorts']) as $part) {
    $id = (int)trim($part);
    if ($id > 0) {
        $cohortids[$id] = $id;
    }
}
if (empty($cohortids)) {
    cli_error('No valid cohort ids provided.');
}

// Validate the cohorts exist.
foreach ($cohortids as $cid) {
    if (!$DB->record_exists('cohort', ['id' => $cid])) {
        cli_error("Cohort id {$cid} does not exist on this site.");
    }
}

$now = time();
$transaction = $DB->start_delegated_transaction();

$client = $DB->get_record('local_partnerapi_clients', ['name' => $name]);
if ($client) {
    if ($options['token'] !== '') {
        $client->token = trim($options['token']);
    }
    $client->timemodified = $now;
    $DB->update_record('local_partnerapi_clients', $client);
    $DB->delete_records('local_partnerapi_cohorts', ['clientid' => $client->id]);
} else {
    $client = new stdClass();
    $client->name = $name;
    $client->token = $options['token'] !== '' ? trim($options['token']) : bin2hex(random_bytes(32));
    $client->suspended = 0;
    $client->timecreated = $now;
    $client->timemodified = $now;
    $client->id = $DB->insert_record('local_partnerapi_clients', $client);
}

foreach ($cohortids as $cid) {
    $DB->insert_record('local_partnerapi_cohorts', (object)[
        'clientid' => $client->id,
        'cohortid' => $cid,
    ]);
}

$transaction->allow_commit();

cli_writeln("Client '{$name}' (id {$client->id}) scoped to cohorts: " . implode(', ', array_keys($cohortids)));
cli_writeln('TOKEN: ' . $client->token);
