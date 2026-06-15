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
 * Language strings for local_partnerapi.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Partner API';
$string['partnerapi:manage'] = 'Manage Partner API clients and cohort scopes';
$string['privacy:metadata'] = 'The Partner API plugin does not store any personal data itself. It exposes existing Moodle learner data to authorized, cohort-scoped partner clients over a read-only API.';

// Management UI.
$string['manageclients'] = 'Partner API clients';
$string['manage_intro'] = 'Partner API clients are scoped tokens used by the Partner Dashboard sync service. Each client can read only the cohorts assigned to it. Treat tokens as secrets and transmit them over HTTPS only.';
$string['addclient'] = 'Add client';
$string['clientname'] = 'Client name';
$string['cohorts'] = 'Cohorts';
$string['cohorts_help'] = 'The cohorts this client is allowed to read. The token can only return learners (and their enrolments, grades and access logs) for these cohorts; requests for any other cohort are ignored.';
$string['suspended'] = 'Suspended';
$string['token'] = 'Token';
$string['status'] = 'Status';
$string['statusactive'] = 'Active';
$string['statussuspended'] = 'Suspended';
$string['actions'] = 'Actions';
$string['regenerate'] = 'Regenerate token';
$string['enable'] = 'Enable';
$string['suspend'] = 'Suspend';
$string['clientsaved'] = 'Client saved.';
$string['clientdeleted'] = 'Client deleted.';
$string['tokenregenerated'] = 'A new token was generated for the client.';
$string['confirmdelete'] = 'Delete the client "{$a}" and its cohort scope? Any dashboard using its token will stop syncing. This cannot be undone.';
$string['noclients'] = 'No Partner API clients yet. Use "Add client" to create one.';
$string['nocohortsselected'] = 'Select at least one cohort.';
$string['missingcohort'] = '(deleted cohort)';

// Affiliation (student profile section + self-select chooser).
$string['affiliation'] = 'Affiliation';
$string['affiliations'] = 'Affiliations';
$string['affiliation_intro'] = 'Your affiliation links your Saylor activity to a partner organization. Choose your affiliation below if it is not already listed.';
$string['youraffiliations'] = 'Your affiliations';
$string['noaffiliation'] = 'No affiliation selected.';
$string['chooseaffiliation'] = 'Choose your affiliation';
$string['addaffiliation'] = 'Add affiliation';
$string['affiliationjoined'] = 'You have been added to "{$a}".';
$string['affiliationchoose_help'] = 'Select the organization you are affiliated with. This adds you to that partner so your progress is shared with them.';
$string['noaffiliationsavailable'] = 'There are no affiliations available to join right now.';
$string['affiliationalreadymember'] = 'You are already affiliated with "{$a}".';
$string['affiliationinvalid'] = 'That affiliation is not available.';

// Auto-affiliation by email domain.
$string['autoaffiliation'] = 'Auto-Affiliation';
$string['domainmap'] = 'Email domain → cohort mapping (JSON)';
$string['domainmap_desc'] = 'A JSON object mapping email domains to AFF- cohort IDs. When a user signs up or logs in with a matching email domain, they are automatically added to the specified cohort.<br><br>Example:<br><code>{"cnu.in.edu": 3, "cnu.edu": 3, "acme.org": 5}</code><br><br>Only cohorts whose idnumber starts with <code>AFF-</code> are eligible. Multiple domains can map to the same cohort.';
