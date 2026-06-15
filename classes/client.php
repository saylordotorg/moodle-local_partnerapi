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
 * Partner client token authentication and cohort-scope resolution.
 *
 * Security hardening:
 * - Token lookup uses hash_equals() for constant-time comparison.
 * - Failed attempts are rate-limited per IP (10 failures per 5 minutes).
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves and authenticates partner API clients.
 */
class client {

    /** Maximum failed auth attempts per IP within the rate window. */
    const RATE_LIMIT_MAX = 10;

    /** Rate limit window in seconds (5 minutes). */
    const RATE_LIMIT_WINDOW = 300;

    /**
     * Authenticate a presented token.
     *
     * Uses constant-time comparison (hash_equals) to prevent timing attacks.
     * Rate-limits failed attempts per IP address.
     *
     * @param string $token the value sent as the wstoken parameter
     * @return \stdClass|null the client record, or null when the token is unknown/suspended/rate-limited
     */
    public static function authenticate(string $token): ?\stdClass {
        global $DB;

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Rate limiting: check if this IP has too many recent failures.
        $ip = getremoteaddr();
        if (self::is_rate_limited($ip)) {
            return null;
        }

        // Fetch all active (non-suspended) clients and compare tokens in
        // constant time. This prevents timing-based token enumeration.
        // For sites with many clients, a hashed-token column would be more
        // efficient — but with <100 clients this is fine and maximally safe.
        $clients = $DB->get_records('local_partnerapi_clients', ['suspended' => 0]);

        $matched = null;
        foreach ($clients as $client) {
            // hash_equals: constant-time comparison prevents timing leaks.
            if (hash_equals($client->token, $token)) {
                $matched = $client;
                // Don't break — always iterate all clients for constant time.
            }
        }

        if ($matched === null) {
            // Record the failed attempt for rate limiting.
            self::record_failure($ip);
            return null;
        }

        // Successful auth — clear any failure count for this IP.
        self::clear_failures($ip);
        return $matched;
    }

    /**
     * Check if the given IP is rate-limited.
     *
     * Uses Moodle's cache API (application-level) for persistence across
     * requests without requiring a dedicated DB table.
     *
     * @param string $ip
     * @return bool true if rate-limited (too many failures)
     */
    private static function is_rate_limited(string $ip): bool {
        $cache = \cache::make('local_partnerapi', 'ratelimit');
        $key = 'fail_' . md5($ip);
        $data = $cache->get($key);
        if ($data === false) {
            return false;
        }
        return (int) $data >= self::RATE_LIMIT_MAX;
    }

    /**
     * Record a failed auth attempt for the given IP.
     *
     * @param string $ip
     */
    private static function record_failure(string $ip): void {
        $cache = \cache::make('local_partnerapi', 'ratelimit');
        $key = 'fail_' . md5($ip);
        $current = $cache->get($key);
        $count = ($current === false) ? 1 : (int) $current + 1;
        // Set with TTL via cache definition (defined in db/caches.php).
        $cache->set($key, $count);
    }

    /**
     * Clear failures for the given IP after a successful auth.
     *
     * @param string $ip
     */
    private static function clear_failures(string $ip): void {
        $cache = \cache::make('local_partnerapi', 'ratelimit');
        $key = 'fail_' . md5($ip);
        $cache->delete($key);
    }

    /**
     * Return the set of cohort ids a client is authorized to read.
     *
     * @param int $clientid
     * @return int[] list of Moodle cohort ids (may be empty)
     */
    public static function allowed_cohorts(int $clientid): array {
        global $DB;

        $ids = $DB->get_fieldset_select('local_partnerapi_cohorts', 'cohortid', 'clientid = ?', [$clientid]);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Generate a new opaque token (64 hex chars).
     *
     * @return string
     */
    public static function generate_token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Return all clients, each with a `cohortids` array attached.
     *
     * @return \stdClass[] keyed by client id
     */
    public static function get_all_clients(): array {
        global $DB;

        $clients = $DB->get_records('local_partnerapi_clients', null, 'name ASC');
        foreach ($clients as $client) {
            $client->cohortids = self::allowed_cohorts((int)$client->id);
        }
        return $clients;
    }

    /**
     * Create or update a client and replace its cohort scope.
     *
     * A new client gets a freshly generated token; editing an existing client
     * leaves its token unchanged (use {@see regenerate_token()} to rotate it).
     *
     * @param \stdClass $data form data with optional ->id, ->name, ->suspended
     * @param int[] $cohortids the cohort ids the client may read
     * @return int the client id
     */
    public static function save_client(\stdClass $data, array $cohortids): int {
        global $DB;

        $now = time();
        $cohortids = array_values(array_unique(array_map('intval', $cohortids)));

        $transaction = $DB->start_delegated_transaction();

        if (!empty($data->id)) {
            $record = $DB->get_record('local_partnerapi_clients', ['id' => $data->id], '*', MUST_EXIST);
            $record->name = $data->name;
            $record->suspended = empty($data->suspended) ? 0 : 1;
            $record->timemodified = $now;
            $DB->update_record('local_partnerapi_clients', $record);
            $DB->delete_records('local_partnerapi_cohorts', ['clientid' => $record->id]);
            $clientid = (int)$record->id;
        } else {
            $record = new \stdClass();
            $record->name = $data->name;
            $record->token = self::generate_token();
            $record->suspended = empty($data->suspended) ? 0 : 1;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $clientid = (int)$DB->insert_record('local_partnerapi_clients', $record);
        }

        foreach ($cohortids as $cohortid) {
            if ($cohortid > 0) {
                $DB->insert_record('local_partnerapi_cohorts', (object)[
                    'clientid' => $clientid,
                    'cohortid' => $cohortid,
                ]);
            }
        }

        $transaction->allow_commit();
        return $clientid;
    }

    /**
     * Replace a client's token with a freshly generated one.
     *
     * @param int $clientid
     * @return string the new token
     */
    public static function regenerate_token(int $clientid): string {
        global $DB;

        $token = self::generate_token();
        $DB->set_field('local_partnerapi_clients', 'token', $token, ['id' => $clientid]);
        $DB->set_field('local_partnerapi_clients', 'timemodified', time(), ['id' => $clientid]);
        return $token;
    }

    /**
     * Suspend or re-enable a client token.
     *
     * @param int $clientid
     * @param bool $suspended
     * @return void
     */
    public static function set_suspended(int $clientid, bool $suspended): void {
        global $DB;

        $DB->set_field('local_partnerapi_clients', 'suspended', $suspended ? 1 : 0, ['id' => $clientid]);
        $DB->set_field('local_partnerapi_clients', 'timemodified', time(), ['id' => $clientid]);
    }

    /**
     * Delete a client and its cohort scope.
     *
     * @param int $clientid
     * @return void
     */
    public static function delete_client(int $clientid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_partnerapi_cohorts', ['clientid' => $clientid]);
        $DB->delete_records('local_partnerapi_clients', ['id' => $clientid]);
        $transaction->allow_commit();
    }
}
