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
 * GET /local/partnerapi/v1/profilefields/
 *
 * Returns the list of standard Moodle profile fields and custom profile fields
 * available on this site. Used by the partner dashboard form builder so partners
 * know what fields they can include in their registration forms.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../bootstrap.php');

use local_partnerapi\util;

global $DB;

// Standard user fields (always available).
$standard = [
    ['name' => 'firstname', 'label' => 'First name', 'type' => 'text', 'required' => true],
    ['name' => 'lastname', 'label' => 'Last name', 'type' => 'text', 'required' => true],
    ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true],
    ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
    ['name' => 'city', 'label' => 'City/Town', 'type' => 'text', 'required' => false],
    ['name' => 'country', 'label' => 'Country', 'type' => 'country', 'required' => false],
];

// Custom profile fields configured on this site.
$customfields = $DB->get_records('user_info_field', null, 'sortorder ASC');
$custom = [];
foreach ($customfields as $f) {
    $custom[] = [
        'shortname' => $f->shortname,
        'name' => format_string($f->name),
        'type' => $f->datatype, // text, menu, checkbox, textarea, datetime
        'required' => (int) $f->required === 1,
        'options' => $f->datatype === 'menu' ? explode("\n", $f->param1 ?? '') : null,
    ];
}

util::send_json([
    'standard' => $standard,
    'custom' => $custom,
]);
