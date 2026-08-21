<?php
// This file is part of Moodle - http://moodle.org/
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
 * Attempt recording and aggregation for mod_exelearning (DEC-0-07).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

/**
 * Stores each user submission as an attempt and aggregates attempts into the
 * gradebook according to the per-instance grademethod, mirroring
 * mod_h5pactivity / mod_scorm.
 */
class attempts {
    /** @var int Aggregation: keep the highest scaled score across attempts. */
    public const GRADE_HIGHEST = 0;
    /** @var int Aggregation: average of all attempts. */
    public const GRADE_AVERAGE = 1;
    /** @var int Aggregation: first attempt. */
    public const GRADE_FIRST = 2;
    /** @var int Aggregation: most recent attempt. */
    public const GRADE_LAST = 3;
    /** @var int Aggregation: lowest scaled score across attempts. */
    public const GRADE_LOWEST = 4;

    /** @var int Review: students never see their past attempts. */
    public const REVIEW_NONE = 0;
    /** @var int Review: students can always review their past attempts. */
    public const REVIEW_ALWAYS = 1;
    /** @var int Review: students review only once the activity is complete. */
    public const REVIEW_AFTERCOMPLETION = 2;

    /**
     * Selectable review modes, for the settings form.
     *
     * @return array<int,string> mode constant => lang string key
     */
    public static function reviewmode_options(): array {
        return [
            self::REVIEW_ALWAYS          => 'reviewmode_always',
            self::REVIEW_AFTERCOMPLETION => 'reviewmode_aftercompletion',
            self::REVIEW_NONE            => 'reviewmode_none',
        ];
    }

    /**
     * Count distinct GRADABLE attempts a user has on an activity (for maxattempt).
     *
     * Ungraded-period attempts (gradable = 0) are excluded on purpose. maxattempt is a
     * grading control — mod_form.php disables it along with the rest of the grade
     * settings when the activity is not graded — so charging it for work the activity
     * itself declared to be outside assessment produces a state with no way out: with
     * maxattempt = 1, a learner who used the activity while it was ungraded arrives at
     * the limit having never had a gradable attempt, and every further submission is
     * refused with 'maxattemptsreached'. They could never be graded at all (DEC-124-03).
     *
     * This is the ONE counting query that filters. The allocation query in
     * resolve_attempt_number() must not: it takes MAX(attempt) to pick the next number,
     * and skipping rows there would reissue an attempt number that already exists,
     * colliding with the upsert key of record_item() and merging two attempts into one.
     *
     * @param int $exelearningid
     * @param int $userid
     * @return int
     */
    public static function count_user_attempts(int $exelearningid, int $userid): int {
        global $DB;
        return (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT attempt) FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ? AND gradable = 1",
            [$exelearningid, $userid]
        );
    }

    /**
     * Whether any student has at least one attempt on this activity.
     *
     * Used to decide whether editing the package should warn the teacher that
     * existing grades are now stale (DEC-12-01).
     *
     * @param int $exelearningid
     * @return bool
     */
    public static function activity_has_attempts(int $exelearningid): bool {
        global $DB;
        return $DB->record_exists('exelearning_attempt', ['exelearningid' => $exelearningid]);
    }

    /**
     * Lang string key for a grademethod value (for display, not just the form).
     *
     * @param int $grademethod
     * @return string
     */
    public static function grademethod_stringkey(int $grademethod): string {
        $options = self::grademethod_options();
        return $options[$grademethod] ?? $options[self::GRADE_HIGHEST];
    }

    /**
     * Teacher-facing participation summary for the activity front page
     * (DEC-0-11 option B, "Assignment-style" summary). Counts how many of the
     * given users have at least one attempt and the mean overall scaled score.
     *
     * Group filtering is the caller's responsibility: pass the userids that the
     * teacher is allowed to see (respecting separate groups).
     *
     * @param int $exelearningid
     * @param int[] $userids Candidate users (enrolled students visible to the teacher).
     * @param int $grademethod One of the GRADE_* constants; defaults to highest
     *     so a caller that has not migrated keeps the previous behaviour.
     * @return array{total:int, attempted:int, meanpercent:float|null}
     */
    public static function participation_summary(
        int $exelearningid,
        array $userids,
        int $grademethod = self::GRADE_HIGHEST
    ): array {
        global $DB;

        $total = count($userids);
        if ($total === 0) {
            return ['total' => 0, 'attempted' => 0, 'meanpercent' => null];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['exeid'] = $exelearningid;

        // Distinct users with at least one overall (itemnumber=0) attempt.
        $attempted = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {exelearning_attempt}
                  WHERE exelearningid = :exeid AND itemnumber = 0 AND userid $insql",
            $params
        );

        // Mean of each user's AGGREGATED overall score (0..1) → percent. The
        // aggregation follows the activity's grademethod (DEC-0-07) so this
        // summary matches what the gradebook publishes — it previously
        // hardcoded the best attempt regardless of method. A second named
        // parameter set (param2_) avoids collisions with the count query above.
        [$insql2, $params2] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'param2_');
        $params2['exeid2'] = $exelearningid;
        $rows = $DB->get_records_sql(
            "SELECT id, userid, scaledscore
                   FROM {exelearning_attempt}
                  WHERE exelearningid = :exeid2 AND itemnumber = 0 AND userid $insql2
                        AND gradable = 1
               ORDER BY userid ASC, attempt ASC",
            $params2
        );
        $byuser = [];
        foreach ($rows as $row) {
            $byuser[(int) $row->userid][] = (float) $row->scaledscore;
        }
        $meanpercent = null;
        if ($byuser !== []) {
            $sum = 0.0;
            foreach ($byuser as $scores) {
                $sum += (float) self::aggregate_values($scores, $grademethod);
            }
            $meanpercent = ($sum / count($byuser)) * 100.0;
        }

        return ['total' => $total, 'attempted' => $attempted, 'meanpercent' => $meanpercent];
    }

    /**
     * All selectable aggregation methods, for the settings form.
     *
     * @return array<int,string> method constant => lang string key
     */
    public static function grademethod_options(): array {
        return [
            self::GRADE_HIGHEST => 'grademethod_highest',
            self::GRADE_AVERAGE => 'grademethod_average',
            self::GRADE_FIRST   => 'grademethod_first',
            self::GRADE_LAST    => 'grademethod_last',
            self::GRADE_LOWEST  => 'grademethod_lowest',
        ];
    }

    /**
     * Resolve the attempt number for a page-load session.
     *
     * All auto-commits of the same page view share one $sessiontoken, so they
     * update the same attempt. The first commit of a fresh session allocates a
     * new attempt number (max for this user/activity + 1).
     *
     * @param int $exelearningid
     * @param int $userid
     * @param string $sessiontoken
     * @return int Attempt number to write to.
     */
    public static function resolve_attempt_number(
        int $exelearningid,
        int $userid,
        string $sessiontoken
    ): int {
        global $DB;

        if ($sessiontoken !== '') {
            $existing = $DB->get_field('exelearning_attempt', 'attempt', [
                'exelearningid' => $exelearningid,
                'userid'        => $userid,
                'sessiontoken'  => $sessiontoken,
            ], IGNORE_MULTIPLE);
            if ($existing !== false) {
                return (int) $existing;
            }
        }

        $max = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(attempt), 0) FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ?",
            [$exelearningid, $userid]
        );
        return $max + 1;
    }

    /**
     * Record (insert or update) one item result inside an attempt.
     *
     * Upsert keyed by (exelearningid, userid, attempt, itemnumber) so repeated
     * auto-commits in the same session refine the same row instead of piling up.
     *
     * @param int $exelearningid
     * @param int $userid
     * @param int $attempt Attempt number from resolve_attempt_number().
     * @param int $itemnumber 0=overall, >0=iDevice.
     * @param float $rawscore
     * @param float $maxscore
     * @param string $status completed|passed|failed|incomplete
     * @param string $sessiontoken
     * @param bool $gradable False when the activity was not graded at the time (DEC-124-03):
     *        the row still feeds completion and the report, but the aggregation ignores it.
     */
    public static function record_item(
        int $exelearningid,
        int $userid,
        int $attempt,
        int $itemnumber,
        float $rawscore,
        float $maxscore,
        string $status,
        string $sessiontoken,
        bool $gradable = true
    ): void {
        global $DB;

        $now = time();
        $scaled = ($maxscore > 0) ? max(0.0, min(1.0, $rawscore / $maxscore)) : 0.0;
        // A row written while the activity was not graded is completion-only
        // (DEC-124-03): it stays in the report and feeds completion by status, but the
        // aggregation queries below exclude it, so re-enabling grading never turns work
        // done during the ungraded period into a mark.
        $gradableflag = $gradable ? 1 : 0;

        $existing = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $exelearningid,
            'userid'        => $userid,
            'attempt'       => $attempt,
            'itemnumber'    => $itemnumber,
        ]);

        if ($existing) {
            // NOTE: $gradable is deliberately NOT applied on update. A row's gradability
            // is decided when it is created and never changes afterwards (DEC-124-03).
            //
            // The upsert is keyed by the session's attempt number, so a learner holding a
            // page open while the teacher flips the master grading switch keeps posting
            // into this same row. Re-deciding the flag on every POST would let that flip
            // rewrite history in BOTH directions: off->on would promote work done outside
            // assessment into a grade, which is precisely the guarantee this flag exists
            // to make; and on->off would demote work that WAS done under assessment,
            // destroying the history DEC-124-01 promises can be recovered when grading
            // comes back on.
            //
            // Splitting the attempt on a state change was the other candidate. It was
            // rejected: resolve_attempt_number() keys on sessiontoken, so splitting means
            // one token mapping to two attempt numbers, and the learner gets a cleanly
            // gradable attempt anyway just by reloading — which now costs them nothing,
            // since count_user_attempts() no longer charges ungraded attempts against
            // maxattempt.
            $existing->rawscore     = $rawscore;
            $existing->maxscore     = $maxscore;
            $existing->scaledscore  = $scaled;
            $existing->status       = $status;
            $existing->sessiontoken = $sessiontoken;
            $existing->timemodified = $now;
            $DB->update_record('exelearning_attempt', $existing);
        } else {
            $DB->insert_record('exelearning_attempt', (object) [
                'exelearningid' => $exelearningid,
                'userid'        => $userid,
                'attempt'       => $attempt,
                'itemnumber'    => $itemnumber,
                'rawscore'      => $rawscore,
                'maxscore'      => $maxscore,
                'scaledscore'   => $scaled,
                'status'        => $status,
                'gradable'      => $gradableflag,
                'sessiontoken'  => $sessiontoken,
                'timecreated'   => $now,
                'timemodified'  => $now,
            ]);
        }
    }

    /**
     * Aggregate a user's attempts for one item into a single scaled score.
     *
     * @param int $exelearningid
     * @param int $userid
     * @param int $itemnumber
     * @param int $grademethod One of the GRADE_* constants.
     * @return float|null Scaled score (0..1) or null when there are no attempts.
     */
    public static function aggregate_scaled(
        int $exelearningid,
        int $userid,
        int $itemnumber,
        int $grademethod
    ): ?float {
        global $DB;

        $scaled = $DB->get_fieldset_sql(
            "SELECT scaledscore FROM {exelearning_attempt}
                  WHERE exelearningid = ? AND userid = ? AND itemnumber = ? AND gradable = 1
               ORDER BY attempt ASC",
            [$exelearningid, $userid, $itemnumber]
        );
        return self::aggregate_values(array_map('floatval', $scaled), $grademethod);
    }

    /**
     * Reduce an ordered list of scaled scores to a single grade (DEC-0-07).
     *
     * Pure aggregation shared by the per-user-per-item path (aggregate_scaled())
     * and the batch recalculation path (exelearning_recalculate_grades_for_users()
     * in lib.php). Scores must already be ordered by attempt ASC so FIRST/LAST
     * pick the correct attempt.
     *
     * @param float[] $scaled Scaled scores (0..1) ordered by attempt ASC.
     * @param int $grademethod One of the GRADE_* constants.
     * @return float|null Aggregated scaled score (0..1) or null for no attempts.
     */
    public static function aggregate_values(array $scaled, int $grademethod): ?float {
        if (empty($scaled)) {
            return null;
        }

        switch ($grademethod) {
            case self::GRADE_AVERAGE:
                return array_sum($scaled) / count($scaled);
            case self::GRADE_FIRST:
                return reset($scaled);
            case self::GRADE_LAST:
                return end($scaled);
            case self::GRADE_LOWEST:
                return min($scaled);
            case self::GRADE_HIGHEST:
            default:
                return max($scaled);
        }
    }

    /**
     * Fetch every scaled score for an activity grouped by user and item.
     *
     * One query replacing the per-user-per-item SELECTs of the bulk grade
     * recalculation. Scores are ordered by attempt ASC inside each group so
     * aggregate_values() (DEC-0-07) sees the same ordering aggregate_scaled()
     * uses.
     *
     * @param int $exelearningid
     * @param int[] $userids Users to fetch; empty array fetches nothing.
     * @return array<int,array<int,float[]>> userid => itemnumber => scaled scores.
     */
    public static function fetch_scaled_by_user_item(int $exelearningid, array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['exeid'] = $exelearningid;

        $rs = $DB->get_recordset_sql(
            "SELECT id, userid, itemnumber, scaledscore FROM {exelearning_attempt}
                  WHERE exelearningid = :exeid AND userid $insql AND gradable = 1
               ORDER BY userid, itemnumber, attempt ASC",
            $params
        );
        $byuser = [];
        foreach ($rs as $row) {
            $byuser[(int) $row->userid][(int) $row->itemnumber][] = (float) $row->scaledscore;
        }
        $rs->close();

        return $byuser;
    }
}
