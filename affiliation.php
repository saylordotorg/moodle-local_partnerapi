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
 * Self-service affiliation page. A learner can have at most ONE affiliation.
 * They can join an affiliation or leave their current one.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/local/partnerapi/lib.php');

require_login();

if (isguestuser()) {
    throw new \moodle_exception('noguest');
}

global $DB, $USER, $OUTPUT, $PAGE;

$thisurl = new moodle_url('/local/partnerapi/affiliation.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($thisurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('affiliation', 'local_partnerapi'));
$PAGE->set_heading(get_string('affiliation', 'local_partnerapi'));

// ─── Handle actions ──────────────────────────────────────────────────

// Join an affiliation.
$join = optional_param('join', 0, PARAM_INT);
if ($join && confirm_sesskey()) {
    // Limit to 1: remove any existing AFF- memberships first.
    $existing = local_partnerapi_user_affiliations((int) $USER->id);
    foreach ($existing as $ex) {
        cohort_remove_member((int) $ex->id, $USER->id);
    }

    $cohort = $DB->get_record('cohort', ['id' => $join], '*', IGNORE_MISSING);
    if (!$cohort || stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
        redirect($thisurl, get_string('affiliationinvalid', 'local_partnerapi'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    cohort_add_member($cohort->id, $USER->id);
    \local_partnerapi\provenance::record((int)$USER->id, (int)$cohort->id, \local_partnerapi\provenance::SOURCE_SELF);
    redirect($thisurl, get_string('affiliationjoined', 'local_partnerapi', format_string($cohort->name)),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Leave (unaffiliate).
$leave = optional_param('leave', 0, PARAM_INT);
if ($leave && confirm_sesskey()) {
    $cohort = $DB->get_record('cohort', ['id' => $leave], '*', IGNORE_MISSING);
    if ($cohort && $DB->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $USER->id])) {
        cohort_remove_member($cohort->id, $USER->id);
        redirect($thisurl, get_string('affiliationremoved', 'local_partnerapi', format_string($cohort->name)),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($thisurl);
}

// ─── Render page ─────────────────────────────────────────────────────

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('affiliation', 'local_partnerapi'));
echo html_writer::tag('p', get_string('affiliation_intro', 'local_partnerapi'),
    ['style' => 'max-width: 700px; margin-bottom: 1.5rem;']);

$current = local_partnerapi_user_affiliations((int) $USER->id);

if (!empty($current)) {
    // User already has an affiliation — show it with an option to leave.
    $aff = reset($current); // only one allowed
    echo html_writer::start_div('card', ['style' => 'max-width: 500px; margin-bottom: 1.5rem;']);
    echo html_writer::start_div('card-body d-flex align-items-center justify-content-between');
    echo html_writer::tag('div',
        html_writer::tag('strong', format_string($aff->name)) .
        html_writer::tag('span', ' — ' . get_string('youraffiliations', 'local_partnerapi'),
            ['class' => 'text-muted']),
    );
    $leaveurl = new moodle_url($thisurl, ['leave' => $aff->id, 'sesskey' => sesskey()]);
    echo html_writer::link($leaveurl, get_string('affiliationleave', 'local_partnerapi'),
        ['class' => 'btn btn-outline-danger btn-sm ml-3']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Data sharing disclaimer.
    echo html_writer::tag('div',
        '<strong>' . get_string('domain_disclosure_heading', 'local_partnerapi') . ':</strong> ' .
        get_string('affiliation_disclaimer', 'local_partnerapi'),
        ['class' => 'alert alert-info', 'style' => 'font-size: 0.85rem; max-width: 700px;']
    );
} else {
    // No current affiliation — show the chooser (limited to 1).
    echo html_writer::tag('p', get_string('noaffiliation', 'local_partnerapi'));

    $params = ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%'];
    $available = $DB->get_records_sql(
        "SELECT c.id, c.name
           FROM {cohort} c
          WHERE c.visible = 1
            AND " . $DB->sql_like('c.idnumber', ':aff', false) . "
       ORDER BY c.name ASC",
        $params
    );

    if (!empty($available)) {
        echo html_writer::tag('h4', get_string('chooseaffiliation', 'local_partnerapi'),
            ['style' => 'margin-top: 1.5rem;']);

        $options = [];
        foreach ($available as $c) {
            $options[$c->id] = format_string($c->name);
        }
        $select = new single_select(
            new moodle_url($thisurl, ['sesskey' => sesskey()]),
            'join',
            $options,
            '',
            ['' => get_string('choosedots')]
        );
        $select->label = get_string('addaffiliation', 'local_partnerapi');
        echo $OUTPUT->render($select);

        // The chooser auto-submits on change, so confirm the choice before it
        // is applied — accidental picks were affiliating users instantly.
        // Capture-phase listener runs before the auto-submit handler; an
        // unconfirmed choice is reset and never submitted.
        $confirmjs = addslashes_js(get_string('affiliation_confirm', 'local_partnerapi', '{PARTNER}'));
        echo html_writer::script(
            'document.addEventListener("change", function(e) {' .
            '    var sel = e.target;' .
            '    if (!sel || sel.name !== "join" || !sel.value) { return; }' .
            '    var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : "";' .
            '    var msg = "' . $confirmjs . '".replace("{PARTNER}", name);' .
            '    if (!window.confirm(msg)) {' .
            '        e.stopPropagation();' .
            '        e.preventDefault();' .
            '        sel.value = "";' .
            '    }' .
            '}, true);'
        );

        // Disclaimer (shown alongside the chooser).
        echo html_writer::tag('div',
            '<strong>' . get_string('domain_disclosure_heading', 'local_partnerapi') . ':</strong> ' .
            get_string('affiliation_disclaimer', 'local_partnerapi'),
            ['class' => 'alert alert-info mt-3', 'style' => 'font-size: 0.85rem; max-width: 700px;']
        );
    } else {
        echo html_writer::tag('p', get_string('noaffiliationsavailable', 'local_partnerapi'));
    }
}

echo $OUTPUT->footer();
