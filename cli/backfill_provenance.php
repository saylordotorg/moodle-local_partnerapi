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
 * CLI: re-run the affiliation source provenance backfill.
 *
 * Records `signup_partner_choice` provenance for existing AFF- cohort members
 * whose email domain matches the configured domain_cohort_map. Idempotent and
 * safe to re-run; existing rows are never downgraded.
 *
 * Usage:
 *   php local/partnerapi/cli/backfill_provenance.php
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/partnerapi/lib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Re-run the affiliation source provenance backfill.\n");
    cli_writeln("Records provenance for existing AFF- cohort members based on the");
    cli_writeln("configured domain_cohort_map. Idempotent and safe to re-run.\n");
    cli_writeln("Usage:");
    cli_writeln("  php local/partnerapi/cli/backfill_provenance.php\n");
    cli_writeln("Options:");
    cli_writeln("  -h, --help          Show this help.");
    exit(0);
}

$count = local_partnerapi_run_backfill();

cli_writeln("Backfill complete. Recorded {$count} provenance row(s).");
