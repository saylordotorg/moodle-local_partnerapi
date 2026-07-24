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

// phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Bootstrap includes config.php.
require(__DIR__ . '/../bootstrap.php');

defined('MOODLE_INTERNAL') || die();

use local_partnerapi\util;

global $DB;

// Standard user fields (always available).
$standard = [
    ['name' => 'firstname', 'label' => get_string('profilefield:firstname', 'local_partnerapi'),
        'type' => 'text', 'required' => true],
    ['name' => 'lastname', 'label' => get_string('profilefield:lastname', 'local_partnerapi'),
        'type' => 'text', 'required' => true],
    ['name' => 'email', 'label' => get_string('profilefield:email', 'local_partnerapi'),
        'type' => 'email', 'required' => true],
    ['name' => 'password', 'label' => get_string('profilefield:password', 'local_partnerapi'),
        'type' => 'password', 'required' => true],
    ['name' => 'city', 'label' => get_string('profilefield:city', 'local_partnerapi'),
        'type' => 'text', 'required' => false],
    ['name' => 'country', 'label' => get_string('profilefield:country', 'local_partnerapi'),
        'type' => 'country', 'required' => false],
];

// Custom profile fields configured on this site.
$customfields = $DB->get_records('user_info_field', null, 'sortorder ASC');
$custom = [];
foreach ($customfields as $f) {
    $custom[] = [
        'shortname' => $f->shortname,
        'name' => format_string($f->name),
        'type' => $f->datatype, // Text, menu, checkbox, textarea, or datetime.
        'required' => (int) $f->required === 1,
        'options' => $f->datatype === 'menu' ? explode("\n", $f->param1 ?? '') : null,
    ];
}

util::send_json([
    'standard' => $standard,
    'custom' => $custom,
]);
