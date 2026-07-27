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
 * Site administration UI to manage Partner API clients and cohort scopes.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/formslib.php');

use local_partnerapi\client;
use local_partnerapi\form\client_form;

admin_externalpage_setup('local_partnerapi_manage');

$context = context_system::instance();
require_capability('local/partnerapi:manage', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id     = optional_param('id', 0, PARAM_INT);

$baseurl = new moodle_url('/local/partnerapi/manage.php');
$PAGE->set_url($baseurl);
$PAGE->set_context($context);

// Non-form actions.

if ($action === 'regenerate' && $id) {
    require_sesskey();
    client::regenerate_token($id);
    redirect($baseurl, get_string('tokenregenerated', 'local_partnerapi'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if (($action === 'suspend' || $action === 'enable') && $id) {
    require_sesskey();
    client::set_suspended($id, $action === 'suspend');
    redirect($baseurl);
}

if ($action === 'delete' && $id) {
    require_sesskey();
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        client::delete_client($id);
        redirect($baseurl, get_string('clientdeleted', 'local_partnerapi'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $clientrec = $DB->get_record('local_partnerapi_clients', ['id' => $id], '*', MUST_EXIST);
    echo $OUTPUT->header();
    $confirmurl = new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_partnerapi', format_string($clientrec->name)),
        $confirmurl,
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Create or edit form.

$editing = ($action === 'edit' && $id);

$mform = new client_form($baseurl->out(false), ['id' => $editing ? $id : 0]);

if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if ($data = $mform->get_data()) {
    client::save_client($data, isset($data->cohorts) ? (array)$data->cohorts : []);
    redirect($baseurl, get_string('clientsaved', 'local_partnerapi'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$showform = ($action === 'new') || $editing || $mform->is_submitted();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageclients', 'local_partnerapi'));

if ($showform) {
    if ($editing && !$mform->is_submitted()) {
        $clientrec = $DB->get_record('local_partnerapi_clients', ['id' => $id], '*', MUST_EXIST);
        $mform->set_data([
            'id'        => (int)$clientrec->id,
            'name'      => $clientrec->name,
            'suspended' => (int)$clientrec->suspended,
            'cohorts'   => client::allowed_cohorts((int)$clientrec->id),
        ]);
    }
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// Client listing.

echo $OUTPUT->box(get_string('manage_intro', 'local_partnerapi'));

echo $OUTPUT->single_button(
    new moodle_url($baseurl, ['action' => 'new']),
    get_string('addclient', 'local_partnerapi'),
    'get'
);

$clients = client::get_all_clients();

if (empty($clients)) {
    echo $OUTPUT->notification(get_string('noclients', 'local_partnerapi'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Resolve cohort names for display.
$allcohorts = $DB->get_records_menu('cohort', null, '', 'id, name');

$table = new html_table();
$table->head = [
    get_string('clientname', 'local_partnerapi'),
    get_string('cohorts', 'local_partnerapi'),
    get_string('token', 'local_partnerapi'),
    get_string('status', 'local_partnerapi'),
    get_string('actions', 'local_partnerapi'),
];
$table->attributes['class'] = 'generaltable';

foreach ($clients as $client) {
    $cohortlabels = [];
    foreach ($client->cohortids as $cohortid) {
        $name = $allcohorts[$cohortid] ?? get_string('missingcohort', 'local_partnerapi');
        $cohortlabels[] = format_string($name) . ' (#' . $cohortid . ')';
    }

    $tokencell = \html_writer::tag('code', s($client->token), ['style' => 'word-break:break-all;']);

    if ($client->suspended) {
        $statuscell = \html_writer::span(get_string('statussuspended', 'local_partnerapi'), 'badge badge-warning');
    } else {
        $statuscell = \html_writer::span(get_string('statusactive', 'local_partnerapi'), 'badge badge-success');
    }

    $actions = [];
    $actions[] = \html_writer::link(
        new moodle_url($baseurl, ['action' => 'edit', 'id' => $client->id]),
        get_string('edit')
    );
    $actions[] = \html_writer::link(
        new moodle_url($baseurl, ['action' => 'regenerate', 'id' => $client->id, 'sesskey' => sesskey()]),
        get_string('regenerate', 'local_partnerapi')
    );
    if ($client->suspended) {
        $actions[] = \html_writer::link(
            new moodle_url($baseurl, ['action' => 'enable', 'id' => $client->id, 'sesskey' => sesskey()]),
            get_string('enable', 'local_partnerapi')
        );
    } else {
        $actions[] = \html_writer::link(
            new moodle_url($baseurl, ['action' => 'suspend', 'id' => $client->id, 'sesskey' => sesskey()]),
            get_string('suspend', 'local_partnerapi')
        );
    }
    $actions[] = \html_writer::link(
        new moodle_url($baseurl, ['action' => 'delete', 'id' => $client->id, 'sesskey' => sesskey()]),
        get_string('delete')
    );

    $table->data[] = [
        format_string($client->name),
        $cohortlabels ? implode('<br>', $cohortlabels) : '-',
        $tokencell,
        $statuscell,
        implode(' | ', $actions),
    ];
}

echo \html_writer::table($table);
echo $OUTPUT->footer();
