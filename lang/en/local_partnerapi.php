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

$string['actions'] = 'Actions';
$string['addaffiliation'] = 'Add affiliation';
$string['addclient'] = 'Add client';
$string['addmapping'] = 'Add mapping';
$string['affiliation'] = 'Affiliation';
$string['affiliation_confirm'] = 'You have chosen to affiliate with {$a}. Your name, email address, and learning progress will be shared with this organization. Continue with this affiliation?';
$string['affiliation_disclaimer'] = 'By selecting an affiliation, you consent to sharing your course progress, released grades, and activity data with that partner organization. Your partner uses this data to support your academic journey.';
$string['affiliation_intro'] = 'Your affiliation links your Saylor activity to a partner organization. Choose your affiliation below if it is not already listed.';
$string['affiliationalreadymember'] = 'You are already affiliated with "{$a}".';
$string['affiliationchoose_help'] = 'Select the organization you are affiliated with. This adds you to that partner so your progress is shared with them.';
$string['affiliationinvalid'] = 'That affiliation is not available.';
$string['affiliationjoined'] = 'You have been added to "{$a}".';
$string['affiliationleave'] = 'Leave affiliation';
$string['affiliationremoved'] = 'You have been removed from "{$a}".';
$string['affiliations'] = 'Affiliations';
$string['autoaffiliation'] = 'Auto-affiliation';
$string['chooseaffiliation'] = 'Choose your affiliation';
$string['chooseoption'] = 'Choose...';
$string['clientdeleted'] = 'Client deleted.';
$string['clientname'] = 'Client name';
$string['clientsaved'] = 'Client saved.';
$string['cohortmissing'] = 'Cohort #{$a} (not found)';
$string['cohorts'] = 'Cohorts';
$string['cohorts_help'] = 'The cohorts this client is allowed to read. The token can only return learners and their enrolments, released grades, and access logs for these cohorts.';
$string['confirmdelete'] = 'Delete the client "{$a}" and its cohort scope? Any dashboard using its token will stop syncing. This cannot be undone.';
$string['domain_disclosure'] = 'Your email address ({$a->email}) is associated with <strong>{$a->partner}</strong>. Your course progress, released grades, and activity data will be shared with this partner organization to support your academic journey. If you do not wish to share your data, please use a different email address to register.';
$string['domain_disclosure_heading'] = 'Data sharing notice';
$string['domainmap'] = 'Email domain to cohort mapping (JSON)';
$string['domainmap_desc'] = 'A JSON object mapping email domains to AFF- cohort IDs. When a user signs up or logs in with a matching email domain, they are automatically added to the specified cohort. Only AFF- cohorts are eligible.';
$string['domainmap_desc_ui'] = 'Map email domains to partner affiliations. When a student signs up or logs in with a matching email domain, they are automatically added to that affiliation. Only cohorts with an AFF- identifier are available.';
$string['domainmapinvalidcohort'] = 'Selected cohort is not a valid AFF- affiliation.';
$string['domainmapinvaliddomain'] = 'Invalid domain format (for example, example.edu).';
$string['domainplaceholder'] = 'e.g. cnu.edu';
$string['emaildomain'] = 'Email domain';
$string['enable'] = 'Enable';
$string['error:invalidpagination'] = 'Pagination is invalid. Page must be zero or greater and perpage must be between 1 and {$a}.';
$string['error:invalidtimerange'] = 'The time range must be valid and no longer than 366 days.';
$string['error:toomanycohortids'] = 'A maximum of {$a} cohort IDs is allowed per request.';
$string['error:toomanyuserids'] = 'A maximum of {$a} user IDs is allowed per request.';
$string['manage_intro'] = 'Partner API clients are scoped tokens used by the Partner Dashboard sync service. Each client can read only the cohorts assigned to it. Treat tokens as secrets and transmit them over HTTPS only.';
$string['manageclients'] = 'Partner API clients';
$string['missingcohort'] = '(deleted cohort)';
$string['noaffcohorts'] = 'No AFF- cohorts are available. Create one first.';
$string['noaffiliation'] = 'No affiliation selected.';
$string['noaffiliationsavailable'] = 'There are no affiliations available to join right now.';
$string['noclients'] = 'No Partner API clients yet. Use "Add client" to create one.';
$string['nocohortsselected'] = 'Select at least one cohort.';
$string['nodomainmappings'] = 'No domain mappings are configured yet.';
$string['partnerapi:manage'] = 'Manage Partner API clients and cohort scopes';
$string['pluginname'] = 'Partner API';
$string['privacy:metadata:partner'] = 'Authorized external partners receive only data for cohorts explicitly assigned to their scoped API client.';
$string['privacy:metadata:partner:activity'] = 'Access dates, activity counts, certificate records, and bounded time-in-course estimates.';
$string['privacy:metadata:partner:affiliation'] = 'Cohort membership, affiliation date, and affiliation provenance.';
$string['privacy:metadata:partner:assessment'] = 'Grades and quiz scores only after Moodle visibility and review-release rules allow disclosure.';
$string['privacy:metadata:partner:identity'] = 'User id, name, and email address.';
$string['privacy:metadata:partner:learning'] = 'Course enrolment, progress, and completion information.';
$string['privacy:metadata:provenance'] = 'How a user became affiliated with a partner cohort.';
$string['privacy:metadata:provenance:cohortid'] = 'The partner affiliation cohort id.';
$string['privacy:metadata:provenance:source'] = 'The source of the affiliation decision.';
$string['privacy:metadata:provenance:timecreated'] = 'When the affiliation provenance was first recorded.';
$string['privacy:metadata:provenance:timemodified'] = 'When the affiliation provenance was last updated.';
$string['privacy:metadata:provenance:userid'] = 'The user associated with the affiliation.';
$string['privacy:path:provenance'] = 'Partner affiliations';
$string['profilefield:city'] = 'City/town';
$string['profilefield:country'] = 'Country';
$string['profilefield:email'] = 'Email address';
$string['profilefield:firstname'] = 'First name';
$string['profilefield:lastname'] = 'Last name';
$string['profilefield:password'] = 'Password';
$string['regenerate'] = 'Regenerate token';
$string['status'] = 'Status';
$string['statusactive'] = 'Active';
$string['statussuspended'] = 'Suspended';
$string['suspend'] = 'Suspend';
$string['suspended'] = 'Suspended';
$string['token'] = 'Token';
$string['tokenregenerated'] = 'A new token was generated for the client.';
$string['youraffiliations'] = 'Your affiliations';
