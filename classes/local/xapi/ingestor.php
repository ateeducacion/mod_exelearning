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

namespace mod_exelearning\local\xapi;

use mod_exelearning\local\attempts;
use mod_exelearning\local\track;

/**
 * Ingests one xAPI statement into the existing grade pipeline (DEC-17-01/DEC-85-01).
 *
 * This is the xAPI counterpart of the SCORM `track::ingest()` orchestration. It does
 * NOT add a parallel model: it normalises the statement to the same `itemscores`
 * shape and reuses the very same building blocks — {@see track::apply_item_scores()},
 * {@see attempts::record_item()}, {@see attempts::aggregate_scaled()},
 * `grade_update()` and `completion_info` — so the per-iDevice grades, the attempt
 * axis and the gradebook writes are identical. `track::ingest()` itself is left
 * untouched (the SCORM productive path).
 *
 * The one deliberate difference is the OVERALL aggregation. SCORM receives the whole
 * `cmi.suspend_data` map on every commit — every registered iDevice, answered or not —
 * so {@see track::recompute_overall_pct()} can take a continuous weighted mean of it.
 * xAPI only ever sees the iDevices that emitted an `answered`, so the reconstruction
 * below waits until the package is fully reported and then replays eXeLearning's own
 * integer largest-remainder allocation ({@see weighted_score_calculator}). The two can
 * therefore differ by up to one allocated point on equal weights (33.33 vs 34 for three
 * equal thirds); that is the producer's own rounding, not a divergence introduced here.
 *
 * Trust model (DEC-0-18): the caller has already authenticated the Moodle session and
 * resolved the instance; this class ignores the statement's actor/authority/stored
 * (grading is attributed to the caller's `$userid`), rejects an `object.id` that does
 * not resolve to a registered iDevice of *this* instance, validates the score range,
 * recomputes/clamps the overall server-side, and is idempotent by `statement.id`
 * (`exelearning_tracking_events`).
 *
 * For packages implementing the additive contract from exelearning/exelearning#2302,
 * the overall (`itemnumber=0`) is reconstructed from the latest per-iDevice score,
 * effective weight, and deterministic order. Older packages omit those extensions and
 * retain the DEC-85-01 package-statement finalScore fallback.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ingestor {
    /** @var string[] Overall statuses that count as a terminal (finished) attempt. */
    private const TERMINAL_STATUSES = ['passed', 'failed', 'completed'];

    /**
     * Ingest one decoded xAPI statement for one user.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param \stdClass $course       The course record (for completion).
     * @param \stdClass $cm           The course_module record (for completion).
     * @param int       $userid       The grading user (the caller's $USER, never the actor).
     * @param array     $statement    The decoded xAPI statement.
     * @param string    $registration Attempt-grouping token injected by the host (the
     *                                xAPI registration; shares the SCORM sessiontoken axis).
     * @param bool      $ispreview    When true, acknowledge without grading (DEC-0-06).
     * @return array Result map: always has 'ok'. May add ignored|lifecycle|duplicate|
     *         noop|mode|error|verb|attempt|objectid|peritem|rawscore|status.
     */
    public static function ingest(
        \stdClass $exe,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        array $statement,
        string $registration,
        bool $ispreview
    ): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $norm = statement_normalizer::normalize($statement);
        if (empty($norm['ok'])) {
            return ['ok' => false, 'error' => $norm['error'] ?? 'invalidstatement'];
        }
        if (!empty($norm['ignored'])) {
            return ['ok' => true, 'ignored' => true, 'verb' => $norm['verb']];
        }
        // The registration is authoritative on the host side: prefer the one the host
        // injected/forwarded over whatever the statement carries.
        $registration = ($registration !== '') ? $registration : (string) ($norm['registration'] ?? '');

        // Preview (DEC-0-06): acknowledge, never grade, never consume idempotency.
        if ($ispreview) {
            return ['ok' => true, 'mode' => 'preview', 'verb' => $norm['verb']];
        }
        // Idempotency (DEC-0-18 §7): a statement.id already processed is not re-applied.
        if ($DB->record_exists('exelearning_tracking_events', ['statementid' => $norm['statementid']])) {
            return ['ok' => true, 'duplicate' => true, 'verb' => $norm['verb']];
        }

        // The package census (upstream PR 2302) rides on the per-page `initialized`
        // statement and lists every evaluable iDevice of that page, answered or not. It
        // is package metadata — identical for every user — so it is learned once onto
        // exelearning_grade_item and from then on lets ANY learner's partial attempt be
        // reconstructed exactly, including the iDevices they never opened.
        if ($norm['census'] !== []) {
            self::record_census($exe->id, $norm['census']);
        }

        // Lifecycle verbs carry no grade: record them for audit only.
        if (!empty($norm['lifecycle'])) {
            self::record_event($exe->id, $userid, $norm, $registration);
            return ['ok' => true, 'lifecycle' => true, 'verb' => $norm['verb']];
        }

        // Master grading switch (DEC-13-07): with grading off there are no grade items,
        // so the statement routes nowhere — a no-op, consistent with rejecting an
        // unknown objectid. Still recorded for audit/idempotency.
        if (empty($exe->gradeenabled)) {
            self::record_event($exe->id, $userid, $norm, $registration);
            return ['ok' => true, 'noop' => true, 'verb' => $norm['verb']];
        }

        // An answered for an objectid this instance does not expose is rejected loudly
        // (DEC-0-18 §4) — unlike the SCORM path, which silently drops unknown ids.
        if ($norm['verb'] === 'answered' && !self::objectid_registered($exe->id, (string) $norm['objectid'])) {
            return ['ok' => false, 'error' => 'unknownobjectid', 'verb' => 'answered'];
        }

        $grademax = (float) ($exe->grademax ?? 100);
        $grademin = (float) ($exe->grademin ?? 0);
        $grademethod = (int) ($exe->grademethod ?? attempts::GRADE_HIGHEST);
        $grademodel = (int) ($exe->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

        // Serialise allocation + writes per (instance, user), exactly like
        // track::ingest(): xAPI and SCORM share the attempt axis and must not race
        // each other on the unique (exelearningid, userid, attempt, itemnumber) index.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_exelearning');
        $lock = $lockfactory->get_lock('ingest_' . $exe->id . '_' . $userid, 5);
        try {
            $attempt = attempts::resolve_attempt_number($exe->id, $userid, $registration);
            $attemptexisted = $DB->record_exists('exelearning_attempt', [
                'exelearningid' => $exe->id,
                'userid'        => $userid,
                'attempt'       => $attempt,
            ]);
            $prioroverallstatus = $attemptexisted ? $DB->get_field('exelearning_attempt', 'status', [
                'exelearningid' => $exe->id,
                'userid'        => $userid,
                'attempt'       => $attempt,
                'itemnumber'    => 0,
            ]) : false;

            // Attempt cap (DEC-0-07 phase 2): a fresh registration over the cap is rejected.
            $maxattempt = (int) ($exe->maxattempt ?? 0);
            if ($maxattempt > 0) {
                $sessionknown = ($registration !== '') && $DB->record_exists(
                    'exelearning_attempt',
                    ['exelearningid' => $exe->id, 'userid' => $userid, 'sessiontoken' => $registration]
                );
                $priorcount = attempts::count_user_attempts($exe->id, $userid);
                if (!$sessionknown && $priorcount >= $maxattempt) {
                    return [
                        'ok'         => false,
                        'error'      => 'maxattemptsreached',
                        'attempts'   => $priorcount,
                        'maxattempt' => $maxattempt,
                    ];
                }
            }

            $result = ['ok' => true, 'verb' => $norm['verb'], 'attempt' => $attempt];

            if ($norm['verb'] === 'answered') {
                // Unreadable reconstruction metadata never costs the learner the score
                // (the endpoint's ok:false is final for the listener), but it does mean a
                // broken emitter: surface it to developers instead of swallowing it.
                if (!empty($norm['metadatawarning'])) {
                    debugging(
                        'mod_exelearning: ' . $norm['metadatawarning'] . ' on statement '
                            . $norm['statementid'] . '; the iDevice score was graded but is '
                            . 'excluded from the reconstructed overall.',
                        DEBUG_DEVELOPER
                    );
                }
                // Per-iDevice column(s): reuse the shared, objectid-routed applier.
                $peritem = track::apply_item_scores($exe, $userid, $attempt, $norm['itemscores'], $registration);
                $result['objectid'] = $norm['objectid'];
                $result['peritem'] = $peritem;

                // New-contract statements carry both fields together, and
                // apply_item_scores() has just persisted them alongside the score on the
                // upserted per-item row, so navigating to another iframe document does not
                // lose prior page contributions. Re-answering updates that same row.
                if ($norm['weight'] !== null && $norm['ideviceorder'] !== null && $peritem !== []) {
                    $reconstructed = self::reconstruct_overall_pct($exe->id, $userid, $attempt);
                    if ($reconstructed !== null) {
                        // Being able to compute a number is NOT the same as the attempt
                        // being finished: with the package census a single answer already
                        // yields an exact grade. The attempt ends when every gradable
                        // iDevice has been answered, and that is also the only end signal
                        // a multipage package will ever send — upstream emits NO
                        // package-level verdict when pageCount > 1 (ADR-2302-01), so
                        // without deriving it here every multipage attempt would stay
                        // 'incomplete' for ever: no completionstatusrequired, no
                        // attempt_completed. A package statement still overrides it when
                        // one does arrive. Short of that, never downgrade a status a
                        // package statement already set (DEC-68-01).
                        $overall = ($reconstructed / 100.0) * $grademax;
                        $status = self::attempt_is_complete($exe->id, $userid, $attempt)
                            ? self::derive_terminal_status($exe, $overall, $grademin, $grademax)
                            : ((is_string($prioroverallstatus) && $prioroverallstatus !== '')
                                ? $prioroverallstatus
                                : 'incomplete');
                        $result['rawscore'] = self::record_overall(
                            $exe,
                            $userid,
                            $attempt,
                            $overall,
                            $status,
                            $registration,
                            $grademax,
                            $grademin,
                            $grademethod,
                            $grademodel
                        );
                        $result['status'] = $status;

                        // A published grade must recompute completion here too: the
                        // reconstructed overall may have just crossed gradepass
                        // (completionpassgrade / DEC-69-01) and the learner can close the
                        // tab before any package statement arrives.
                        $completion = new \completion_info($course);
                        if ($completion->is_enabled($cm)) {
                            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
                        }
                        self::maybe_emit_completed(
                            $exe,
                            $course,
                            $cm,
                            $userid,
                            $attempt,
                            (float) $result['rawscore'],
                            $status,
                            $prioroverallstatus
                        );
                    }
                }
                // Package lifecycle statements still determine terminal status. The
                // answered event only creates the attempt and, for new packages, makes
                // the independently reconstructed grade durable immediately.
                self::maybe_emit_started($exe, $course, $cm, $userid, $attempt, $attemptexisted);
            } else {
                // Package verbs remain the lifecycle/status signal. For a new-contract
                // attempt, preserve the independently reconstructed numeric result; only
                // legacy attempts still use the package's page-local finalScore.
                $reconstructed = self::reconstruct_overall_pct($exe->id, $userid, $attempt);
                $overallpct = $reconstructed ?? (float) $norm['overallpct'];
                $status = (string) $norm['status'];
                $finaloverall = self::record_overall(
                    $exe,
                    $userid,
                    $attempt,
                    ($overallpct / 100.0) * $grademax,
                    $status,
                    $registration,
                    $grademax,
                    $grademin,
                    $grademethod,
                    $grademodel
                );
                $result['rawscore'] = $finaloverall;
                $result['status'] = $status;

                // Recompute completion (completionpassgrade / DEC-69-01), then the
                // once-per-attempt lifecycle events (start + outcome).
                $completion = new \completion_info($course);
                if ($completion->is_enabled($cm)) {
                    $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
                }
                self::maybe_emit_started($exe, $course, $cm, $userid, $attempt, $attemptexisted);
                self::maybe_emit_completed(
                    $exe,
                    $course,
                    $cm,
                    $userid,
                    $attempt,
                    (float) $finaloverall,
                    $status,
                    $prioroverallstatus
                );
            }

            self::record_event($exe->id, $userid, $norm, $registration);
            return $result;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Learn the package's evaluable-iDevice census onto the grade items.
     *
     * Package metadata, not user data: the same values for every learner and attempt, so
     * one learner visiting every page is enough to make the whole package reconstructible
     * for everybody afterwards. An id the instance does not expose as a gradable iDevice
     * has no row to learn onto and is ignored, exactly like an unknown objectid in
     * {@see track::apply_item_scores()}.
     *
     * @param int $exelearningid Activity instance id.
     * @param array<string, array{weight: float, ideviceorder: int}> $census Keyed by objectid.
     * @return void
     */
    private static function record_census(int $exelearningid, array $census): void {
        global $DB;

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exelearningid, 'deleted' => 0],
            '',
            'id, objectid, xapiweight, xapiorder'
        );
        $byobjectid = [];
        foreach ($rows as $row) {
            $byobjectid[(string) $row->objectid] = $row;
        }

        $now = time();
        foreach ($census as $objectid => $meta) {
            if (!isset($byobjectid[(string) $objectid])) {
                continue;
            }
            $row = $byobjectid[(string) $objectid];
            // A re-export can legitimately change weights or reorder pages, so the latest
            // census wins; skip the write when nothing moved.
            $sameweight = ($row->xapiweight !== null)
                && (abs((float) $row->xapiweight - $meta['weight']) < 0.00001);
            if ($sameweight && (int) $row->xapiorder === $meta['ideviceorder']) {
                continue;
            }
            $DB->update_record('exelearning_grade_item', (object) [
                'id'           => $row->id,
                'xapiweight'   => $meta['weight'],
                'xapiorder'    => $meta['ideviceorder'],
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Whether every gradable iDevice of the package has been answered in this attempt.
     *
     * This is the end-of-attempt test, deliberately separate from whether a grade can be
     * computed: the package census makes a one-answer attempt exactly gradable, but it is
     * obviously not finished.
     *
     * @param int $exelearningid Activity instance id.
     * @param int $userid Attempt owner.
     * @param int $attempt Attempt number.
     * @return bool
     */
    private static function attempt_is_complete(int $exelearningid, int $userid, int $attempt): bool {
        global $DB;

        $expected = $DB->count_records('exelearning_grade_item', [
            'exelearningid' => $exelearningid,
            'deleted'       => 0,
        ]);
        if ($expected === 0) {
            return false;
        }
        $answered = $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {exelearning_attempt} a
               JOIN {exelearning_grade_item} gi ON gi.exelearningid = a.exelearningid
                    AND gi.itemnumber = a.itemnumber AND gi.deleted = 0
              WHERE a.exelearningid = ? AND a.userid = ? AND a.attempt = ? AND a.itemnumber > 0',
            [$exelearningid, $userid, $attempt]
        );
        return $answered >= $expected;
    }

    /**
     * The terminal status of a fully reported attempt, in Moodle's own terms.
     *
     * The emitter calls a single-page package passed at >= 50/100. Moodle's equivalent
     * knob is the activity's `gradepass`, so that is what decides here; with no pass
     * grade configured there is no pass/fail distinction and the attempt is merely
     * `completed`. Never returns `incomplete`: the caller only reaches this once every
     * gradable iDevice of the package has reported.
     *
     * @param \stdClass $exe Activity instance.
     * @param float $overall Raw overall before clamping.
     * @param float $grademin Activity minimum grade.
     * @param float $grademax Activity maximum grade.
     * @return string completed|passed|failed
     */
    private static function derive_terminal_status(
        \stdClass $exe,
        float $overall,
        float $grademin,
        float $grademax
    ): string {
        $gradepass = (float) ($exe->gradepass ?? 0);
        if ($gradepass <= 0.0) {
            return 'completed';
        }
        // Compare the value that actually reaches the gradebook, not the raw one.
        return (max($grademin, min($grademax, $overall)) >= $gradepass) ? 'passed' : 'failed';
    }

    /**
     * Reconstruct the exact current package score from persisted new-contract rows.
     *
     * The attempt table is already the latest-state map: its unique key contains one
     * row per itemnumber, and a repeated answer overwrites that row. Nullable xAPI
     * metadata excludes legacy SCORM and pre-#2302 xAPI rows from this path, and the
     * join drops rows whose iDevice disappeared in a re-upload (`deleted = 1`), which
     * must no longer weigh on the result.
     *
     * The denominator is the whole publication, never the answered subset: the emitter
     * only ever tracks what the learner answered, so renormalising over that subset would
     * inflate a partial attempt — reporting 100 for a learner who only answered the
     * lightest question. Two ways to get the full weight vector, in order of preference:
     *
     * 1. The package census learned from the `initialized` statements. Once every
     *    gradable iDevice of the instance carries its weight and order, an unanswered one
     *    is scored as the 0 eXeLearning counts for it and even a one-answer attempt is
     *    exact. Because the census is package metadata this holds for every learner as
     *    soon as anybody has visited all the pages.
     * 2. Without a census, only a fully answered attempt carries every weight, so
     *    anything less returns null and keeps the DEC-85-01 package-score fallback.
     *
     * An attempt with no new-contract row at all is legacy in either case: it must keep
     * the package-statement score rather than be reconstructed as a row of zeroes.
     *
     * @param int $exelearningid Activity instance id.
     * @param int $userid Attempt owner.
     * @param int $attempt Attempt number.
     * @return float|null Percentage in 0..100, or null for a legacy/incomplete-state attempt.
     */
    private static function reconstruct_overall_pct(int $exelearningid, int $userid, int $attempt): ?float {
        global $DB;

        $roster = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exelearningid, 'deleted' => 0],
            'xapiorder ASC, itemnumber ASC',
            'itemnumber, xapiweight, xapiorder'
        );
        if ($roster === []) {
            return null;
        }
        $scores = $DB->get_records_select(
            'exelearning_attempt',
            'exelearningid = ? AND userid = ? AND attempt = ? AND itemnumber > 0',
            [$exelearningid, $userid, $attempt],
            '',
            'itemnumber, scaledscore, xapiweight, xapiorder'
        );

        // A legacy attempt reports no weight at all; reconstructing it would publish a
        // package of zeroes over the score its package statement legitimately carries.
        $hasnewcontract = false;
        foreach ($scores as $score) {
            if ($score->xapiweight !== null && $score->xapiorder !== null) {
                $hasnewcontract = true;
                break;
            }
        }
        if (!$hasnewcontract) {
            return null;
        }

        $items = [];
        foreach ($roster as $itemnumber => $gradeitem) {
            $score = $scores[$itemnumber] ?? null;
            $scorepct = ($score !== null) ? ((float) $score->scaledscore * 100.0) : 0.0;

            if ($gradeitem->xapiweight !== null && $gradeitem->xapiorder !== null) {
                // Censused: this iDevice weighs whether or not it was ever answered.
                $items[] = [
                    'scorepct' => $scorepct,
                    'weight' => (float) $gradeitem->xapiweight,
                    'ideviceorder' => (int) $gradeitem->xapiorder,
                ];
                continue;
            }
            // Not censused: only this attempt's own answer can supply its weight, and a
            // single missing one makes the package total unknowable.
            if ($score === null || $score->xapiweight === null || $score->xapiorder === null) {
                return null;
            }
            $items[] = [
                'scorepct' => $scorepct,
                'weight' => (float) $score->xapiweight,
                'ideviceorder' => (int) $score->xapiorder,
            ];
        }
        return weighted_score_calculator::calculate($items);
    }

    /**
     * Persist and, in OVERALL mode, publish one overall result.
     *
     * @param \stdClass $exe Activity instance.
     * @param int $userid Attempt owner.
     * @param int $attempt Attempt number.
     * @param float $overall Raw overall, clamped here to the activity grade range.
     * @param string $status completed|passed|failed|incomplete.
     * @param string $registration Attempt-grouping token.
     * @param float $grademax Activity maximum grade.
     * @param float $grademin Activity minimum grade.
     * @param int $grademethod Cross-attempt aggregation method.
     * @param int $grademodel OVERALL or PERITEM.
     * @return float Aggregated overall grade.
     */
    private static function record_overall(
        \stdClass $exe,
        int $userid,
        int $attempt,
        float $overall,
        string $status,
        string $registration,
        float $grademax,
        float $grademin,
        int $grademethod,
        int $grademodel
    ): float {
        // Clamp here rather than at each call site so the reconstructed and the
        // package-score paths can never disagree on the instance grade range.
        $overall = max($grademin, min($grademax, $overall));
        attempts::record_item($exe->id, $userid, $attempt, 0, $overall, $grademax, $status, $registration);
        $scaledoverall = attempts::aggregate_scaled($exe->id, $userid, 0, $grademethod);
        $finaloverall = ($scaledoverall === null) ? $overall : ($scaledoverall * $grademax);

        // Publish the aggregated overall ONLY in OVERALL mode (DEC-25-01); in
        // PERITEM the per-iDevice columns carry the gradebook.
        if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
            grade_update(
                'mod/exelearning',
                $exe->course,
                'mod',
                'exelearning',
                $exe->id,
                0,
                (object) ['userid' => $userid, 'rawgrade' => $finaloverall, 'feedback' => null],
                [
                    'gradetype' => GRADE_TYPE_VALUE,
                    'grademax'  => $exe->grademax ?? 100,
                    'grademin'  => $exe->grademin ?? 0,
                    'display'   => (int) ($exe->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
                    'itemname'  => clean_param($exe->name, PARAM_NOTAGS),
                    'hidden'    => 0,
                ]
            );
        }
        return $finaloverall;
    }

    /**
     * Whether an objectid resolves to a registered (non-deleted) grade item of the
     * instance — the ownership/identity check (DEC-5-01 / DEC-0-18 §4).
     *
     * @param int    $exelearningid
     * @param string $objectid
     * @return bool
     */
    private static function objectid_registered(int $exelearningid, string $objectid): bool {
        global $DB;
        return $DB->record_exists('exelearning_grade_item', [
            'exelearningid' => $exelearningid,
            'objectid'      => $objectid,
            'deleted'       => 0,
        ]);
    }

    /**
     * Persists the audit/idempotency row for a processed statement.
     *
     * Idempotent under concurrency: the UNIQUE(statementid) index rejects a racing
     * duplicate, which is swallowed (the grade writes are themselves idempotent).
     *
     * @param int    $exelearningid
     * @param int    $userid
     * @param array  $norm         The normalizer output.
     * @param string $registration
     * @return void
     */
    private static function record_event(int $exelearningid, int $userid, array $norm, string $registration): void {
        global $DB;
        try {
            $DB->insert_record('exelearning_tracking_events', (object) [
                'exelearningid' => $exelearningid,
                'userid'        => $userid,
                'statementid'   => (string) $norm['statementid'],
                'verb'          => (string) $norm['verb'],
                'objectid'      => isset($norm['objectid']) ? (string) $norm['objectid'] : null,
                'registration'  => ($registration !== '') ? $registration : null,
                'scaled'        => array_key_exists('scaled', $norm) ? (float) $norm['scaled'] : null,
                'timecreated'   => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // Only the UNIQUE(statementid) race is benign (a concurrent request already
            // claimed this statement.id). Any other write failure — a NUMBER precision /
            // length violation, a dropped connection, disk-full — must NOT be hidden: it
            // would lose the audit row while the grade was already written. Re-check the
            // dedup key and swallow only the genuine duplicate; rethrow the rest.
            if (!$DB->record_exists('exelearning_tracking_events', ['statementid' => (string) $norm['statementid']])) {
                throw $e;
            }
            debugging(
                'mod_exelearning: duplicate xAPI statement.id on insert (race), ignored: '
                    . $norm['statementid'],
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Fires attempt_started once, only on the commit that creates the attempt
     * (mirrors the SCORM path's observability contract, DEC-68-01).
     *
     * @param \stdClass $exe
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param int       $userid
     * @param int       $attempt
     * @param bool      $attemptexisted Whether the attempt already had rows before this commit.
     * @return void
     */
    private static function maybe_emit_started(
        \stdClass $exe,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        int $attempt,
        bool $attemptexisted
    ): void {
        if ($attemptexisted) {
            return;
        }
        $event = \mod_exelearning\event\attempt_started::create([
            'context'       => \context_module::instance($cm->id),
            'objectid'      => $exe->id,
            'relateduserid' => $userid,
            'other'         => ['attempt' => $attempt],
        ]);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('exelearning', $exe);
        $event->trigger();
    }

    /**
     * Fires attempt_completed once, only on the transition into a terminal status
     * (mirrors the SCORM path's observability contract, DEC-68-01).
     *
     * @param \stdClass    $exe
     * @param \stdClass    $course
     * @param \stdClass    $cm
     * @param int          $userid
     * @param int          $attempt
     * @param float        $score
     * @param string       $status
     * @param string|false $priorstatus The attempt's overall status before this commit.
     * @return void
     */
    private static function maybe_emit_completed(
        \stdClass $exe,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        int $attempt,
        float $score,
        string $status,
        $priorstatus
    ): void {
        $wasterminal = in_array((string) $priorstatus, self::TERMINAL_STATUSES, true);
        $isterminal = in_array($status, self::TERMINAL_STATUSES, true);
        if (!$isterminal || $wasterminal) {
            return;
        }
        $event = \mod_exelearning\event\attempt_completed::create([
            'context'       => \context_module::instance($cm->id),
            'objectid'      => $exe->id,
            'relateduserid' => $userid,
            'other'         => ['attempt' => $attempt, 'score' => $score, 'status' => $status],
        ]);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('exelearning', $exe);
        $event->trigger();
    }
}
