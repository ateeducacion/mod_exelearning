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

namespace mod_exelearning\local;

/**
 * SCORM tracking helpers shared between the `track.php` endpoint and its tests.
 *
 * Holds the per-iDevice routing logic so it can be unit-tested without invoking
 * the AJAX script. Two concerns live here:
 *
 *  - {@see self::parse_suspend_data()} decodes eXeLearning v4's `cmi.suspend_data`
 *    string. It is the single PHP source of truth for that format and mirrors the
 *    JavaScript parser in the `view.php` SCORM shim.
 *  - {@see self::apply_item_scores()} routes per-iDevice scores to the gradebook by
 *    the stable `objectid` captured client-side (DEC-5-01 / RIE-007), instead of by
 *    the page-local index N the producer emits — which collides across pages.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class track {
    /**
     * Ingests a SCORM tracking payload: records the attempt, routes per-iDevice
     * scores and updates the gradebook + completion. Shared by the web `track.php`
     * endpoint (sesskey-authenticated) and the `save_track` web service (token
     * authenticated for the mobile app), so the scoring pipeline — and its
     * server-side safeguards — live in one tested place.
     *
     * The caller is responsible for authentication, capability and context checks;
     * this method trusts neither the client's overall score (it recomputes it from
     * the per-iDevice objectid map when one is supplied, DEC-6-01) nor the client's
     * itemnumbers (scores are routed by stable objectid, DEC-5-01, and an objectid
     * the package does not expose is ignored). Scores are clamped to the instance
     * grade range and the attempt cap (maxattempt) is enforced.
     *
     * @param \stdClass $exe       The exelearning instance record.
     * @param \stdClass $course    The course record (for completion).
     * @param \stdClass $cm        The course_module record (for completion).
     * @param int       $userid    The grading user.
     * @param array     $payload   Decoded payload: {cmi:{...}, session?:string, itemscores?:array}.
     * @param bool      $ispreview When true, acknowledge the score without grading (DEC-0-06).
     * @return array Result map: always has 'ok'. May add noop|mode|error|attempt|rawscore|status|peritem.
     */
    public static function ingest(
        \stdClass $exe,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        array $payload,
        bool $ispreview
    ): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $cmi = (isset($payload['cmi']) && is_array($payload['cmi'])) ? $payload['cmi'] : [];
        // Page-load session token (DEC-0-07): groups auto-commits into one attempt.
        $sessiontoken = isset($payload['session'])
                ? substr(clean_param((string) $payload['session'], PARAM_ALPHANUMEXT), 0, 255) : '';
        $rawscore = $cmi['cmi.core.score.raw'] ?? $cmi['cmi.score.raw'] ?? null;
        $maxscore = $cmi['cmi.core.score.max'] ?? $cmi['cmi.score.max'] ?? null;
        $status   = $cmi['cmi.core.lesson_status'] ?? $cmi['cmi.completion_status'] ?? null;

        if ($rawscore === null || $rawscore === '') {
            // Nothing to persist yet; just acknowledge.
            return ['ok' => true, 'noop' => true];
        }

        $grademax = (float) ($exe->grademax ?? 100);
        $grademin = (float) ($exe->grademin ?? 0);

        // Normalise to the grade item scale, then clamp so an out-of-range CMI value
        // cannot be persisted as the attempt rawscore.
        $score = (float) $rawscore;
        if ($maxscore !== null && (float) $maxscore > 0) {
            $score = ($score / (float) $maxscore) * $grademax;
        }
        $score = max($grademin, min($grademax, $score));

        // Preview mode: do NOT update the gradebook; only acknowledge (DEC-0-06).
        if ($ispreview) {
            return ['ok' => true, 'mode' => 'preview', 'rawscore' => $score, 'status' => $status];
        }

        // Per-iDevice routing: prefer the stable objectid map (DEC-5-01); fall back
        // to the page-local index from cmi.suspend_data only when none is supplied.
        $itemscores = (isset($payload['itemscores']) && is_array($payload['itemscores']))
                ? $payload['itemscores'] : [];
        if (count($itemscores) > 1000) {
            // A well-formed package emits one entry per gradable iDevice; a map far
            // larger than any real package is malformed/abusive — drop it.
            debugging(
                'mod_exelearning: itemscores map exceeded the sane size cap and was ignored.',
                DEBUG_DEVELOPER
            );
            $itemscores = [];
        }
        // Only scores for registered, non-deleted gradable iDevices may influence the
        // grade. apply_item_scores() already ignores unknown objectids, but the
        // overall recompute below operates on the whole map, so filter here too — a
        // client must not be able to skew the overall by injecting extra objectids.
        if ($itemscores !== []) {
            $registered = array_flip(array_map('strval', self::registered_objectids($exe)));
            $itemscores = array_filter(
                $itemscores,
                fn($key) => isset($registered[(string) $key]),
                ARRAY_FILTER_USE_KEY
            );
        }
        $suspend = $cmi['cmi.suspend_data'] ?? '';
        $peritem = is_string($suspend) ? self::parse_suspend_data($suspend) : [];
        if (is_string($suspend) && $suspend !== '' && $peritem === [] && $itemscores === []) {
            debugging(
                'mod_exelearning: cmi.suspend_data was non-empty but no per-iDevice '
                    . 'results could be parsed from it.',
                DEBUG_DEVELOPER
            );
        }

        $grademethod = (int) ($exe->grademethod ?? attempts::GRADE_HIGHEST);
        $grademodel = (int) ($exe->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
        $itemdetailsbase = [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => $exe->grademax ?? 100,
            'grademin'  => $exe->grademin ?? 0,
            'display'   => (int) ($exe->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
        ];

        // Serialize attempt allocation + writes per (instance, user): two concurrent
        // first commits with different session tokens (e.g. the same student in two
        // tabs, each page load getting its own sessiontoken and autocommitting ~500 ms
        // after the first score) would otherwise both read the same MAX(attempt),
        // allocate the same number and collide on the unique
        // (exelearningid, userid, attempt, itemnumber) index (db/install.xml). The web
        // shim self-heals (it retries on a failed POST once MAX has moved), but the
        // save_track WS would surface the raw dml_write_exception to the mobile client.
        // The lock covers allocation, the maxattempt cap and every write/aggregation
        // that reads back what was just written (this plan's reasoning).
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_exelearning');
        $lock = $lockfactory->get_lock('ingest_' . $exe->id . '_' . $userid, 5);
        try {
            // Resolve the attempt number (one per page load / session).
            $attempt = attempts::resolve_attempt_number($exe->id, $userid, $sessiontoken);
            // Whether this attempt already has rows, checked before any write for it:
            // drives the one-shot attempt_started event below (fired only when this
            // commit is what brings the attempt into existence).
            $attemptexisted = $DB->record_exists('exelearning_attempt', [
                'exelearningid' => $exe->id,
                'userid'        => $userid,
                'attempt'       => $attempt,
            ]);
            // The attempt's overall status before this commit (false when none yet):
            // drives the one-shot attempt_completed event, fired only on the commit
            // that first moves the attempt into a terminal status.
            $prioroverallstatus = $attemptexisted ? $DB->get_field('exelearning_attempt', 'status', [
                'exelearningid' => $exe->id,
                'userid'        => $userid,
                'attempt'       => $attempt,
                'itemnumber'    => 0,
            ]) : false;

            // Attempt limit (DEC-0-07 phase 2): a fresh session over the cap is rejected.
            $maxattempt = (int) ($exe->maxattempt ?? 0);
            if ($maxattempt > 0) {
                $sessionknown = ($sessiontoken !== '') && $DB->record_exists(
                    'exelearning_attempt',
                    ['exelearningid' => $exe->id, 'userid' => $userid, 'sessiontoken' => $sessiontoken]
                );
                $priorcount = attempts::count_user_attempts($exe->id, $userid);
                if (!$sessionknown && $priorcount >= $maxattempt) {
                    // The try/finally releases the lock on this early return too.
                    return [
                        'ok'         => false,
                        'error'      => 'maxattemptsreached',
                        'attempts'   => $priorcount,
                        'maxattempt' => $maxattempt,
                    ];
                }
            }

            // 1) Attempts + aggregated grade per iDevice (itemnumber > 0).
            $persaved = [];
            if ($itemscores !== []) {
                $persaved = self::apply_item_scores($exe, $userid, $attempt, $itemscores, $sessiontoken);
            } else if ($peritem) {
                $persaved = self::apply_legacy_peritem($exe, $userid, $attempt, $peritem, $sessiontoken);
            }

            // 2) Overall (itemnumber=0): recompute from the per-iDevice scores when an
            // objectid map was supplied (DEC-6-01), never trusting the client overall.
            if ($itemscores !== []) {
                $overallpct = self::recompute_overall_pct($itemscores);
                if ($overallpct !== null) {
                    $recomputed = max($grademin, min($grademax, ($overallpct / 100.0) * $grademax));
                    if (abs($recomputed - $score) > 0.01) {
                        debugging(
                            'mod_exelearning: overall recomputed from itemscores (' . $recomputed
                                . ') diverges from cmi.core.score.raw (' . $score
                                . '); using the recomputed value (DEC-6-01).',
                            DEBUG_DEVELOPER
                        );
                    }
                    $score = $recomputed;
                }
            }
            $overallstatus = in_array($status, ['passed', 'failed', 'completed', 'incomplete'], true)
                    ? $status : 'completed';
            // Marked completion-only when the activity is not graded (DEC-124-03). The
            // row is still written — DEC-69-01's completion by status needs it — but its
            // score never went through the server-side recompute (no registered
            // objectids to recompute from), so it must not become a mark when grading
            // comes back on.
            attempts::record_item(
                $exe->id,
                $userid,
                $attempt,
                0,
                $score,
                $grademax,
                $overallstatus,
                $sessiontoken,
                !empty($exe->gradeenabled)
            );
            $scaledoverall = attempts::aggregate_scaled($exe->id, $userid, 0, $grademethod);
            $finaloverall = ($scaledoverall === null) ? $score : ($scaledoverall * $grademax);

            // Publish the aggregated overall grade ONLY in OVERALL mode (DEC-25-01): in
            // PERITEM the per-iDevice grades carry the gradebook.
            //
            // And only while the master grading switch is on (DEC-13-07, DEC-124-02).
            // With it off, exelearning_sync_grade_items() has deleted this instance's
            // grade items — and grade_update() RECREATES a deleted item, so publishing
            // here would resurrect the very column the teacher turned off. It would also
            // publish the CLIENT's cmi.core.score.raw, because with no registered
            // objectids there is nothing for the server-side recompute to work from.
            //
            // The attempt row above is still written, deliberately: DEC-69-01's
            // completion by status reads it filtering on exelearningid, userid,
            // itemnumber and status, never on gradeenabled, and mod_form.php does not
            // gate that rule on the switch either. It is also the history DEC-13-07
            // preserves so grading recomputes when the switch goes back on (DEC-124-01).
            //
            // apply_one()'s per-iDevice grade_update() needs no guard of its own: it
            // routes by registered objectid (DEC-5-01), and with the switch off none is
            // registered, so it never runs. A second check there would be dead code
            // implying a path that does not exist — pinned by the peritem case of
            // test_ingest_with_grading_disabled_records_attempt_but_publishes_no_grade.
            $result = GRADE_UPDATE_OK;
            if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL && !empty($exe->gradeenabled)) {
                $grade = (object) [
                    'userid'   => $userid,
                    'rawgrade' => $finaloverall,
                    'feedback' => null,
                ];
                $result = grade_update(
                    'mod/exelearning',
                    $exe->course,
                    'mod',
                    'exelearning',
                    $exe->id,
                    0,
                    $grade,
                    $itemdetailsbase + [
                        'itemname' => clean_param($exe->name, PARAM_NOTAGS),
                        'hidden'   => 0,
                    ]
                );
            }

            // Recalculate completion (completionpassgrade, SCORM style).
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
            }

            // Observability events (DEC-68-01, extending DEC-26-03): emitted only now the
            // attempt is persisted, so the preview / no-op / over-cap commits that
            // returned earlier never reach the logstore. Both are once-per-attempt
            // LEVEL_PARTICIPATING learner events (begin + outcome), NOT per-commit — the
            // ~500 ms autocommit would flood the log, which is why DEC-26-03 rejected a
            // per-commit event. The shared pipeline means the web (track.php) and mobile
            // (save_track) paths emit the same signal.
            self::emit_tracking_events(
                $exe,
                $course,
                $cm,
                $userid,
                $attempt,
                (float) $finaloverall,
                (string) $overallstatus,
                $prioroverallstatus,
                $attemptexisted
            );

            return [
                'ok'       => $result === GRADE_UPDATE_OK,
                'attempt'  => $attempt,
                'rawscore' => $finaloverall,
                'status'   => $status,
                'peritem'  => $persaved,
            ];
        } finally {
            // On a timeout get_lock() returns false and we proceed without the lock:
            // degraded mode equals today's unprotected behaviour, so a wedged request
            // never blocks a legitimate commit. The guard skips release() in that case.
            if ($lock) {
                $lock->release();
            }
        }
    }

    /** @var string[] Overall statuses that count as a terminal (finished) attempt. */
    private const TERMINAL_STATUSES = ['passed', 'failed', 'completed'];

    /**
     * Triggers the once-per-attempt lifecycle events for a persisted tracking commit:
     * attempt_started (on the commit that creates the attempt) and attempt_completed
     * (on the commit that first moves the attempt into a terminal status). Kept out of
     * ingest() to keep that method focused; both events are part of the public
     * observability contract (DEC-68-01, extending DEC-26-03) and are deliberately NOT
     * per-commit.
     *
     * @param \stdClass    $exe            The exelearning instance record.
     * @param \stdClass    $course         The course record.
     * @param \stdClass    $cm             The course_module record.
     * @param int          $userid         The learner whose attempt was recorded.
     * @param int          $attempt        Attempt number that was written.
     * @param float        $score          Server-side overall grade after aggregation.
     * @param string       $status         Overall status just recorded for the attempt.
     * @param string|false $priorstatus    The attempt's overall status before this commit.
     * @param bool         $attemptexisted Whether the attempt already had rows before this commit.
     * @return void
     */
    private static function emit_tracking_events(
        \stdClass $exe,
        \stdClass $course,
        \stdClass $cm,
        int $userid,
        int $attempt,
        float $score,
        string $status,
        $priorstatus,
        bool $attemptexisted
    ): void {
        $base = [
            'context'       => \context_module::instance($cm->id),
            'objectid'      => $exe->id,
            'relateduserid' => $userid,
        ];
        // The attempt_started event fires once, only on the commit creating the attempt.
        if (!$attemptexisted) {
            $started = \mod_exelearning\event\attempt_started::create(
                $base + ['other' => ['attempt' => $attempt]]
            );
            $started->add_record_snapshot('course_modules', $cm);
            $started->add_record_snapshot('course', $course);
            $started->add_record_snapshot('exelearning', $exe);
            $started->trigger();
        }
        // The attempt_completed event fires once, only on the transition into terminal.
        $wasterminal = in_array((string) $priorstatus, self::TERMINAL_STATUSES, true);
        $isterminal = in_array($status, self::TERMINAL_STATUSES, true);
        if ($isterminal && !$wasterminal) {
            $completed = \mod_exelearning\event\attempt_completed::create(
                $base + ['other' => ['attempt' => $attempt, 'score' => $score, 'status' => $status]]
            );
            $completed->add_record_snapshot('course_modules', $cm);
            $completed->add_record_snapshot('course', $course);
            $completed->add_record_snapshot('exelearning', $exe);
            $completed->trigger();
        }
    }

    /**
     * Decodes an eXeLearning v4 `cmi.suspend_data` string into per-index results.
     *
     * The producer (`public/app/common/common.js`) serialises one entry per scored
     * iDevice as `{N}. "{title}"; {scoreLabel}: {S}%; {weightLabel}: {W}%`, joined
     * by ".\t". N is the page-local DOM index of the iDevice (NOT our itemnumber);
     * see DEC-5-01. The score/weight labels are localised, hence the `[^:]+` parts.
     *
     * @param string $suspend Raw cmi.suspend_data value.
     * @return array Map of page-local N (int) to ['title' => string, 'scorepct' => float,
     *         'weighted' => float]. Empty when nothing parses.
     */
    public static function parse_suspend_data(string $suspend): array {
        $peritem = [];
        if ($suspend === '') {
            return $peritem;
        }
        foreach (preg_split('~\.\t~', $suspend) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // The score/weight numbers accept a comma as the decimal separator so a
            // package localised to es_ES/fr_FR/de_DE ("60,5%") parses too; the
            // captured group is normalised to a dot before casting (mirrors the JS
            // parser in the view.php shim).
            if (
                preg_match(
                    '~^(\d+)\.\s"([^"]*)";\s[^:]+:\s([\d.,]+)%;\s[^:]+:\s([\d.,]+)%\.?$~',
                    $line,
                    $m
                )
            ) {
                $peritem[(int) $m[1]] = [
                    'title'    => $m[2],
                    // Clamp to 0..100: an out-of-range percentage (e.g. "150%") must
                    // not be persisted as a rawscore above maxscore.
                    'scorepct' => max(0.0, min(100.0, self::to_float($m[3]))),
                    'weighted' => self::to_float($m[4]),
                ];
            }
        }
        return $peritem;
    }

    /**
     * Casts a parsed numeric string to float, accepting a comma decimal separator.
     *
     * eXeLearning serialises the score/weight percentages with the producer's locale,
     * so a value can arrive as "60,5". Normalise the comma to a dot before casting.
     *
     * @param string $value Numeric string possibly using a comma decimal separator.
     * @return float
     */
    private static function to_float(string $value): float {
        return (float) str_replace(',', '.', $value);
    }

    /**
     * Recomputes the overall score (0..100) from the per-iDevice objectid scores.
     *
     * DEC-6-01 / RIE-007 residual: the producer's `cmi.core.score.raw`
     * (`getFinalScore`) is corrupt under a multi-page `cmi.suspend_data` collision,
     * but the per-iDevice scores the shim captures by stable objectid are not. This
     * derives the overall as the weighted mean of each item's `scorepct` by its
     * `weighted` (eXeLearning's per-iDevice weight); when every weight is zero it
     * falls back to a simple mean so a package without weights still aggregates. For
     * a single-page package (no collision) this equals the producer's overall, so the
     * verified single-page behaviour is preserved.
     *
     * @param array $itemscores Map objectid => ['scorepct' => float, 'weighted' => float, ...].
     * @return float|null Overall percentage in 0..100, or null when no item is usable.
     */
    public static function recompute_overall_pct(array $itemscores): ?float {
        $sumweighted = 0.0;
        $sumweight = 0.0;
        $sumscore = 0.0;
        $count = 0;
        foreach ($itemscores as $info) {
            if (!is_array($info) || !isset($info['scorepct'])) {
                continue;
            }
            $scorepct = max(0.0, min(100.0, (float) $info['scorepct']));
            $weight = (float) ($info['weighted'] ?? 0);
            $sumweighted += $scorepct * $weight;
            $sumweight += $weight;
            $sumscore += $scorepct;
            $count++;
        }
        if ($count === 0) {
            return null;
        }
        return ($sumweight > 0) ? ($sumweighted / $sumweight) : ($sumscore / $count);
    }

    /**
     * Routes per-iDevice scores to the gradebook by stable objectid (DEC-5-01).
     *
     * `$itemscores` is keyed by the iDevice objectid (the `.idevice_node` element id
     * the client shim reads from the iframe DOM, which equals `<odeIdeviceId>` in
     * content.xml and the `objectid` stored in `exelearning_grade_item`). Each value
     * carries at least `scorepct`. Routing by objectid is collision-free across
     * pages, unlike the page-local N the producer puts in cmi.suspend_data.
     *
     * For each objectid that resolves to a non-deleted grade item it records the
     * attempt and, unless the activity is in OVERALL grading mode, publishes the
     * aggregated grade to that itemnumber.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number from attempts::resolve_attempt_number().
     * @param array     $itemscores   Map objectid => ['scorepct' => float, ...].
     * @param string    $sessiontoken Page-load session token.
     * @return array<int, float> Map of itemnumber => final published grade.
     */
    public static function apply_item_scores(
        \stdClass $exe,
        int $userid,
        int $attempt,
        array $itemscores,
        string $sessiontoken
    ): array {
        global $DB;

        $persaved = [];
        if ($itemscores === []) {
            return $persaved;
        }
        $ctx = self::grade_context($exe);

        // Index the registered grade items by their stable objectid so an incoming
        // score can be routed to the right itemnumber regardless of page order.
        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exe->id, 'deleted' => 0],
            'itemnumber ASC',
            'id, itemnumber, name, objectid'
        );
        $byobjectid = [];
        foreach ($rows as $row) {
            $byobjectid[(string) $row->objectid] = $row;
        }

        foreach ($itemscores as $objectid => $info) {
            $objectid = (string) $objectid;
            // An objectid the package no longer exposes (or never had as a gradable
            // iDevice) has no column to receive the score: skip it silently.
            if (!isset($byobjectid[$objectid]) || !is_array($info)) {
                continue;
            }
            $row = $byobjectid[$objectid];
            $itemnumber = (int) $row->itemnumber;
            $scorepct = max(0.0, min(100.0, (float) ($info['scorepct'] ?? 0)));
            $persaved[$itemnumber] = self::apply_one(
                $exe,
                $ctx,
                $userid,
                $attempt,
                $itemnumber,
                $scorepct,
                (string) $row->name,
                $sessiontoken
            );
        }
        return $persaved;
    }

    /**
     * Legacy fallback: routes per-iDevice scores by the page-local index N parsed
     * from cmi.suspend_data, treating N directly as the itemnumber.
     *
     * Only correct for a single-page package whose iDevices are all gradable (see
     * RIE-007): when two gradable iDevices on different pages share the same
     * page-local N they collide here, so this is used only when the client shim
     * supplied no objectid map. Preserves the pre-DEC-5-01 behaviour exactly.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number from attempts::resolve_attempt_number().
     * @param array     $peritem      Map N => ['scorepct' => float, ...] from parse_suspend_data().
     * @param string    $sessiontoken Page-load session token.
     * @return array<int, float> Map of itemnumber => final published grade.
     */
    public static function apply_legacy_peritem(
        \stdClass $exe,
        int $userid,
        int $attempt,
        array $peritem,
        string $sessiontoken
    ): array {
        global $DB;

        $persaved = [];
        if ($peritem === []) {
            return $persaved;
        }
        $ctx = self::grade_context($exe);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exe->id, 'deleted' => 0],
            'itemnumber ASC',
            'itemnumber, name, objectid'
        );
        foreach ($peritem as $itemnumber => $info) {
            $itemnumber = (int) $itemnumber;
            if (!isset($rows[$itemnumber]) || !is_array($info)) {
                continue;
            }
            $scorepct = max(0.0, min(100.0, (float) ($info['scorepct'] ?? 0)));
            $persaved[$itemnumber] = self::apply_one(
                $exe,
                $ctx,
                $userid,
                $attempt,
                $itemnumber,
                $scorepct,
                (string) $rows[$itemnumber]->name,
                $sessiontoken
            );
        }
        return $persaved;
    }

    /**
     * Returns the stable objectids of the instance's registered (non-deleted)
     * gradable iDevices, used to reject scores for unknown objectids.
     *
     * @param \stdClass $exe The exelearning instance record.
     * @return string[] Registered objectids.
     */
    private static function registered_objectids(\stdClass $exe): array {
        global $DB;
        return $DB->get_fieldset_select(
            'exelearning_grade_item',
            'objectid',
            'exelearningid = ? AND deleted = 0',
            [$exe->id]
        );
    }

    /**
     * Resolves the per-instance grading context used by both routing paths.
     *
     * @param \stdClass $exe The exelearning instance record.
     * @return array Keys: grademax (float), grademethod (int), grademodel (int), itemdetailsbase (array).
     */
    private static function grade_context(\stdClass $exe): array {
        return [
            'grademax'    => (float) ($exe->grademax ?? 100),
            'grademethod' => (int) ($exe->grademethod ?? attempts::GRADE_HIGHEST),
            'grademodel'  => (int) ($exe->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM),
            'itemdetailsbase' => [
                'gradetype' => GRADE_TYPE_VALUE,
                'grademax'  => $exe->grademax ?? 100,
                'grademin'  => $exe->grademin ?? 0,
                'display'   => (int) ($exe->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
            ],
        ];
    }

    /**
     * Records one item's attempt and publishes its aggregated gradebook grade.
     *
     * @param \stdClass $exe          The exelearning instance record.
     * @param array     $ctx          Output of {@see self::grade_context()}.
     * @param int       $userid       The grading user.
     * @param int       $attempt      Attempt number.
     * @param int       $itemnumber   Grade item number (> 0).
     * @param float     $scorepct     Score as a 0..100 percentage.
     * @param string    $name         Gradebook column name.
     * @param string    $sessiontoken Page-load session token.
     * @return float The final published (aggregated) grade for the item.
     */
    private static function apply_one(
        \stdClass $exe,
        array $ctx,
        int $userid,
        int $attempt,
        int $itemnumber,
        float $scorepct,
        string $name,
        string $sessiontoken
    ): float {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $grademax = $ctx['grademax'];
        $rawitem = ($scorepct / 100.0) * $grademax;

        attempts::record_item(
            $exe->id,
            $userid,
            $attempt,
            $itemnumber,
            $rawitem,
            $grademax,
            'completed',
            $sessiontoken,
            // Unreachable with grading off — no objectid is registered, so nothing routes
            // here — but the flag is passed rather than hardcoded so the two write sites
            // cannot drift apart if that ever changes (DEC-124-03).
            !empty($exe->gradeenabled)
        );
        // Gradebook grade = aggregation of attempts according to grademethod.
        $scaled = attempts::aggregate_scaled($exe->id, $userid, $itemnumber, $ctx['grademethod']);
        $finalitem = ($scaled === null) ? $rawitem : ($scaled * $grademax);
        // In "overall only" mode per-iDevice columns are not published (DEC-0-08),
        // but the attempt IS recorded for the report.
        if ($ctx['grademodel'] !== EXELEARNING_GRADEMODEL_OVERALL) {
            grade_update(
                'mod/exelearning',
                $exe->course,
                'mod',
                'exelearning',
                $exe->id,
                $itemnumber,
                (object) ['userid' => $userid, 'rawgrade' => $finalitem],
                $ctx['itemdetailsbase'] + ['itemname' => $name]
            );
        }
        return $finalitem;
    }
}
