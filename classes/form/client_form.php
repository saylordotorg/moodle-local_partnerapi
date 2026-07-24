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
 * Create/edit form for a Partner API client.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form for creating and editing partner clients and their cohort scope.
 */
class client_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('clientname', 'local_partnerapi'), ['size' => 48]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        // Build the cohort options as "Name [idnumber] (#id)" for clarity.
        $cohortoptions = [];
        $cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber');
        foreach ($cohorts as $cohort) {
            $label = format_string($cohort->name);
            if ($cohort->idnumber !== null && $cohort->idnumber !== '') {
                $label .= ' [' . $cohort->idnumber . ']';
            }
            $label .= ' (#' . $cohort->id . ')';
            $cohortoptions[(int)$cohort->id] = $label;
        }

        $mform->addElement(
            'autocomplete',
            'cohorts',
            get_string('cohorts', 'local_partnerapi'),
            $cohortoptions,
            ['multiple' => true]
        );
        $mform->addRule('cohorts', get_string('nocohortsselected', 'local_partnerapi'), 'required', null, 'client');
        $mform->addHelpButton('cohorts', 'cohorts', 'local_partnerapi');

        $mform->addElement('advcheckbox', 'suspended', get_string('suspended', 'local_partnerapi'));
        $mform->setDefault('suspended', 0);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }
}
