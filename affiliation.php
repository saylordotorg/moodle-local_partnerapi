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
 * Self-service affiliation chooser. Lets a signed-in learner join a partner
 * affiliation cohort (idnumber starting `AFF-`) if they were not pre-sorted.
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

// ─── Handle a join request ───────────────────────────────────────────
$join = optional_param('join', 0, PARAM_INT);
if ($join && confirm_sesskey()) {
    $cohort = $DB->get_record('cohort', ['id' => $join], '*', IGNORE_MISSING);
    // Only AFF- cohorts can be self-joined.
    if (!$cohort || stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
        redirect($thisurl, get_string('affiliationinvalid', 'local_partnerapi'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    if ($DB->record_exists('cohort_members', ['cohortid' => $cohort->id, 'userid' => $USER->id])) {
        redirect($thisurl, get_string('affiliationalreadymember', 'local_partnerapi', format_string($cohort->name)));
    }
    cohort_add_member($cohort->id, $USER->id);
    redirect($thisurl, get_string('affiliationjoined', 'local_partnerapi', format_string($cohort->name)));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('affiliation', 'local_partnerapi'));
echo html_writer::tag('p', get_string('affiliation_intro', 'local_partnerapi'));

// ─── Current affiliations ────────────────────────────────────────────
$current = local_partnerapi_user_affiliations((int) $USER->id);
echo $OUTPUT->heading(get_string('youraffiliations', 'local_partnerapi'), 4);
if (!empty($current)) {
    echo html_writer::alist(array_map(fn($c) => format_string($c->name), $current));
} else {
    echo html_writer::tag('p', get_string('noaffiliation', 'local_partnerapi'));
}

// ─── Available affiliations to join ──────────────────────────────────
$params = ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%'];
$notin = '';
$currentids = array_map(fn($c) => (int) $c->id, $current);
if (!empty($currentids)) {
    list($notinsql, $inparams) = $DB->get_in_or_equal($currentids, SQL_PARAMS_NAMED, 'ex', false);
    $notin = " AND c.id $notinsql";
    $params += $inparams;
}
$available = $DB->get_records_sql(
    "SELECT c.id, c.name
       FROM {cohort} c
      WHERE c.visible = 1
        AND " . $DB->sql_like('c.idnumber', ':aff', false) . $notin . "
   ORDER BY c.name ASC",
    $params
);

echo $OUTPUT->heading(get_string('chooseaffiliation', 'local_partnerapi'), 4);
if (!empty($available)) {
    $options = [];
    foreach ($available as $c) {
        $options[$c->id] = format_string($c->name);
    }
    // Include sesskey in the URL so single_select's GET submit is validated.
    $select = new single_select(
        new moodle_url('/local/partnerapi/affiliation.php', ['sesskey' => sesskey()]),
        'join',
        $options,
        '',
        ['' => get_string('choosedots')]
    );
    $select->label = get_string('addaffiliation', 'local_partnerapi');
    echo $OUTPUT->render($select);
} else {
    echo html_writer::tag('p', get_string('noaffiliationsavailable', 'local_partnerapi'));
}

echo $OUTPUT->footer();
