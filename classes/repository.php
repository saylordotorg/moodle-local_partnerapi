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

// phpcs:disable moodle.Commenting.ValidTags.Invalid -- PHPMD suppression tag.
/**
 * Data access for the Partner API. All reads are cohort/user scoped by the caller.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

/**
 * Read-only queries against Moodle core tables, shaped to the dashboard contract.
 *
 * The class intentionally keeps the endpoint query contract in one stateless
 * boundary. Each complex query path is decomposed into focused helper methods,
 * so the aggregate class metric is not representative of a single code path.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class repository {
    // phpcs:enable moodle.Commenting.ValidTags.Invalid
    /** @var int Maximum ids per IN() chunk to stay within DB bind limits. */
    const CHUNK = 1000;

    /**
     * Site course id (course 1) is excluded from learner-facing results.
     */
    const SITE_COURSE = 1;

    /** @var int Quiz review bit for an in-progress attempt. */
    private const REVIEW_DURING = 0x10000;

    /** @var int Quiz review bit for the first two minutes after submission. */
    private const REVIEW_IMMEDIATELY_AFTER = 0x01000;

    /** @var int Quiz review bit while the quiz remains open. */
    private const REVIEW_WHILE_OPEN = 0x00100;

    /** @var int Quiz review bit after the quiz closes. */
    private const REVIEW_AFTER_CLOSE = 0x00010;

    /**
     * Learner profiles for the given (already scope-checked) cohort ids.
     *
     * @param int[] $cohortids
     * @return array[] list of {id, firstname, lastname, email, lastaccess,
     *                 cohort_ids, affiliation_join_at, affiliation_source}
     */
    public static function get_learners(array $cohortids): array {
        global $DB, $CFG;

        // The affiliation cohort idnumber prefix lives in the plugin lib; the
        // v1 bootstrap loads config.php but not lib.php, so require it here.
        require_once($CFG->dirroot . '/local/partnerapi/lib.php');

        $cohortids = self::clean_ids($cohortids);
        if (empty($cohortids)) {
            return [];
        }

        // Map userid -> [cohortid, ...] limited to the requested cohorts, and
        // capture cohort_members.timeadded plus whether the cohort is a partner
        // affiliation (idnumber starts with the AFF- prefix). The affiliation
        // join timestamp is the earliest timeadded across the user's affiliation
        // cohort memberships, falling back to the earliest of any requested
        // cohort when none of them are affiliations.
        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'co');
        $members = $DB->get_recordset_sql(
            "SELECT cm.id, cm.userid, cm.cohortid, cm.timeadded, c.idnumber
               FROM {cohort_members} cm
               JOIN {cohort} c ON c.id = cm.cohortid
              WHERE cm.cohortid $insql",
            $params
        );

        [$cohortsbyuser, $affjoinbyuser, $anyjoinbyuser] = self::index_memberships($members);
        $members->close();

        if (empty($cohortsbyuser)) {
            return [];
        }

        // Resolve authoritative affiliation source per user in one batched pass
        // (restricted to AFF- cohorts, highest-precedence value wins).
        $sourcesbyuser = provenance::sources_for_users(array_keys($cohortsbyuser));

        $result = [];
        foreach (array_chunk(array_keys($cohortsbyuser), self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $users = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess
                   FROM {user} u
                  WHERE u.id $uin AND u.deleted = 0",
                $uparams
            );
            foreach ($users as $u) {
                $result[] = self::format_learner(
                    $u,
                    $cohortsbyuser,
                    $affjoinbyuser,
                    $anyjoinbyuser,
                    $sourcesbyuser
                );
            }
        }

        return $result;
    }

    /**
     * Filter a list of user ids down to those who are members of the given cohorts.
     * This enforces that a partner token cannot read arbitrary user ids.
     *
     * @param int[] $userids
     * @param int[] $cohortids the client's allowed cohorts
     * @return int[] the subset of $userids that are in-scope
     */
    public static function scope_userids(array $userids, array $cohortids): array {
        global $DB;

        $userids = self::clean_ids($userids);
        $cohortids = self::clean_ids($cohortids);
        if (empty($userids) || empty($cohortids)) {
            return [];
        }

        [$cin, $cparams] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'c');
        $allowed = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_fieldset_sql(
                "SELECT DISTINCT cm.userid
                   FROM {cohort_members} cm
                  WHERE cm.userid $uin AND cm.cohortid $cin",
                array_merge($uparams, $cparams)
            );
            foreach ($rows as $uid) {
                $allowed[] = (int)$uid;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Per-user, per-course enrollments with progress and completion.
     *
     * @param int[] $userids already scope-checked
     * @return array[] list matching the MoodleEnrollment contract
     */
    public static function get_enrollments(array $userids): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');

            // Distinct (user, course) enrolments. Use a recordset and build a
            // plain list: get_records_sql keys rows by the first column (userid)
            // and would silently drop a learner's additional courses.
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT ue.userid, c.id AS courseid, c.shortname, c.fullname
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   JOIN {course} c ON c.id = e.courseid
                  WHERE ue.userid $uin AND c.id <> :site",
                array_merge($uparams, ['site' => self::SITE_COURSE])
            );
            $rows = [];
            foreach ($rs as $r) {
                $rows[] = $r;
            }
            $rs->close();
            if (empty($rows)) {
                continue;
            }

            $courseids = array_values(array_unique(array_map(fn($r) => (int)$r->courseid, $rows)));
            $totals = self::total_activities_by_course($courseids);
            $completed = self::completed_activities_by_user_course($chunk, $courseids);
            $completion = self::course_completion_times($chunk, $courseids);

            foreach ($rows as $r) {
                $courseid = (int)$r->courseid;
                $userid = (int)$r->userid;
                $total = $totals[$courseid] ?? 0;
                $done = $completed[$userid][$courseid] ?? 0;
                $progress = $total > 0 ? (int)round(min($done, $total) / $total * 100) : 0;
                $progress = max(0, min(100, $progress));

                $result[] = [
                    'user_id' => $userid,
                    'course'  => [
                        'id'               => $courseid,
                        'shortname'        => $r->shortname,
                        'fullname'         => $r->fullname,
                        'total_activities' => $total,
                    ],
                    'completed_activities' => $done,
                    'progress_percent'     => $progress,
                    'timecompleted'        => $completion[$userid][$courseid] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Flat completion records (optional endpoint; not used by the current sync).
     *
     * @param int[] $userids already scope-checked
     * @return array[] list of {user_id, course_id, completed_activities, progress_percent, timecompleted}
     */
    public static function get_completions(array $userids): array {
        $enrollments = self::get_enrollments($userids);
        $out = [];
        foreach ($enrollments as $e) {
            $out[] = [
                'user_id'              => $e['user_id'],
                'course_id'            => $e['course']['id'],
                'completed_activities' => $e['completed_activities'],
                'progress_percent'     => $e['progress_percent'],
                'timecompleted'        => $e['timecompleted'],
            ];
        }
        return $out;
    }

    /**
     * Grade items per user per course.
     *
     * @param int[] $userids already scope-checked
     * @return array[] list matching the MoodleGrade contract
     */
    public static function get_grades(array $userids): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_recordset_sql(
                "SELECT gg.id, gg.userid, gi.courseid, gi.itemname, gi.itemtype, gi.itemmodule,
                        gg.finalgrade, gi.grademax, gg.timemodified
                   FROM {grade_grades} gg
                   JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE gg.userid $uin AND gi.courseid <> :site
                    AND (gi.hidden = 0 OR (gi.hidden > 1 AND gi.hidden <= :itemnow))
                    AND (gg.hidden = 0 OR (gg.hidden > 1 AND gg.hidden <= :gradenow))",
                array_merge($uparams, [
                    'site' => self::SITE_COURSE,
                    'itemnow' => time(),
                    'gradenow' => time(),
                ])
            );
            foreach ($rows as $g) {
                $result[] = [
                    'user_id'       => (int)$g->userid,
                    'course_id'     => (int)$g->courseid,
                    'item_name'     => self::grade_item_name($g),
                    'grade'         => isset($g->finalgrade) && $g->finalgrade !== null ? (float)$g->finalgrade : null,
                    'grade_max'     => isset($g->grademax) && $g->grademax !== null ? (float)$g->grademax : null,
                    'timesubmitted' => $g->timemodified ? (int)$g->timemodified : null,
                ];
            }
            $rows->close();
        }

        return $result;
    }

    /**
     * Daily access counts per user from the standard log store.
     *
     * @param int[] $userids already scope-checked
     * @param int $since Inclusive lower log timestamp bound.
     * @param int $until Inclusive upper log timestamp bound.
     * @return array[] list of {user_id, access_date (YYYY-MM-DD), access_count}
     */
    public static function get_accesslogs(array $userids, int $since, int $until): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params = array_merge($uparams, [
                'since' => max(0, $since),
                'until' => $until,
            ]);
            // Group by integer day number (UTC) so it is portable across MySQL/Postgres.
            $rows = $DB->get_recordset_sql(
                "SELECT l.userid, FLOOR(l.timecreated / 86400) AS dayno, COUNT(*) AS cnt
                   FROM {logstore_standard_log} l
                  WHERE l.userid $uin AND l.timecreated >= :since AND l.timecreated <= :until
                  GROUP BY l.userid, FLOOR(l.timecreated / 86400)",
                $params
            );
            foreach ($rows as $row) {
                $result[] = [
                    'user_id'      => (int)$row->userid,
                    'access_date'  => gmdate('Y-m-d', ((int)$row->dayno) * 86400),
                    'access_count' => (int)$row->cnt,
                ];
            }
            $rows->close();
        }

        return $result;
    }

    /**
     * Cohort id → name/idnumber for the given (already scope-checked) cohort ids.
     *
     * @param int[] $cohortids
     * @return array[] list of {id, name, idnumber}
     */
    public static function get_cohorts(array $cohortids): array {
        global $DB;

        $cohortids = self::clean_ids($cohortids);
        if (empty($cohortids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT id, name, idnumber
               FROM {cohort}
              WHERE id $insql",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int)$r->id,
                'name'     => $r->name,
                'idnumber' => $r->idnumber ?? '',
            ];
        }
        return $out;
    }

    /**
     * Issued certificates (tool_certificate) for the given (already
     * scope-checked) user ids.
     *
     * Returns an empty array when the certificate plugin is not installed,
     * so the contract degrades gracefully on sites without it.
     *
     * @param int[] $userids already scope-checked
     * @return array[] list of {id, user_id, code, template_name, course_id,
     *                 timecreated, expires, archived, verify_url, view_url}
     */
    public static function get_certificates(array $userids): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        // Degrade gracefully if tool_certificate isn't present on this site.
        if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_recordset_sql(
                "SELECT ci.id, ci.userid, ci.code, ci.timecreated, ci.expires,
                        ci.courseid, ci.archived, t.name AS templatename
                   FROM {tool_certificate_issues} ci
                   JOIN {tool_certificate_templates} t ON t.id = ci.templateid
                  WHERE ci.userid $uin",
                $uparams
            );
            foreach ($rows as $r) {
                $code = (string)$r->code;
                $verifyurl = (new \moodle_url('/admin/tool/certificate/index.php', ['code' => $code]))->out(false);
                $viewurl = (new \moodle_url('/admin/tool/certificate/view.php', ['code' => $code]))->out(false);
                $result[] = [
                    'id'            => (int)$r->id,
                    'user_id'       => (int)$r->userid,
                    'code'          => $code,
                    'template_name' => $r->templatename,
                    'course_id'     => $r->courseid ? (int)$r->courseid : null,
                    'timecreated'   => $r->timecreated ? (int)$r->timecreated : null,
                    'expires'       => $r->expires ? (int)$r->expires : null,
                    'archived'      => (int)$r->archived === 1,
                    'verify_url'    => $verifyurl,
                    'view_url'      => $viewurl,
                ];
            }
            $rows->close();
        }

        return $result;
    }

    /**
     * Quiz attempts (mod_quiz) for the given (already scope-checked) user ids.
     *
     * Returns one row per non-preview attempt with the scaled score, the quiz
     * max grade, and the grade-to-pass (from the quiz's grade item) so the
     * dashboard can compute pass/fail, attempt counts, and timing. Moodle's
     * grade visibility and quiz review timing are enforced before release.
     *
     * @param int[] $userids already scope-checked
     * @return array[] list of {user_id, course_id, quiz_id, quiz_name, attempt,
     *                 state, timestart, timefinish, score, max_score, grade_to_pass}
     */
    public static function get_quiz_attempts(array $userids): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }
        if (!$DB->get_manager()->table_exists('quiz_attempts')) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rs = $DB->get_recordset_sql(
                "SELECT qa.id, qa.userid, qa.quiz AS quizid, q.course AS courseid,
                        q.name AS quizname, qa.attempt, qa.state, qa.timestart,
                        qa.timefinish, qa.sumgrades, q.sumgrades AS quizsumgrades,
                        q.grade AS quizgrade, q.timeclose, q.reviewattempt,
                        q.reviewmarks, q.reviewmaxmarks, gi.gradepass,
                        gi.hidden AS itemhidden, gg.hidden AS gradehidden
                   FROM {quiz_attempts} qa
                   JOIN {quiz} q ON q.id = qa.quiz
              LEFT JOIN {grade_items} gi
                        ON gi.itemmodule = 'quiz' AND gi.iteminstance = q.id
                        AND gi.courseid = q.course AND gi.itemtype = 'mod'
              LEFT JOIN {grade_grades} gg
                        ON gg.itemid = gi.id AND gg.userid = qa.userid
                  WHERE qa.userid $uin AND qa.preview = 0 AND q.course <> :site",
                array_merge($uparams, ['site' => self::SITE_COURSE])
            );
            foreach ($rs as $r) {
                $attempt = self::format_quiz_attempt($r, time());
                if ($attempt !== null) {
                    $result[] = $attempt;
                }
            }
            $rs->close();
        }

        return $result;
    }

    /**
     * Estimated time-on-task per (user, course), in seconds.
     *
     * Uses the standard "dedication" heuristic over the standard log store:
     * consecutive events within the same course count toward time when the gap
     * is positive and within the session window; larger gaps start a new
     * session and are not counted.
     *
     * @param int[] $userids already scope-checked
     * @param int $since Inclusive lower log timestamp bound.
     * @param int $until Inclusive upper log timestamp bound.
     * @return array[] list of {user_id, course_id, seconds}
     */
    public static function get_time_in_course(array $userids, int $since, int $until): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rs = $DB->get_recordset_sql(
                "SELECT l.userid, l.courseid, l.timecreated
                   FROM {logstore_standard_log} l
                  WHERE l.userid $uin AND l.courseid IS NOT NULL AND l.courseid <> :site
                    AND l.timecreated >= :since AND l.timecreated <= :until
                   ORDER BY l.userid, l.courseid, l.timecreated",
                array_merge($uparams, [
                    'site' => self::SITE_COURSE,
                    'since' => $since,
                    'until' => $until,
                ])
            );

            $acc = self::accumulate_session_time($rs);
            $rs->close();
            array_push($result, ...self::format_session_time($acc));
        }

        return $result;
    }

    // Private helpers.

    /**
     * Index cohort membership rows by user and earliest membership time.
     *
     * @param \moodle_recordset $members Membership rows.
     * @return array{0: array, 1: array, 2: array} Cohorts, AFF times, and fallback times.
     */
    private static function index_memberships(\moodle_recordset $members): array {
        $cohortsbyuser = [];
        $affjoinbyuser = [];
        $anyjoinbyuser = [];
        foreach ($members as $member) {
            $userid = (int) $member->userid;
            $cohortsbyuser[$userid][] = (int) $member->cohortid;
            $timeadded = (int) $member->timeadded;
            if ($timeadded <= 0) {
                continue;
            }
            self::keep_earliest($anyjoinbyuser, $userid, $timeadded);
            if (stripos((string) $member->idnumber, LOCAL_PARTNERAPI_AFFILIATION_PREFIX) === 0) {
                self::keep_earliest($affjoinbyuser, $userid, $timeadded);
            }
        }
        return [$cohortsbyuser, $affjoinbyuser, $anyjoinbyuser];
    }

    /**
     * Keep the earliest timestamp for a user.
     *
     * @param array $timestamps Timestamp map, modified in place.
     * @param int $userid User id.
     * @param int $timestamp Candidate timestamp.
     * @return void
     */
    private static function keep_earliest(array &$timestamps, int $userid, int $timestamp): void {
        if (!isset($timestamps[$userid]) || $timestamp < $timestamps[$userid]) {
            $timestamps[$userid] = $timestamp;
        }
    }

    /**
     * Shape one learner record for the API.
     *
     * @param stdClass $user Moodle user record.
     * @param array $cohortsbyuser Membership map.
     * @param array $affjoinbyuser AFF membership timestamps.
     * @param array $anyjoinbyuser Fallback membership timestamps.
     * @param array $sourcesbyuser Provenance map.
     * @return array<string, mixed> API learner record.
     */
    private static function format_learner(
        \stdClass $user,
        array $cohortsbyuser,
        array $affjoinbyuser,
        array $anyjoinbyuser,
        array $sourcesbyuser
    ): array {
        $userid = (int) $user->id;
        return [
            'id' => $userid,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'lastaccess' => $user->lastaccess ? (int) $user->lastaccess : null,
            'cohort_ids' => array_values(array_unique($cohortsbyuser[$userid] ?? [])),
            'affiliation_join_at' => $affjoinbyuser[$userid] ?? $anyjoinbyuser[$userid] ?? null,
            'affiliation_source' => $sourcesbyuser[$userid] ?? null,
        ];
    }

    /**
     * Release and shape one quiz attempt according to Moodle's review policy.
     *
     * @param stdClass $attempt Joined quiz-attempt record.
     * @param int $now Current timestamp.
     * @return array<string, mixed>|null Released record, or null when withheld.
     */
    private static function format_quiz_attempt(\stdClass $attempt, int $now): ?array {
        if (!self::visibility_is_released($attempt->itemhidden, $now)) {
            return null;
        }
        if (!self::visibility_is_released($attempt->gradehidden, $now)) {
            return null;
        }

        $phase = self::quiz_review_phase($attempt, $now);
        if (((int) $attempt->reviewattempt & $phase) === 0) {
            return null;
        }
        $marksreleased = ((int) $attempt->reviewmarks & $phase) !== 0;
        $maxreleased = ((int) $attempt->reviewmaxmarks & $phase) !== 0;

        return [
            'user_id' => (int) $attempt->userid,
            'course_id' => (int) $attempt->courseid,
            'quiz_id' => (int) $attempt->quizid,
            'quiz_name' => $attempt->quizname,
            'attempt' => (int) $attempt->attempt,
            'state' => (string) $attempt->state,
            'timestart' => $attempt->timestart ? (int) $attempt->timestart : null,
            'timefinish' => $attempt->timefinish ? (int) $attempt->timefinish : null,
            'score' => self::released_quiz_score($attempt, $marksreleased),
            'max_score' => $maxreleased && $attempt->quizgrade !== null ? (float) $attempt->quizgrade : null,
            'grade_to_pass' => self::released_grade_to_pass($attempt, $marksreleased),
        ];
    }

    /**
     * Scale a released raw quiz mark.
     *
     * @param stdClass $attempt Joined attempt record.
     * @param bool $released Whether marks may be reviewed.
     * @return float|null Released scaled score.
     */
    private static function released_quiz_score(\stdClass $attempt, bool $released): ?float {
        if (!$released || $attempt->sumgrades === null || $attempt->quizsumgrades === null) {
            return null;
        }
        if ((float) $attempt->quizsumgrades <= 0) {
            return null;
        }
        return round(
            (float) $attempt->sumgrades / (float) $attempt->quizsumgrades * (float) $attempt->quizgrade,
            2
        );
    }

    /**
     * Return a released grade-to-pass value.
     *
     * @param stdClass $attempt Joined attempt record.
     * @param bool $released Whether marks may be reviewed.
     * @return float|null Released threshold.
     */
    private static function released_grade_to_pass(\stdClass $attempt, bool $released): ?float {
        if (!$released || $attempt->gradepass === null || (float) $attempt->gradepass <= 0) {
            return null;
        }
        return (float) $attempt->gradepass;
    }

    /**
     * Accumulate session gaps from an ordered log recordset.
     *
     * @param \moodle_recordset $records Ordered standard-log rows.
     * @return array<int, array<int, int>> Seconds by user and course.
     */
    private static function accumulate_session_time(\moodle_recordset $records): array {
        $accumulated = [];
        $previous = null;
        foreach ($records as $record) {
            $current = [(int) $record->userid, (int) $record->courseid, (int) $record->timecreated];
            if ($previous !== null && $previous[0] === $current[0] && $previous[1] === $current[1]) {
                $gap = $current[2] - $previous[2];
                if ($gap > 0 && $gap <= (30 * MINSECS)) {
                    $accumulated[$current[0]][$current[1]] =
                        ($accumulated[$current[0]][$current[1]] ?? 0) + $gap;
                }
            }
            $previous = $current;
        }
        return $accumulated;
    }

    /**
     * Shape accumulated session time for the API.
     *
     * @param array $accumulated Seconds by user and course.
     * @return array<int, array<string, int>> API records.
     */
    private static function format_session_time(array $accumulated): array {
        $result = [];
        foreach ($accumulated as $userid => $courses) {
            foreach ($courses as $courseid => $seconds) {
                $result[] = [
                    'user_id' => $userid,
                    'course_id' => $courseid,
                    'seconds' => (int) $seconds,
                ];
            }
        }
        return $result;
    }

    /**
     * Check Moodle's hidden-value semantics.
     *
     * A value of 1 is hidden indefinitely. Values greater than 1 are release
     * timestamps and become visible once that time has passed.
     *
     * @param int|string|null $hidden Moodle hidden flag or timestamp.
     * @param int $now Current timestamp.
     * @return bool Whether the value is released.
     */
    private static function visibility_is_released($hidden, int $now): bool {
        $hidden = (int) $hidden;
        return $hidden === 0 || ($hidden > 1 && $hidden <= $now);
    }

    /**
     * Resolve the active Moodle quiz review period for an attempt.
     *
     * @param stdClass $attempt Joined quiz-attempt record.
     * @param int $now Current timestamp.
     * @return int One of the REVIEW_* bit constants.
     */
    private static function quiz_review_phase(\stdClass $attempt, int $now): int {
        if ($attempt->state === 'inprogress') {
            return self::REVIEW_DURING;
        }
        if ((int) $attempt->timeclose > 0 && $now >= (int) $attempt->timeclose) {
            return self::REVIEW_AFTER_CLOSE;
        }
        if ($now < (int) $attempt->timefinish + 120) {
            return self::REVIEW_IMMEDIATELY_AFTER;
        }
        return self::REVIEW_WHILE_OPEN;
    }

    /**
     * Count of completion-tracked activities per course.
     *
     * @param int[] $courseids
     * @return array map courseid => count
     */
    private static function total_activities_by_course(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$cin, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_records_sql(
            "SELECT cm.course AS courseid, COUNT(*) AS cnt
               FROM {course_modules} cm
              WHERE cm.course $cin AND cm.completion > 0 AND cm.deletioninprogress = 0
              GROUP BY cm.course",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->courseid] = (int)$r->cnt;
        }
        return $out;
    }

    /**
     * Count of completed activities per user per course.
     *
     * @param int[] $userids
     * @param int[] $courseids
     * @return array nested map [userid][courseid] => count
     */
    private static function completed_activities_by_user_course(array $userids, array $courseids): array {
        global $DB;
        if (empty($userids) || empty($courseids)) {
            return [];
        }
        [$uin, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_recordset_sql(
            "SELECT cm.course AS courseid, cmc.userid, COUNT(*) AS cnt
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cmc.userid $uin AND cm.course $cin
                    AND cmc.completionstate > 0 AND cm.completion > 0
              GROUP BY cm.course, cmc.userid",
            array_merge($uparams, $cparams)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->userid][(int)$r->courseid] = (int)$r->cnt;
        }
        $rows->close();
        return $out;
    }

    /**
     * Course completion timestamps per user per course.
     *
     * @param int[] $userids
     * @param int[] $courseids
     * @return array nested map [userid][courseid] => timecompleted|null
     */
    private static function course_completion_times(array $userids, array $courseids): array {
        global $DB;
        if (empty($userids) || empty($courseids)) {
            return [];
        }
        [$uin, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        [$cin, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $rows = $DB->get_recordset_sql(
            "SELECT cc.userid, cc.course AS courseid, cc.timecompleted
               FROM {course_completions} cc
              WHERE cc.userid $uin AND cc.course $cin",
            array_merge($uparams, $cparams)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->userid][(int)$r->courseid] = $r->timecompleted ? (int)$r->timecompleted : null;
        }
        $rows->close();
        return $out;
    }

    /**
     * Derive a human-readable grade item name.
     *
     * @param \stdClass $g a row joining grade_grades + grade_items
     * @return string
     */
    private static function grade_item_name(\stdClass $g): string {
        if (!empty($g->itemname)) {
            return $g->itemname;
        }
        if ($g->itemtype === 'course') {
            return 'Course total';
        }
        if (!empty($g->itemmodule)) {
            return ucfirst($g->itemmodule);
        }
        return 'Grade item';
    }

    /**
     * Normalize a list of ids to unique positive integers.
     *
     * @param array $ids
     * @return int[]
     */
    private static function clean_ids(array $ids): array {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }
}
