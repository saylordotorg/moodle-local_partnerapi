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
 * Affiliation source provenance for AFF- cohort memberships.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Records and resolves how a learner became affiliated with an AFF- cohort.
 *
 * Source precedence (highest wins): partner_registration_link > signup_partner_choice
 * > self_affiliated. A stronger source is never overwritten by a weaker one.
 */
class provenance {

    /** @var int Maximum ids per IN() chunk to stay within DB bind limits. */
    const CHUNK = 1000;

    /** @var string Affiliated via the hosted registration form (/v1/register). Highest precedence. */
    const SOURCE_REGISTRATION = 'partner_registration_link';

    /** @var string Affiliated via domain auto-affiliation (the observer) at signup. */
    const SOURCE_SIGNUP = 'signup_partner_choice';

    /** @var string Affiliated via self-service affiliation (affiliation.php). Lowest precedence. */
    const SOURCE_SELF = 'self_affiliated';

    /**
     * Pure: precedence rank of a source. Higher wins. Unknown/invalid -> 0.
     * partner_registration_link = 3, signup_partner_choice = 2, self_affiliated = 1.
     *
     * @param string|null $source One of the SOURCE_* constants, or null/invalid.
     * @return int Precedence rank: 3, 2, 1, or 0 for null/unknown/empty.
     */
    public static function rank(?string $source): int {
        switch ($source) {
            case self::SOURCE_REGISTRATION:
                return 3;
            case self::SOURCE_SIGNUP:
                return 2;
            case self::SOURCE_SELF:
                return 1;
            default:
                return 0;
        }
    }

    /**
     * Pure: merge an incoming source into an existing one without downgrading.
     * Returns the higher-precedence of the two; a null/invalid incoming never
     * overwrites a valid existing value. Mirrors the dashboard merge.
     *
     * Invalid values are normalized to null on output, so an invalid string
     * never leaks: when the winning value has rank 0, null is returned.
     *
     * @param string|null $existing The currently stored source, or null/invalid.
     * @param string|null $incoming The source being applied, or null/invalid.
     * @return string|null One of the SOURCE_* constants, or null.
     */
    public static function merge_source(?string $existing, ?string $incoming): ?string {
        // Higher rank wins; on a tie keep existing so a weaker incoming never downgrades.
        $winner = self::rank($incoming) > self::rank($existing) ? $incoming : $existing;
        // Normalize invalid/unknown (rank 0) to null so invalid values never leak.
        return self::rank($winner) === 0 ? null : $winner;
    }

    /**
     * I/O: record provenance for one (userid, cohortid). Idempotent and
     * precedence-merged. Best-effort: catches its own exceptions, logs via
     * debugging(), and never throws to the caller (Req 5.3).
     *
     * Inserts a row when none exists; otherwise raises the stored source via
     * merge_source() only when the incoming value has higher precedence,
     * bumping timemodified. A source outside the three SOURCE_* constants is
     * rejected before any DB access. The UNIQUE(userid, cohortid) index makes a
     * concurrent first-insert race surface as a duplicate-key exception, which
     * the catch turns into a no-op, preserving idempotence (Req 1.5).
     *
     * @param int $userid Moodle user.id.
     * @param int $cohortid Moodle cohort.id of an AFF- cohort.
     * @param string $source One of the SOURCE_* constants; anything else is ignored.
     * @return void
     */
    public static function record(int $userid, int $cohortid, string $source): void {
        global $DB;

        // Defensive: never store a value outside the three determinable sources.
        if (self::rank($source) === 0) {
            return;
        }

        try {
            $existing = $DB->get_record('local_partnerapi_provenance',
                ['userid' => $userid, 'cohortid' => $cohortid]);

            if (!$existing) {
                $now = time();
                $DB->insert_record('local_partnerapi_provenance', (object)[
                    'userid'       => $userid,
                    'cohortid'     => $cohortid,
                    'source'       => $source,
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ]);
                return;
            }

            // Only update when the merge raises the stored value; never downgrade.
            $merged = self::merge_source($existing->source, $source);
            if ($merged !== null && $merged !== $existing->source) {
                $DB->update_record('local_partnerapi_provenance', (object)[
                    'id'           => $existing->id,
                    'source'       => $merged,
                    'timemodified' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            // Provenance is metadata and must never break the affiliation flow.
            debugging('local_partnerapi provenance::record failed: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }
    }

    /**
     * I/O: stored source for one (userid, cohortid), or null when none.
     *
     * The stored value is normalized through rank() so an invalid value that
     * somehow reached the table (rank 0) never leaks to the caller: only one
     * of the three SOURCE_* constants, or null, is returned (Req 2.1–2.3).
     *
     * @param int $userid Moodle user.id.
     * @param int $cohortid Moodle cohort.id.
     * @return string|null One of the SOURCE_* constants, or null.
     */
    public static function get_source(int $userid, int $cohortid): ?string {
        global $DB;

        $row = $DB->get_record('local_partnerapi_provenance',
            ['userid' => $userid, 'cohortid' => $cohortid]);

        if (!$row) {
            return null;
        }

        // Never let an invalid stored value (rank 0) leak through as the API value.
        return self::rank($row->source) > 0 ? $row->source : null;
    }

    /**
     * I/O: resolve a single affiliation source per user, restricted to
     * provenance rows that belong to an `AFF-` cohort.
     *
     * Mirrors the conventions in repository.php: input ids are cleaned to
     * unique positive ints, lookups are chunked to stay within DB bind limits,
     * and the AFF- prefix is matched case-insensitively in PHP via stripos()
     * (LOCAL_PARTNERAPI_AFFILIATION_PREFIX from lib.php) to match exactly how
     * get_learners() classifies affiliation cohorts.
     *
     * When a user has provenance rows for more than one AFF- cohort, the
     * highest-`rank` value wins (never-downgrade, consistent with
     * merge_source()). Stored values of rank 0 are ignored, and a user with no
     * valid AFF- provenance row is simply omitted from the result.
     *
     * @param int[] $userids Moodle user.ids.
     * @return array<int,string> map userid => source (one SOURCE_* constant each).
     */
    public static function sources_for_users(array $userids): array {
        global $DB, $CFG;

        // The AFF- prefix constant lives in the plugin lib; the v1 bootstrap
        // loads config.php but not lib.php, so require it here (as repository.php does).
        require_once($CFG->dirroot . '/local/partnerapi/lib.php');

        // Clean to unique positive ints (mirrors repository::clean_ids).
        $clean = [];
        foreach ($userids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        if (empty($clean)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($clean, self::CHUNK) as $chunk) {
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rs = $DB->get_recordset_sql(
                "SELECT p.id, p.userid, p.source, c.idnumber
                   FROM {local_partnerapi_provenance} p
                   JOIN {cohort} c ON c.id = p.cohortid
                  WHERE p.userid $uin",
                $uparams
            );
            foreach ($rs as $r) {
                // Only AFF- cohorts count; match repository.php semantics exactly.
                if (stripos((string)$r->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) !== 0) {
                    continue;
                }
                $userid = (int)$r->userid;
                // Fold each row into the running value, keeping the highest rank
                // and dropping invalid/rank-0 stored values.
                $merged = self::merge_source($result[$userid] ?? null, $r->source);
                if ($merged !== null) {
                    $result[$userid] = $merged;
                }
            }
            $rs->close();
        }

        return $result;
    }
}
