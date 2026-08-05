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
 * `grade_update()` and `completion_info` — so xAPI and SCORM grades cannot diverge.
 * `track::ingest()` itself is left untouched (the SCORM productive path).
 *
 * Trust model (DEC-0063): the caller has already authenticated the Moodle session and
 * resolved the instance; this class ignores the statement's actor/authority/stored
 * (grading is attributed to the caller's `$userid`), rejects an `object.id` that does
 * not resolve to a registered iDevice of *this* instance, validates the score range,
 * recomputes/clamps the overall server-side, and is idempotent by `statement.id`
 * (`exelearning_tracking_events`).
 *
 * The overall (`itemnumber=0`) is taken from the package `passed/failed/completed`
 * statement — the producer's *weighted* finalScore — because per-iDevice `answered`
 * statements carry no weight (DEC-85-01); it is validated and clamped server-side
 * rather than blindly trusted (spirit of DEC-6-01).
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
     * @param bool      $ispreview    When true, acknowledge without grading (DEC-0006).
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

        // Preview (DEC-0006): acknowledge, never grade, never consume idempotency.
        if ($ispreview) {
            return ['ok' => true, 'mode' => 'preview', 'verb' => $norm['verb']];
        }
        // Idempotency (DEC-0063 §7): a statement.id already processed is not re-applied.
        if ($DB->record_exists('exelearning_tracking_events', ['statementid' => $norm['statementid']])) {
            return ['ok' => true, 'duplicate' => true, 'verb' => $norm['verb']];
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
        // (DEC-0063 §4) — unlike the SCORM path, which silently drops unknown ids.
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

            // Attempt cap (DEC-0007 phase 2): a fresh registration over the cap is rejected.
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
                // Per-iDevice column(s): reuse the shared, objectid-routed applier.
                $peritem = track::apply_item_scores($exe, $userid, $attempt, $norm['itemscores'], $registration);
                $result['objectid'] = $norm['objectid'];
                $result['peritem'] = $peritem;
                // The package statement (emitted right after each answered) carries the
                // authoritative overall, so attempt_started is the only lifecycle event
                // to fire here (on the commit that creates the attempt).
                self::maybe_emit_started($exe, $course, $cm, $userid, $attempt, $attemptexisted);
            } else {
                // Package verb: the overall (itemnumber=0). Take the producer's weighted
                // finalScore, validate-and-clamp to the grade range (DEC-85-01/DEC-6-01).
                $overall = max($grademin, min($grademax, ((float) $norm['overallpct'] / 100.0) * $grademax));
                $status = (string) $norm['status'];
                attempts::record_item($exe->id, $userid, $attempt, 0, $overall, $grademax, $status, $registration);
                $scaledoverall = attempts::aggregate_scaled($exe->id, $userid, 0, $grademethod);
                $finaloverall = ($scaledoverall === null) ? $overall : ($scaledoverall * $grademax);

                // Publish the aggregated overall ONLY in OVERALL mode (DEC-25-01); in
                // PERITEM the per-iDevice columns carry the gradebook and the overall
                // item exists only for completionpassgrade.
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
     * Whether an objectid resolves to a registered (non-deleted) grade item of the
     * instance — the ownership/identity check (DEC-5-01 / DEC-0063 §4).
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
