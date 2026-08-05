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

namespace mod_exelearning\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use context_module;
use mod_exelearning\local\track;

/**
 * External function: save a tracking submission for the current user (mobile app).
 *
 * The web-service counterpart of track.php. It hands the submission to the shared,
 * unit-tested {@see track::ingest()} so the server-side safeguards are identical:
 * per-iDevice scores are routed by stable objectid (an objectid the package does not
 * expose is ignored), the overall is recomputed server-side from those scores
 * (never trusting the client), scores are clamped to the grade range and the attempt
 * cap is enforced. Always writes for the authenticated user — a client cannot grade
 * another user.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_track extends external_api {
    /**
     * Parameters for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'exelearningid' => new external_value(PARAM_INT, 'exelearning instance id'),
            'track' => new external_single_structure([
                'session'  => new external_value(PARAM_ALPHANUMEXT, 'Page-load session token', VALUE_DEFAULT, ''),
                // Nullable on purpose (B6, DEC-34-01): omitting scoreraw means a
                // status-only / empty commit, which must NOT be recorded as a real
                // 0-score attempt. A default of 0 silently turned every score-less
                // commit into a genuine 0 that dragged GRADE_LAST/AVERAGE down and
                // burnt a maxattempt slot — the web track.php path correctly no-ops
                // the same payload.
                'scoreraw' => new external_value(
                    PARAM_FLOAT,
                    'Overall raw score (omit for a status-only commit)',
                    VALUE_DEFAULT,
                    null,
                    NULL_ALLOWED
                ),
                'scoremax' => new external_value(PARAM_FLOAT, 'Overall maximum score', VALUE_DEFAULT, 100),
                'status'   => new external_value(PARAM_ALPHA, 'Lesson status (completed|passed|failed)', VALUE_DEFAULT, ''),
                'itemscores' => new external_multiple_structure(
                    new external_single_structure([
                        'objectid' => new external_value(PARAM_NOTAGS, 'Stable iDevice objectid (<odeIdeviceId>)'),
                        'scorepct' => new external_value(PARAM_FLOAT, 'Score as a 0..100 percentage'),
                        'weighted' => new external_value(PARAM_FLOAT, 'iDevice weight', VALUE_DEFAULT, 0),
                    ]),
                    'Per-iDevice scores routed by objectid',
                    VALUE_DEFAULT,
                    []
                ),
            ]),
        ]);
    }

    /**
     * Ingest a tracking submission for the current user.
     *
     * @param int $exelearningid The exelearning instance id.
     * @param array $track The tracking payload (session, overall score, per-iDevice scores).
     * @return array status + attempt + score + warnings.
     */
    public static function execute(int $exelearningid, array $track): array {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'exelearningid' => $exelearningid,
            'track'         => $track,
        ]);

        $exelearning = $DB->get_record('exelearning', ['id' => $params['exelearningid']], '*', MUST_EXIST);
        // Resolve a plain stdClass cm (not the cm_info get_course_and_cm_from_instance
        // returns), because track::ingest() type-hints stdClass.
        $cm = get_coursemodule_from_instance('exelearning', $exelearning->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/exelearning:savetrack', $context);

        // Re-shape the typed params into the payload track::ingest() expects.
        $t = $params['track'];
        $itemscores = [];
        foreach ($t['itemscores'] as $is) {
            // Last value wins for a duplicated objectid (ingest also ignores any
            // objectid the package does not expose as a gradable iDevice).
            $itemscores[(string) $is['objectid']] = [
                'scorepct' => (float) $is['scorepct'],
                'weighted' => (float) $is['weighted'],
                'title'    => '',
            ];
        }
        // Only a real score submission carries cmi.core.score.raw. When scoreraw is
        // omitted (null) the commit is status-only and must hit track::ingest()'s
        // no-op guard instead of being persisted as a spurious 0-score attempt
        // (B6, DEC-34-01).
        $cmi = ['cmi.core.lesson_status' => $t['status']];
        if ($t['scoreraw'] !== null) {
            $cmi['cmi.core.score.raw'] = $t['scoreraw'];
            $cmi['cmi.core.score.max'] = $t['scoremax'];
        }
        $payload = [
            'session' => $t['session'],
            'cmi' => $cmi,
            'itemscores' => $itemscores,
        ];

        // Preview is always off here: a web-service caller never grades in preview.
        $result = track::ingest($exelearning, $course, $cm, $USER->id, $payload, false);

        $warnings = [];
        if (!empty($result['error'])) {
            $warnings[] = [
                'item'        => 'exelearning',
                'itemid'      => (int) $exelearning->id,
                'warningcode' => (string) $result['error'],
                'message'     => get_string('error_' . $result['error'], 'mod_exelearning'),
            ];
        }

        return [
            'status'  => !empty($result['ok']),
            'attempt' => (int) ($result['attempt'] ?? 0),
            'score'   => (float) ($result['rawscore'] ?? 0),
            'warnings' => $warnings,
        ];
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_BOOL, 'Whether the submission was graded'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number written (0 when rejected)'),
            'score'   => new external_value(PARAM_FLOAT, 'Server-side overall grade after aggregation'),
            'warnings' => new external_warnings(),
        ]);
    }
}
