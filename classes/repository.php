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
 * Data access for the Partner API. All reads are cohort/user scoped by the caller.
 *
 * @package    local_partnerapi
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_partnerapi;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only queries against Moodle core tables, shaped to the dashboard contract.
 */
class repository {

    /** @var int Maximum ids per IN() chunk to stay within DB bind limits. */
    const CHUNK = 1000;

    /**
     * Site course id (course 1) is excluded from learner-facing results.
     */
    const SITE_COURSE = 1;

    /**
     * Learner profiles for the given (already scope-checked) cohort ids.
     *
     * @param int[] $cohortids
     * @return array[] list of {id, firstname, lastname, email, lastaccess, cohort_ids}
     */
    public static function get_learners(array $cohortids): array {
        global $DB;

        $cohortids = self::clean_ids($cohortids);
        if (empty($cohortids)) {
            return [];
        }

        // Map userid -> [cohortid, ...] limited to the requested cohorts.
        list($insql, $params) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'co');
        $members = $DB->get_recordset_sql(
            "SELECT cm.userid, cm.cohortid
               FROM {cohort_members} cm
              WHERE cm.cohortid $insql",
            $params
        );

        $cohortsbyuser = [];
        foreach ($members as $m) {
            $cohortsbyuser[(int)$m->userid][] = (int)$m->cohortid;
        }
        $members->close();

        if (empty($cohortsbyuser)) {
            return [];
        }

        $result = [];
        foreach (array_chunk(array_keys($cohortsbyuser), self::CHUNK) as $chunk) {
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $users = $DB->get_records_sql(
                "SELECT u.id, u.firstname, u.lastname, u.email, u.lastaccess
                   FROM {user} u
                  WHERE u.id $uin AND u.deleted = 0",
                $uparams
            );
            foreach ($users as $u) {
                $result[] = [
                    'id'         => (int)$u->id,
                    'firstname'  => $u->firstname,
                    'lastname'   => $u->lastname,
                    'email'      => $u->email,
                    'lastaccess' => $u->lastaccess ? (int)$u->lastaccess : null,
                    'cohort_ids' => array_values(array_unique($cohortsbyuser[(int)$u->id] ?? [])),
                ];
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

        list($cin, $cparams) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'c');
        $allowed = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
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
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');

            // Distinct (user, course) enrolments.
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT ue.userid, c.id AS courseid, c.shortname, c.fullname
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   JOIN {course} c ON c.id = e.courseid
                  WHERE ue.userid $uin AND c.id <> :site",
                array_merge($uparams, ['site' => self::SITE_COURSE])
            );
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
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_recordset_sql(
                "SELECT gg.id, gg.userid, gi.courseid, gi.itemname, gi.itemtype, gi.itemmodule,
                        gg.finalgrade, gi.grademax, gg.timemodified
                   FROM {grade_grades} gg
                   JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE gg.userid $uin AND gi.courseid <> :site",
                array_merge($uparams, ['site' => self::SITE_COURSE])
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
     * @param int $since unix timestamp; only entries at/after this are counted (0 = all)
     * @return array[] list of {user_id, access_date (YYYY-MM-DD), access_count}
     */
    public static function get_accesslogs(array $userids, int $since = 0): array {
        global $DB;

        $userids = self::clean_ids($userids);
        if (empty($userids)) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            list($uin, $uparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
            $params = array_merge($uparams, ['since' => max(0, $since)]);
            // Group by integer day number (UTC) so it is portable across MySQL/Postgres.
            $rows = $DB->get_recordset_sql(
                "SELECT l.userid, FLOOR(l.timecreated / 86400) AS dayno, COUNT(*) AS cnt
                   FROM {logstore_standard_log} l
                  WHERE l.userid $uin AND l.timecreated >= :since
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

    // ----- Private helpers -------------------------------------------------

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
        list($cin, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
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
        list($uin, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        list($cin, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
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
        list($uin, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        list($cin, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
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
