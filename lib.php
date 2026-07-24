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
 * Public library hooks for local_partnerapi.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The idnumber prefix that marks a cohort as a partner affiliation.
 */
const LOCAL_PARTNERAPI_AFFILIATION_PREFIX = 'AFF-';

/**
 * Return the partner-affiliation cohorts a user belongs to.
 *
 * A cohort counts as an affiliation only when its idnumber starts with the
 * `AFF-` prefix, so general cohorts (e.g. "Pre-MBA") are never treated as
 * partners.
 *
 * @param int $userid
 * @return array list of {id, name} cohort records
 */
function local_partnerapi_user_affiliations(int $userid): array {
    global $DB;
    $sql = "SELECT c.id, c.name
              FROM {cohort} c
              JOIN {cohort_members} cm ON cm.cohortid = c.id
             WHERE cm.userid = :userid
               AND " . $DB->sql_like('c.idnumber', ':aff', false) . "
          ORDER BY c.name ASC";
    return array_values($DB->get_records_sql($sql, [
        'userid' => $userid,
        'aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%',
    ]));
}

/**
 * Return a cohort when it is available for self-service affiliation.
 *
 * @param int $cohortid Cohort id to validate.
 * @return stdClass|null The visible AFF- cohort, or null when it is not eligible.
 */
function local_partnerapi_get_self_service_affiliation(int $cohortid): ?stdClass {
    global $DB;

    if ($cohortid <= 0) {
        return null;
    }

    $cohort = $DB->get_record('cohort', ['id' => $cohortid, 'visible' => 1], '*', IGNORE_MISSING);
    if (!$cohort || stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
        return null;
    }

    return $cohort;
}

/**
 * Check whether the current user may edit a target user's affiliation.
 *
 * Learners may manage their own affiliation. Editing another user's cohort
 * membership additionally requires Moodle's cohort assignment capability.
 *
 * @param int $targetuserid User whose affiliation would be changed, or 0 for a new user.
 * @return bool Whether the current user may change the affiliation.
 */
function local_partnerapi_can_edit_affiliation(int $targetuserid): bool {
    global $USER;

    if ($targetuserid > 0 && (int) $USER->id === $targetuserid && !isguestuser()) {
        return true;
    }

    return has_capability('moodle/cohort:assign', context_system::instance());
}

/**
 * Extend global navigation to register the affiliation chooser page so that
 * Moodle Workplace themes do not 404 it.
 *
 * @param global_navigation $navigation
 */
function local_partnerapi_extend_navigation(global_navigation $navigation) {
    // No-op: we just need the function to exist so Moodle recognizes lib.php hooks.
    unset($navigation);
}

/**
 * Add an "Affiliation" dropdown to the email signup form so new users can
 * choose their partner organization at registration time.
 *
 * Also adds:
 * - A data-sharing disclaimer below the dropdown.
 * - A dynamic email-domain disclosure (rendered via JS) that warns the user
 *   their email domain is linked to a partner.
 *
 * Standard Moodle signup extension hook.
 *
 * @param MoodleQuickForm $mform
 */
function local_partnerapi_extend_signup_form($mform) {
    global $DB;

    $params = ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%'];
    $cohorts = $DB->get_records_sql(
        "SELECT c.id, c.name
           FROM {cohort} c
          WHERE c.visible = 1 AND " . $DB->sql_like('c.idnumber', ':aff', false) . "
       ORDER BY c.name ASC",
        $params
    );

    if (empty($cohorts)) {
        return; // No affiliations available — don't show the field.
    }

    $options = ['' => get_string('none')]; // Default: no affiliation (optional).
    foreach ($cohorts as $c) {
        $options[$c->id] = format_string($c->name);
    }

    $mform->addElement(
        'header',
        'local_partnerapi_signup_header',
        get_string('affiliation', 'local_partnerapi')
    );

    $mform->addElement(
        'select',
        'local_partnerapi_affiliation',
        get_string('chooseaffiliation', 'local_partnerapi'),
        $options
    );
    $mform->addHelpButton('local_partnerapi_affiliation', 'affiliationchoose', 'local_partnerapi');
    $mform->setType('local_partnerapi_affiliation', PARAM_INT);

    // Data-sharing disclaimer (shown only when an affiliation is selected) and
    // a confirmation prompt on submit so the choice is deliberate — some users
    // were selecting a partner thinking they were "applying" to it.
    $confirmtpl = addslashes_js(get_string('affiliation_confirm', 'local_partnerapi', '{PARTNER}'));
    $mform->addElement(
        'static',
        'local_partnerapi_disclaimer',
        '',
        '<div id="local_partnerapi_aff_disclaimer" class="alert alert-info mt-2" role="status" ' .
        'aria-live="polite" style="font-size: 0.85rem; display: none;">' .
        '<strong>' . get_string('domain_disclosure_heading', 'local_partnerapi') . ':</strong> ' .
        get_string('affiliation_disclaimer', 'local_partnerapi') .
        '</div>' .
        '<script>' .
        '(function(){' .
        '  var sel = document.getElementById("id_local_partnerapi_affiliation");' .
        '  var box = document.getElementById("local_partnerapi_aff_disclaimer");' .
        '  if(!sel||!box) return;' .
        '  function toggle(){ box.style.display = sel.value ? "block" : "none"; }' .
        '  sel.addEventListener("change", toggle);' .
        '  toggle();' .
        '  var form = sel.form;' .
        '  if(form){' .
        '    form.addEventListener("submit", function(e){' .
        '      if(!sel.value) return;' .
        '      var name = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : "";' .
        '      var msg = "' . $confirmtpl . '".replace("{PARTNER}", name);' .
        '      if(!window.confirm(msg)){ e.preventDefault(); e.stopPropagation(); }' .
        '    });' .
        '  }' .
        '})();' .
        '</script>'
    );

    local_partnerapi_add_domain_disclosure($mform);
}

/**
 * Add the signup disclosure for configured email-domain affiliations.
 *
 * @param MoodleQuickForm $mform Signup form.
 * @return void
 */
function local_partnerapi_add_domain_disclosure($mform): void {
    global $DB;

    // Dynamic email-domain disclosure container populated by JavaScript below.
    $mform->addElement(
        'static',
        'local_partnerapi_domain_notice',
        '',
        '<div id="local_partnerapi_domain_notice" role="status" aria-live="polite" style="display:none;"></div>'
    );

    // Add JavaScript that watches the email field and shows a disclosure if the
    // domain matches a configured auto-affiliation domain.
    $domainmapjson = get_config('local_partnerapi', 'domain_cohort_map');
    $domainmap = !empty($domainmapjson) ? json_decode($domainmapjson, true) : [];

    // Build a JavaScript-friendly map from domain to partner name.
    $domaintopartner = [];
    foreach ($domainmap as $domain => $cohortid) {
        $cohort = $DB->get_record('cohort', ['id' => (int) $cohortid], 'name', IGNORE_MISSING);
        if ($cohort) {
            $domaintopartner[strtolower($domain)] = format_string($cohort->name);
        }
    }

    if (!empty($domaintopartner)) {
        $jsoptions = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $jsmap = json_encode($domaintopartner, $jsoptions);
        $heading = get_string('domain_disclosure_heading', 'local_partnerapi');
        $disclosure = get_string('domain_disclosure', 'local_partnerapi', (object) [
            'email' => '{EMAIL}',
            'partner' => '{PARTNER}',
        ]);
        $jsheading = json_encode($heading, $jsoptions);
        $jsdisclosure = json_encode($disclosure, $jsoptions);

        $js = <<<JS
<script>
(function() {
    var map = {$jsmap};
    var heading = {$jsheading};
    var disclosure = {$jsdisclosure};
    var container = document.getElementById('local_partnerapi_domain_notice');
    var emailField = document.getElementById('id_email');
    if (!emailField || !container) return;

    function clearNotice() {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    }

    function hideNotice() {
        clearNotice();
        container.style.display = 'none';
    }

    function showNotice(email, partner) {
        clearNotice();
        var notice = document.createElement('div');
        notice.className = 'alert alert-warning';
        notice.style.fontSize = '0.85rem';
        var title = document.createElement('strong');
        title.textContent = heading + ':';
        notice.appendChild(title);
        var message = disclosure.replace('{PARTNER}', partner).replace('{EMAIL}', email);
        notice.appendChild(document.createTextNode(' ' + message));
        container.appendChild(notice);
        container.style.display = 'block';
    }

    function check() {
        var email = (emailField.value || '').trim().toLowerCase();
        var at = email.indexOf('@');
        if (at < 1) { hideNotice(); return; }
        var domain = email.substring(at + 1);
        if (Object.prototype.hasOwnProperty.call(map, domain)) {
            showNotice(email, map[domain]);
        } else {
            hideNotice();
        }
    }

    emailField.addEventListener('input', check);
    emailField.addEventListener('change', check);
    check();
})();
</script>
JS;
        $mform->addElement('static', 'local_partnerapi_domain_js', '', $js);
    }
}

/**
 * Process the affiliation selection after a new account is created.
 *
 * Moodle calls this hook (via core_login_post_signup_requests) with the
 * full user object after account creation. The custom form field
 * `local_partnerapi_affiliation` is available as a property on the object
 * because Moodle merges all form fields into the user data.
 *
 * @param stdClass $data the user/form data object (includes ->id and custom fields)
 */
function local_partnerapi_post_signup_requests($data) {
    global $DB;
    require_once(__DIR__ . '/../../cohort/lib.php');

    $cohortid = !empty($data->local_partnerapi_affiliation)
        ? (int) $data->local_partnerapi_affiliation
        : 0;

    if ($cohortid <= 0) {
        return; // No selection made — this is the normal case for most students.
    }

    $userid = (int) ($data->id ?? 0);
    if ($userid <= 0) {
        return;
    }

    $cohort = local_partnerapi_get_self_service_affiliation($cohortid);
    if (!$cohort) {
        return;
    }

    // An explicit signup choice replaces any domain-derived affiliation.
    foreach (local_partnerapi_user_affiliations($userid) as $existing) {
        if ((int) $existing->id !== $cohortid) {
            cohort_remove_member((int) $existing->id, $userid);
        }
    }

    if (!$DB->record_exists('cohort_members', ['cohortid' => $cohortid, 'userid' => $userid])) {
        cohort_add_member($cohortid, $userid);
        \local_partnerapi\provenance::record($userid, $cohortid, \local_partnerapi\provenance::SOURCE_SIGNUP);
    }
}

/**
 * Add an "Affiliation" section to the user edit form with a multi-select of
 * available AFF- cohorts. This is the primary self-service mechanism for
 * learners to declare their partner affiliation.
 *
 * Moodle calls this hook on the user edit / editadvanced pages.
 *
 * @param MoodleQuickForm $mform
 * @param stdClass $user
 */
function local_partnerapi_user_edit_form_definition($mform, $user) {
    global $DB;

    $targetuserid = (int) ($user->id ?? 0);
    if (!local_partnerapi_can_edit_affiliation($targetuserid)) {
        return;
    }

    $mform->addElement(
        'header',
        'local_partnerapi_affiliation_header',
        get_string('affiliation', 'local_partnerapi')
    );

    // Build the list of available AFF- cohorts.
    $params = ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%'];
    $all = $DB->get_records_sql(
        "SELECT c.id, c.name
           FROM {cohort} c
          WHERE c.visible = 1 AND " . $DB->sql_like('c.idnumber', ':aff', false) . "
       ORDER BY c.name ASC",
        $params
    );

    if (empty($all)) {
        $mform->addElement(
            'static',
            'local_partnerapi_noaff',
            '',
            get_string('noaffiliationsavailable', 'local_partnerapi')
        );
        return;
    }

    // The "None" option means no affiliation.
    $options = ['' => get_string('none')];
    foreach ($all as $c) {
        $options[$c->id] = format_string($c->name);
    }

    $mform->addElement(
        'select',
        'local_partnerapi_affiliations',
        get_string('affiliations', 'local_partnerapi'),
        $options
    );
    // Limit to one affiliation (single select, not multiple).
    $mform->addHelpButton('local_partnerapi_affiliations', 'affiliationchoose', 'local_partnerapi');

    // Data-sharing disclaimer.
    $mform->addElement(
        'static',
        'local_partnerapi_edit_disclaimer',
        '',
        '<div class="alert alert-info mt-2" style="font-size: 0.85rem;">' .
        '<strong>' . get_string('domain_disclosure_heading', 'local_partnerapi') . ':</strong> ' .
        get_string('affiliation_disclaimer', 'local_partnerapi') .
        '</div>'
    );

    // Pre-select the current single affiliation (or "None").
    if (!empty($user->id)) {
        $current = local_partnerapi_user_affiliations((int) $user->id);
        $currentid = !empty($current) ? (string) reset($current)->id : '';
        $mform->setDefault('local_partnerapi_affiliations', $currentid);
    }
}

/**
 * Save the affiliation selections from the user edit form.
 *
 * Called by Moodle after the form is submitted and validated.
 *
 * @param stdClass $user
 * @param stdClass $usernew the submitted form data
 */
function local_partnerapi_user_edit_form_save($user, $usernew) {
    global $DB;
    require_once(__DIR__ . '/../../cohort/lib.php');

    $selected = $usernew->local_partnerapi_affiliations ?? '';
    // Single select: value is a single cohort id (or empty string for "None").
    $selectedid = (int) $selected;
    $userid = (int) ($usernew->id ?? $user->id ?? 0);
    if ($userid <= 0) {
        return;
    }
    if (!local_partnerapi_can_edit_affiliation($userid)) {
        return;
    }

    // Only operate on valid AFF- cohorts.
    $params = ['aff' => LOCAL_PARTNERAPI_AFFILIATION_PREFIX . '%'];
    $allaffiliations = $DB->get_fieldset_sql(
        "SELECT c.id FROM {cohort} c WHERE c.visible = 1 AND " . $DB->sql_like('c.idnumber', ':aff', false),
        $params
    );
    $validids = array_map('intval', $allaffiliations);

    // Preserve the current membership when a crafted request submits an
    // ineligible or hidden cohort id. An empty value intentionally removes it.
    if ($selectedid > 0 && !in_array($selectedid, $validids, true)) {
        return;
    }

    // Current AFF- memberships.
    $current = array_map(fn($c) => (int) $c->id, local_partnerapi_user_affiliations($userid));

    // Remove all existing AFF- memberships (limit to 1 rule).
    foreach ($current as $cid) {
        if ($cid !== $selectedid) {
            cohort_remove_member($cid, $userid);
        }
    }

    // Add the selected one (if valid and not already a member).
    if ($selectedid > 0 && !in_array($selectedid, $current, true)) {
        cohort_add_member($selectedid, $userid);
        \local_partnerapi\provenance::record($userid, $selectedid, \local_partnerapi\provenance::SOURCE_SELF);
    }
}

/**
 * Add an "Affiliation" category to the user profile page listing the partner
 * cohorts (idnumber starting `AFF-`) the user belongs to, plus a link to the
 * self-select chooser for the current user.
 *
 * Standard Moodle myprofile callback.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_partnerapi_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    // The callback signature includes the current course, but affiliation data is site-wide.
    unset($course);

    $category = new \core_user\output\myprofile\category(
        'local_partnerapi_affiliation',
        get_string('affiliation', 'local_partnerapi'),
        null
    );
    $tree->add_category($category);

    $affiliations = local_partnerapi_user_affiliations((int) $user->id);

    if (!empty($affiliations)) {
        foreach ($affiliations as $a) {
            $tree->add_node(new \core_user\output\myprofile\node(
                'local_partnerapi_affiliation',
                'local_partnerapi_affiliation_' . $a->id,
                format_string($a->name),
                null,
                null
            ));
        }
    } else {
        $tree->add_node(new \core_user\output\myprofile\node(
            'local_partnerapi_affiliation',
            'local_partnerapi_affiliation_none',
            get_string('noaffiliation', 'local_partnerapi'),
            null,
            null
        ));
    }

    // Only the owner can change their own affiliation.
    if ($iscurrentuser && !isguestuser()) {
        $tree->add_node(new \core_user\output\myprofile\node(
            'local_partnerapi_affiliation',
            'local_partnerapi_affiliation_choose',
            get_string('chooseaffiliation', 'local_partnerapi'),
            null,
            new \moodle_url('/local/partnerapi/affiliation.php')
        ));
    }
}

/**
 * One-time, idempotent backfill of provenance for existing AFF- cohort members
 * whose source is determinable from the configured `domain_cohort_map`.
 *
 * For every configured `domain => cohortid` entry that points at an `AFF-`
 * cohort, this finds the cohort's members whose `user.email` domain matches the
 * configured domain (case-insensitive equality, mirroring the observer) and
 * records `signup_partner_choice` provenance for each (Req 3.1, 3.2).
 *
 * Members whose source cannot be determined this way get no provenance row
 * (Req 3.3). Idempotence and never-downgrade are guaranteed by
 * {@see \local_partnerapi\provenance::record()} itself, so this routine adds no
 * extra dedupe logic and is safe to run repeatedly (Req 3.4).
 *
 * @return int number of provenance rows recorded (for CLI/reporting)
 */
function local_partnerapi_run_backfill(): int {
    // Defensive JSON parse — identical semantics to observer::get_domain_mappings().
    $json = get_config('local_partnerapi', 'domain_cohort_map');
    if (empty($json)) {
        return 0;
    }
    $map = json_decode($json, true);
    if (!is_array($map) || empty($map)) {
        return 0;
    }

    $count = 0;
    foreach ($map as $domain => $cohortid) {
        $domain = strtolower(trim((string) $domain));
        $cohortid = (int) $cohortid;
        $count += local_partnerapi_backfill_mapping($domain, $cohortid);
    }

    return $count;
}

/**
 * Backfill one valid domain-to-affiliation mapping.
 *
 * @param string $domain Normalized email domain.
 * @param int $cohortid Mapped cohort id.
 * @return int Number of matching members processed.
 */
function local_partnerapi_backfill_mapping(string $domain, int $cohortid): int {
    global $DB;

    if ($domain === '' || $cohortid <= 0) {
        return 0;
    }
    $cohort = $DB->get_record('cohort', ['id' => $cohortid], 'id, idnumber');
    if (!$cohort || stripos((string) $cohort->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
        return 0;
    }

    $records = $DB->get_recordset_sql(
        "SELECT u.id, u.email
           FROM {cohort_members} cm
           JOIN {user} u ON u.id = cm.userid
          WHERE cm.cohortid = :cohortid
            AND u.deleted = 0",
        ['cohortid' => $cohortid]
    );
    $count = 0;
    foreach ($records as $user) {
        if (local_partnerapi_email_domain((string) $user->email) !== $domain) {
            continue;
        }
        \local_partnerapi\provenance::record(
            (int) $user->id,
            $cohortid,
            \local_partnerapi\provenance::SOURCE_SIGNUP
        );
        $count++;
    }
    $records->close();
    return $count;
}

/**
 * Extract a normalized domain from an email address.
 *
 * @param string $email Email address.
 * @return string|null Normalized domain, or null for a malformed address.
 */
function local_partnerapi_email_domain(string $email): ?string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return null;
    }
    $domain = strtolower(trim($parts[1]));
    return $domain === '' ? null : $domain;
}
