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
 *  - {@see self::parse_suspend_data()} decodes eXeLearning's `cmi.suspend_data`
 *    string — both the versioned `exe12/` payload written by the SCORM 1.2 runtime
 *    and the legacy unversioned lines. It is the single PHP source of truth for both
 *    formats and mirrors the JavaScript parser in the `view.php` SCORM shim.
 *  - {@see self::apply_item_scores()} routes per-iDevice scores to the gradebook by
 *    the stable `objectid` captured client-side (DEC-5-01 / RIE-007), instead of by
 *    the page-local index N the producer emits — which collides across pages.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class track {
    /** Header of the versioned cmi.suspend_data payload written by eXeLearning's SCORM 1.2 runtime. */
    private const EXE12_PREFIX = 'exe12/';

    /** Highest versioned-payload version this parser understands. */
    private const EXE12_MAX_VERSION = 1;

    /** Record separator inside the versioned payload. */
    private const EXE12_RECORD_SEPARATOR = '|';

    /** Field separator inside one versioned record. */
    private const EXE12_FIELD_SEPARATOR = ';';

    /** Bit 1 of a versioned record's flag field: the activity counts towards the score. */
    private const EXE12_FLAG_EVALUABLE = 1;

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

        // Master grading switch (DEC-13-07): with grading off the instance has no
        // grade items, so the objectid filter below empties itemscores, the
        // server-side recompute never runs, and ingest would fall through to trusting
        // the CLIENT's cmi.core.score.raw — writing attempt rows, gradebook updates
        // and lifecycle events for an activity its teacher configured as ungraded.
        // Mirror the xAPI channel (xapi\ingestor::ingest()): acknowledge, write
        // nothing.
        if (empty($exe->gradeenabled)) {
            return ['ok' => true, 'noop' => true];
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
        $itemscores = self::filter_registered_scores($exe, $itemscores);
        $suspend = $cmi['cmi.suspend_data'] ?? '';
        $peritem = is_string($suspend) ? self::parse_suspend_data($suspend) : [];
        if (is_string($suspend) && $suspend !== '' && $peritem === [] && $itemscores === []) {
            debugging(
                'mod_exelearning: cmi.suspend_data was non-empty but no per-iDevice '
                    . 'results could be parsed from it.',
                DEBUG_DEVELOPER
            );
        }
        // A versioned `exe12/` payload names the objectid inside every record, so the
        // server can route it by objectid exactly like a client-supplied map — no
        // page-local index, no DOM, no collision. This matters when the client sent no
        // map of its own: the mobile web service, or a shim that could not read the
        // package iframe.
        $fromsuspend = self::objectid_scores($peritem);
        if ($fromsuspend !== []) {
            if ($itemscores === []) {
                $itemscores = self::filter_registered_scores($exe, $fromsuspend);
            }
            // Those entries are keyed by objectid, never by itemnumber: the page-local
            // legacy fallback must never see them.
            $peritem = [];
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
            // The cap only ever applies to a graded activity, and DEC-126-01 makes that
            // true by construction: with grading off ingest() returned above, so no
            // attempt was created and none was charged against the cap.
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
            // Always gradable: reaching here means the activity is graded (DEC-126-01).
            // The flag itself stays because rows written by earlier versions, while the
            // activity was ungraded, are in the database marked 0 and must keep being
            // excluded from every aggregation.
            attempts::record_item(
                $exe->id,
                $userid,
                $attempt,
                0,
                $score,
                $grademax,
                $overallstatus,
                $sessiontoken,
                true
            );
            // A gradable row has just been written by record_item(), so the aggregation
            // has something to return. The null branch is left as a floor: taking $score
            // would publish the client's cmi.core.score.raw unverified, and a learner
            // whose only stored history is completion-only rows from an older version
            // must not be graded from it.
            $scaledoverall = attempts::aggregate_scaled($exe->id, $userid, 0, $grademethod);
            $hasgradablehistory = ($scaledoverall !== null);
            $finaloverall = $hasgradablehistory ? ($scaledoverall * $grademax) : $score;

            // Publish the aggregated overall grade ONLY in OVERALL mode (DEC-25-01): in
            // PERITEM the per-iDevice grades carry the gradebook.
            //
            // The master grading switch needs no check here: with it off ingest()
            // returned at the top (DEC-126-01), which is what stops grade_update() from
            // RECREATING the very column exelearning_sync_grade_items() deleted when the
            // teacher turned grading off.
            $result = GRADE_UPDATE_OK;
            if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL && $hasgradablehistory) {
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
     * Decodes an eXeLearning `cmi.suspend_data` string into per-iDevice results.
     *
     * Two producer formats reach this LMS and both are supported. The header selects
     * the parser; nothing downstream re-sniffs the payload:
     *
     *  - the VERSIONED payload `exe12/1|{record}|{record}…` written by eXeLearning's
     *    SCORM 1.2 runtime (core PR #2209 onwards, see
     *    `public/app/common/scorm/scorm12/exe-scorm12-activities.js`). Every record
     *    names its own activity id, so entries come back keyed by that stable
     *    objectid and route straight through {@see self::apply_item_scores()};
     *  - the LEGACY unversioned lines `{N}. "{title}"; {scoreLabel}: {S}%;
     *    {weightLabel}: {W}%` joined by ".\t", written by every earlier release. N is
     *    the page-local DOM index of the iDevice (NOT our itemnumber, and it collides
     *    across pages; see DEC-5-01), so entries come back keyed by N and only the
     *    client shim — which can see the loaded page — can resolve them safely.
     *
     * One representation serves both: each value carries `title`, `scorepct` and
     * `weighted`, plus an `objectid` key on — and only on — an entry that knows its
     * own identity. Callers branch on that key, never on the raw string. This mirrors
     * the JavaScript parser in `js/scorm_tracker.js`.
     *
     * @param string $suspend Raw cmi.suspend_data value.
     * @return array Map of objectid (versioned) or page-local N (legacy) to
     *         ['title' => string, 'scorepct' => float, 'weighted' => float,
     *         'objectid' => string (versioned only)]. Empty when nothing parses.
     */
    public static function parse_suspend_data(string $suspend): array {
        if ($suspend === '') {
            return [];
        }
        if (strpos($suspend, self::EXE12_PREFIX) === 0) {
            return self::parse_exe12_payload($suspend);
        }
        return self::parse_legacy_suspend_data($suspend);
    }

    /**
     * Parses the versioned `exe12/{version}` payload.
     *
     * Version handling is deliberately strict: an unreadable version tag, or one
     * newer than {@see self::EXE12_MAX_VERSION}, yields an EMPTY map instead of a
     * best-effort parse. A future revision may reorder or repurpose fields, and a
     * silently misparsed field would publish a wrong grade — worse than publishing
     * none, which merely leaves the item ungraded and visibly missing.
     *
     * @param string $suspend Raw value, already known to start with the header.
     * @return array Map of objectid to the parsed entry. Empty when nothing parses.
     */
    private static function parse_exe12_payload(string $suspend): array {
        $peritem = [];
        $body = substr($suspend, strlen(self::EXE12_PREFIX));
        $separator = strpos($body, self::EXE12_RECORD_SEPARATOR);
        $versiontext = ($separator === false) ? $body : substr($body, 0, $separator);
        if (!is_numeric($versiontext)) {
            debugging(
                'mod_exelearning: ignored a cmi.suspend_data payload with an unreadable version tag.',
                DEBUG_DEVELOPER
            );
            return $peritem;
        }
        $version = (float) $versiontext;
        if ($version < 1 || $version > self::EXE12_MAX_VERSION) {
            debugging(
                'mod_exelearning: ignored a cmi.suspend_data payload written by an unsupported runtime '
                    . '(version ' . $versiontext . '); no per-iDevice score was read from it.',
                DEBUG_DEVELOPER
            );
            return $peritem;
        }
        if ($separator === false) {
            // Header only: a session that has registered nothing yet.
            return $peritem;
        }
        $records = explode(self::EXE12_RECORD_SEPARATOR, substr($body, $separator + 1));
        foreach ($records as $record) {
            $entry = self::decode_exe12_record($record);
            if ($entry !== null) {
                $peritem[$entry['objectid']] = $entry;
            }
        }
        return $peritem;
    }

    /**
     * Decodes one record of the versioned payload.
     *
     * Layout (see `encodeRecord()` in exe-scorm12-activities.js), fields separated
     * by ';':
     *   [0] rawurlencoded activity id — the `.idevice_node` id, i.e. our objectid
     *   [1] flags bitmask (1 evaluable, 2 completionRequired, 4 completed)
     *   [2] answered  [3] total  [4] score  [5] weight  [6] minimumScore  [7] maximumScore
     *
     * The score is field [4] and is NEVER derived from answered/total: a real record
     * reads `ide-a;7;0;4;100;25;0;100` — answered 0 of 4, score 100 — because an
     * iDevice may report a score without reporting question counters. It is scaled
     * into 0..100 with the record's own min/max window.
     *
     * Skipped, each on purpose:
     *  - three-field records: migrated-but-unclaimed legacy entries
     *    (`position;score;weight`) riding along in the payload. They name a page
     *    position instead of an iDevice, and "unclaimed" means no live iDevice owns
     *    them, so attributing them to whatever sits at that position would invent a
     *    grade;
     *  - records with no evaluable flag: the producer excludes them from
     *    cmi.core.score.raw, so they must not reach a gradebook column either;
     *  - records whose score field is empty: the activity has not produced a result
     *    yet, which is not the same as scoring 0.
     *
     * @param string $record One encoded record.
     * @return array|null ['title' => '', 'scorepct' => float, 'weighted' => float,
     *         'objectid' => string], or null when the record is unusable.
     */
    private static function decode_exe12_record(string $record): ?array {
        $fields = explode(self::EXE12_FIELD_SEPARATOR, $record);
        if (count($fields) < 6) {
            return null;
        }
        // PHP's rawurldecode() never fails, but the JS parser drops a record whose id
        // has a malformed escape; reject the same ones so both sides agree.
        if (preg_match('~%(?![0-9A-Fa-f]{2})~', $fields[0])) {
            return null;
        }
        $objectid = rawurldecode($fields[0]);
        if ($objectid === '') {
            return null;
        }
        if (!is_numeric($fields[1])) {
            return null;
        }
        $flags = (int) $fields[1];
        if (($flags & self::EXE12_FLAG_EVALUABLE) === 0) {
            return null;
        }
        if (!is_numeric($fields[4])) {
            return null;
        }
        $score = (float) $fields[4];
        $weight = is_numeric($fields[5]) ? (float) $fields[5] : 1.0;
        if ($weight <= 0) {
            $weight = 1.0;
        }
        $minimum = (isset($fields[6]) && is_numeric($fields[6])) ? (float) $fields[6] : 0.0;
        $maximum = (isset($fields[7]) && is_numeric($fields[7])) ? (float) $fields[7] : 100.0;
        // A degenerate range cannot normalise a score; fall back to a 100-wide
        // window, exactly like the producer's normalize().
        if ($maximum <= $minimum) {
            $maximum = $minimum + 100.0;
        }
        return [
            // The versioned format drops titles (they are the largest field in a
            // 4096-character element and aggregation never needed them).
            'title'    => '',
            'scorepct' => max(0.0, min(100.0, (($score - $minimum) / ($maximum - $minimum)) * 100.0)),
            'weighted' => $weight,
            'objectid' => $objectid,
        ];
    }

    /**
     * Parses the legacy (unversioned) `cmi.suspend_data` lines.
     *
     * The producer (`public/app/common/common.js`) serialises one entry per scored
     * iDevice as `{N}. "{title}"; {scoreLabel}: {S}%; {weightLabel}: {W}%`, joined by
     * ".\t". N is the page-local DOM index of the iDevice (NOT our itemnumber); see
     * DEC-5-01. The score/weight labels are localised, hence the `[^:]+` parts.
     *
     * @param string $suspend Raw cmi.suspend_data value.
     * @return array Map of page-local N (int) to ['title' => string,
     *         'scorepct' => float, 'weighted' => float]. Empty when nothing parses.
     */
    private static function parse_legacy_suspend_data(string $suspend): array {
        $peritem = [];
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
     * Extracts the entries of a {@see self::parse_suspend_data()} result that carry
     * their own stable objectid, re-keyed by it and ready for
     * {@see self::apply_item_scores()}.
     *
     * Only the versioned `exe12/` format produces such entries; a legacy payload
     * yields an empty array, which is what keeps it on the page-local fallback path.
     *
     * @param array $peritem Result of parse_suspend_data().
     * @return array Map of objectid => entry. Empty for a legacy payload.
     */
    public static function objectid_scores(array $peritem): array {
        $scores = [];
        foreach ($peritem as $info) {
            if (is_array($info) && isset($info['objectid']) && $info['objectid'] !== '') {
                $scores[(string) $info['objectid']] = $info;
            }
        }
        return $scores;
    }

    /**
     * Keeps only the scores whose objectid belongs to a registered, non-deleted
     * gradable iDevice of this instance.
     *
     * apply_item_scores() already ignores unknown objectids, but the overall recompute
     * operates on the whole map, so an unfiltered map would let a caller skew the
     * overall by injecting extra objectids. Extracted because the versioned `exe12/`
     * path needs the same filter for the scores it recovers from cmi.suspend_data.
     *
     * @param \stdClass $exe        The exelearning instance record.
     * @param array     $itemscores Map objectid => entry.
     * @return array The same map without the unregistered objectids.
     */
    private static function filter_registered_scores(\stdClass $exe, array $itemscores): array {
        if ($itemscores === []) {
            return [];
        }
        $registered = array_flip(array_map('strval', self::registered_objectids($exe)));
        return array_filter(
            $itemscores,
            fn($key) => isset($registered[(string) $key]),
            ARRAY_FILTER_USE_KEY
        );
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
            // A versioned `exe12/` entry names its own objectid and is keyed by it,
            // not by an itemnumber: it belongs to apply_item_scores(). ingest() never
            // sends one here; this is the guard for any other caller.
            if (is_array($info) && !empty($info['objectid'])) {
                continue;
            }
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
            // Always gradable: apply_one() only runs for a registered objectid, and an
            // ungraded activity has none — it returned at the top of ingest()
            // (DEC-126-01).
            true
        );
        // Gradebook grade = aggregation of attempts according to grademethod, over
        // GRADABLE rows only. A null means this learner has no gradable history for this
        // iDevice — only completion-only rows written by an older version — and the
        // fallback to $rawitem is the score the CLIENT sent, so taking it would publish
        // an unverified browser value. Publish nothing instead. Mirrors the same rule on
        // the overall in ingest().
        $scaled = attempts::aggregate_scaled($exe->id, $userid, $itemnumber, $ctx['grademethod']);
        $finalitem = ($scaled === null) ? $rawitem : ($scaled * $grademax);
        // In "overall only" mode per-iDevice columns are not published (DEC-0-08),
        // but the attempt IS recorded for the report.
        if ($ctx['grademodel'] !== EXELEARNING_GRADEMODEL_OVERALL && $scaled !== null) {
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
