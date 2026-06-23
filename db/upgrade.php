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
 * Upgrade steps for local_partnerapi.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin schema.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_partnerapi_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061800) {

        // Define table local_partnerapi_provenance to be created.
        $table = new xmldb_table('local_partnerapi_provenance');

        // Fields (mirror db/install.xml exactly).
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cohortid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        // Indexes — UNIQUE(userid, cohortid) named useridcohortid (matches install.xml).
        $table->add_index('useridcohortid', XMLDB_INDEX_UNIQUE, ['userid', 'cohortid']);

        // Conditionally create.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Idempotent backfill of determinable sources for existing members.
        require_once($CFG->dirroot . '/local/partnerapi/lib.php');
        local_partnerapi_run_backfill();

        // Partnerapi savepoint reached.
        upgrade_plugin_savepoint(true, 2026061800, 'local', 'partnerapi');
    }

    return true;
}
