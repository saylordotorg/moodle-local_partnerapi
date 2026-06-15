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
 * Admin page: manage email-domain → AFF-cohort auto-affiliation mappings.
 *
 * Provides a table UI with add/delete rows instead of raw JSON editing.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/partnerapi/lib.php');

require_login();
require_capability('local/partnerapi:manage', context_system::instance());

global $DB, $OUTPUT, $PAGE;

$thisurl = new moodle_url('/local/partnerapi/domainmap.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($thisurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('autoaffiliation', 'local_partnerapi'));
$PAGE->set_heading(get_string('autoaffiliation', 'local_partnerapi'));

// ─── Handle form actions ─────────────────────────────────────────────

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'add' && confirm_sesskey()) {
    $domain = required_param('domain', PARAM_RAW_TRIMMED);
    $cohortid = required_param('cohortid', PARAM_INT);

    $domain = strtolower(trim($domain));
    // Basic domain validation.
    if (preg_match('/^[a-z0-9][a-z0-9\-\.]*\.[a-z]{2,}$/', $domain)) {
        // Verify cohort is AFF-.
        $cohort = $DB->get_record('cohort', ['id' => $cohortid]);
        if ($cohort && stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) === 0) {
            // Load current map, add, save.
            $map = self_load_map();
            $map[$domain] = (int) $cohortid;
            self_save_map($map);
            redirect($thisurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($thisurl, 'Selected cohort is not a valid AFF- affiliation.', null, \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        redirect($thisurl, 'Invalid domain format (e.g. example.edu).', null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($action === 'delete' && confirm_sesskey()) {
    $domain = required_param('domain', PARAM_RAW_TRIMMED);
    $map = self_load_map();
    unset($map[strtolower($domain)]);
    self_save_map($map);
    redirect($thisurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// ─── Render page ─────────────────────────────────────────────────────

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('autoaffiliation', 'local_partnerapi'));
echo html_writer::tag('p', get_string('domainmap_desc_ui', 'local_partnerapi'));

// Load current mappings and available AFF- cohorts.
$map = self_load_map();
$affcohorts = $DB->get_records_sql(
    "SELECT id, name, idnumber FROM {cohort} WHERE " . $DB->sql_like('idnumber', ':aff', false) . " ORDER BY name",
    ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%']
);
$cohortnames = [];
foreach ($affcohorts as $c) {
    $cohortnames[$c->id] = format_string($c->name) . ' [' . $c->idnumber . ']';
}

// Existing mappings table.
if (!empty($map)) {
    $table = new html_table();
    $table->head = ['Email Domain', 'Affiliation', 'Actions'];
    $table->attributes['class'] = 'generaltable';
    foreach ($map as $domain => $cid) {
        $label = $cohortnames[(int)$cid] ?? "Cohort #$cid (not found)";
        $deleteurl = new moodle_url($thisurl, [
            'action' => 'delete',
            'domain' => $domain,
            'sesskey' => sesskey(),
        ]);
        $deletelink = html_writer::link($deleteurl, get_string('delete'),
            ['class' => 'btn btn-sm btn-outline-danger']);
        $table->data[] = [s($domain), s($label), $deletelink];
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag('p', html_writer::tag('em', 'No domain mappings configured yet.'));
}

// Add new row form.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $thisurl->out(false),
    'class' => 'form-inline mt-3',
]);
echo html_writer::input_hidden_params($thisurl);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']);

echo html_writer::start_div('d-flex flex-wrap align-items-end gap-2');

// Domain input.
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Email domain', ['for' => 'id_domain', 'class' => 'd-block font-weight-bold']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'id_domain',
    'name' => 'domain',
    'placeholder' => 'e.g. cnu.edu',
    'required' => 'required',
    'class' => 'form-control',
    'style' => 'min-width:200px',
]);
echo html_writer::end_div();

// Cohort dropdown.
echo html_writer::start_div('form-group');
echo html_writer::tag('label', 'Affiliation', ['for' => 'id_cohortid', 'class' => 'd-block font-weight-bold']);
if (empty($cohortnames)) {
    echo html_writer::tag('span', 'No AFF- cohorts available. Create one first.', ['class' => 'text-danger']);
} else {
    echo html_writer::select($cohortnames, 'cohortid', '', ['' => 'Choose...'], ['id' => 'id_cohortid', 'class' => 'form-control', 'required' => 'required']);
}
echo html_writer::end_div();

// Submit button.
if (!empty($cohortnames)) {
    echo html_writer::tag('button', '+ Add mapping', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
}

echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();

// ─── Helpers ─────────────────────────────────────────────────────────

function self_load_map(): array {
    $json = get_config('local_partnerapi', 'domain_cohort_map');
    if (empty($json)) return [];
    $map = json_decode($json, true);
    return is_array($map) ? $map : [];
}

function self_save_map(array $map): void {
    set_config('domain_cohort_map', json_encode($map, JSON_UNESCAPED_SLASHES), 'local_partnerapi');
}
