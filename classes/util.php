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
 * Output helpers for the Partner API endpoints.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * JSON response helpers that use real HTTP status codes (not a WS envelope).
 */
class util {

    /**
     * Emit a JSON body with the given HTTP status and stop execution.
     *
     * @param mixed $data
     * @param int $status HTTP status code
     * @return void
     */
    public static function send_json($data, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data);
        exit;
    }

    /**
     * Emit a JSON error body with the given HTTP status and stop execution.
     *
     * @param int $status HTTP status code
     * @param string $message coarse, non-sensitive error message
     * @return void
     */
    public static function error(int $status, string $message): void {
        self::send_json(['error' => $message], $status);
    }
}
