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

namespace mod_exelearning;

use advanced_testcase;
use mod_exelearning\local\track;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the SCORM tracking helper (per-iDevice grade routing).
 *
 * Covers RIE-007 / DEC-5-01: routing scores to the right gradebook column by stable
 * objectid instead of by the page-local index N that collides across pages.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\track
 */
final class track_test extends advanced_testcase {
    /**
     * Helper: course + exelearning instance + enrolled student.
     *
     * @param array $record extra generator fields (e.g. packagefilepath, grademodel)
     * @return array{0: \stdClass, 1: \stdClass} [instance, student]
     */
    protected function create_activity_with_student(array $record = []): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(array_merge(['course' => $course->id], $record));

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        return [$instance, $student];
    }

    /**
     * Returns the objectid registered for a given itemnumber of an instance.
     *
     * @param \stdClass $instance
     * @param int $itemnumber
     * @return string
     */
    protected function objectid_for(\stdClass $instance, int $itemnumber): string {
        global $DB;
        return (string) $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => $itemnumber,
            'deleted'       => 0,
        ], MUST_EXIST);
    }

    /**
     * Returns the published gradebook grade for a user on a given itemnumber.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param int $itemnumber
     * @return float|null
     */
    protected function published_grade(\stdClass $instance, int $userid, int $itemnumber): ?float {
        $grades = grade_get_grades(
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            $userid
        );
        if (!isset($grades->items[$itemnumber]->grades[$userid])) {
            return null;
        }
        $grade = $grades->items[$itemnumber]->grades[$userid]->grade;
        return ($grade === null) ? null : (float) $grade;
    }

    /**
     * parse_suspend_data() decodes the producer's format, is locale-agnostic on the
     * score/weight labels, tolerates a trailing period and clamps out-of-range %.
     */
    public function test_parse_suspend_data_matches_js_format(): void {
        $suspend = '1. "Quiz one"; Puntuación: 80%; Peso: 100%' . ".\t"
                . '2. "Quiz two"; Puntuación: 60.5%; Peso: 50%' . ".\t"
                . '3. "Over"; Score: 150%; Weight: 100%.';

        $parsed = track::parse_suspend_data($suspend);

        $this->assertSame([1, 2, 3], array_keys($parsed));
        $this->assertSame('Quiz one', $parsed[1]['title']);
        $this->assertEqualsWithDelta(80.0, $parsed[1]['scorepct'], 0.0001);
        $this->assertEqualsWithDelta(100.0, $parsed[1]['weighted'], 0.0001);
        $this->assertEqualsWithDelta(60.5, $parsed[2]['scorepct'], 0.0001);
        // Out-of-range percentages are clamped to 100.
        $this->assertEqualsWithDelta(100.0, $parsed[3]['scorepct'], 0.0001);

        // Empty / unparsable input yields an empty map (no warnings, no entries).
        $this->assertSame([], track::parse_suspend_data(''));
        $this->assertSame([], track::parse_suspend_data('not a valid line'));
    }

    /**
     * apply_item_scores() routes each score to the itemnumber that owns its objectid.
     */
    public function test_objectid_routing_routes_to_correct_itemnumber(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();

        $obj1 = $this->objectid_for($instance, 1);
        $obj2 = $this->objectid_for($instance, 2);

        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sess1');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            $obj1 => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
            $obj2 => ['scorepct' => 40.0, 'weighted' => 100.0, 'title' => 'b'],
        ], 'sess1');

        // Returned map and the stored attempts land on the right itemnumbers.
        $this->assertEqualsWithDelta(80.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(40.0, $saved[2], 0.0001);

        $a1 = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 1,
        ], '*', MUST_EXIST);
        $a2 = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 2,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(0.8, (float) $a1->scaledscore, 0.0001);
        $this->assertEqualsWithDelta(0.4, (float) $a2->scaledscore, 0.0001);

        // And the published gradebook columns match.
        $this->assertEqualsWithDelta(80.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * The headline RIE-007 case: two gradable iDevices on different pages that share
     * the same page-local index N. objectid routing keeps them distinct; the legacy
     * N-routing collides (both look like itemnumber=2) and loses item 1.
     */
    public function test_collision_same_pagelocal_n_different_pages(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );

        // Both iDevices live at page-local DOM index 2 on their own page.
        $this->assertSame('idevice-tf-0001', $this->objectid_for($instance, 1));
        $this->assertSame('idevice-guess-0002', $this->objectid_for($instance, 2));

        // Objectid routing: each score reaches its own column.
        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessOK');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            'idevice-tf-0001'    => ['scorepct' => 90.0, 'weighted' => 100.0, 'title' => 'tf'],
            'idevice-guess-0002' => ['scorepct' => 30.0, 'weighted' => 100.0, 'title' => 'guess'],
        ], 'sessOK');
        $this->assertEqualsWithDelta(90.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(30.0, $saved[2], 0.0001);
        $this->assertEqualsWithDelta(90.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->published_grade($instance, $student->id, 2), 0.0001);

        // Contrast: the legacy path sees only N=2 (the collided survivor in
        // suspend_data), so item 1 never receives a grade. This is the bug the
        // objectid map fixes — asserted here so a regression is caught.
        [$instance2, $student2] = $this->create_activity_with_student(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );
        $attempt2 = local\attempts::resolve_attempt_number($instance2->id, $student2->id, 'sessLegacy');
        $savedlegacy = track::apply_legacy_peritem($instance2, $student2->id, $attempt2, [
            2 => ['scorepct' => 30.0, 'weighted' => 100.0, 'title' => 'guess'],
        ], 'sessLegacy');
        $this->assertArrayNotHasKey(1, $savedlegacy, 'legacy N-routing cannot reach item 1 under collision');
        $this->assertArrayHasKey(2, $savedlegacy);
        $this->assertNull(
            $this->published_grade($instance2, $student2->id, 1),
            'legacy routing leaves item 1 ungraded (the RIE-007 data loss)'
        );
    }

    /**
     * Backward compatibility: for a single-page package whose iDevices are all
     * gradable, the legacy N-routing fallback still lands each score correctly.
     */
    public function test_legacy_suspenddata_fallback_unchanged(): void {
        [$instance, $student] = $this->create_activity_with_student();

        // The default fixture is single-page with two gradable iDevices, so N==itemnumber.
        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessL');
        $saved = track::apply_legacy_peritem($instance, $student->id, $attempt, [
            1 => ['scorepct' => 70.0, 'weighted' => 100.0, 'title' => 'a'],
            2 => ['scorepct' => 50.0, 'weighted' => 100.0, 'title' => 'b'],
        ], 'sessL');

        $this->assertEqualsWithDelta(70.0, $saved[1], 0.0001);
        $this->assertEqualsWithDelta(50.0, $saved[2], 0.0001);
        $this->assertEqualsWithDelta(70.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * An objectid not registered as a gradable iDevice is ignored (no fatal, no row).
     */
    public function test_unknown_objectid_is_ignored(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();

        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessU');
        $saved = track::apply_item_scores($instance, $student->id, $attempt, [
            'no-such-objectid' => ['scorepct' => 99.0, 'weighted' => 100.0, 'title' => 'x'],
        ], 'sessU');

        $this->assertSame([], $saved);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
    }

    /**
     * recompute_overall_pct() returns the weight-weighted mean of scorepct, falls back
     * to a simple mean when all weights are zero, clamps out-of-range scorepct, skips
     * malformed entries and returns null when nothing is usable (DEC-6-01).
     */
    public function test_recompute_overall_pct(): void {
        // Weighted mean: (80*100 + 40*300) / 400 = 50.
        $this->assertEqualsWithDelta(50.0, track::recompute_overall_pct([
            'a' => ['scorepct' => 80.0, 'weighted' => 100.0],
            'b' => ['scorepct' => 40.0, 'weighted' => 300.0],
        ]), 0.0001);

        // All weights zero -> simple mean: (80 + 40) / 2 = 60.
        $this->assertEqualsWithDelta(60.0, track::recompute_overall_pct([
            'a' => ['scorepct' => 80.0, 'weighted' => 0.0],
            'b' => ['scorepct' => 40.0, 'weighted' => 0.0],
        ]), 0.0001);

        // Out-of-range scorepct is clamped to 0..100 before averaging.
        $this->assertEqualsWithDelta(100.0, track::recompute_overall_pct([
            'a' => ['scorepct' => 150.0, 'weighted' => 100.0],
        ]), 0.0001);

        // Malformed entries (non-array, missing scorepct) are skipped.
        $this->assertEqualsWithDelta(70.0, track::recompute_overall_pct([
            'a' => ['scorepct' => 70.0, 'weighted' => 100.0],
            'b' => 'not-an-array',
            'c' => ['weighted' => 100.0],
        ]), 0.0001);

        // Nothing usable -> null.
        $this->assertNull(track::recompute_overall_pct([]));
        $this->assertNull(track::recompute_overall_pct(['a' => 'x', 'b' => ['weighted' => 1.0]]));
    }

    /**
     * The overall recompute fixes the RIE-007 residual: two iDevices on different
     * pages share page-local N, so the producer's collided getFinalScore is wrong,
     * but recompute_overall_pct() derives the correct overall from the objectid map.
     */
    public function test_overall_recompute_from_collided_itemscores(): void {
        // Producer would emit a single (collided) cmi.core.score.raw, but the two
        // per-iDevice scores recovered by objectid average to the correct overall.
        $itemscores = [
            'idevice-tf-0001'    => ['scorepct' => 90.0, 'weighted' => 100.0, 'title' => 'tf'],
            'idevice-guess-0002' => ['scorepct' => 30.0, 'weighted' => 100.0, 'title' => 'guess'],
        ];
        // Equal weights -> mean of 90 and 30 = 60, regardless of the corrupt CMI value.
        $this->assertEqualsWithDelta(60.0, track::recompute_overall_pct($itemscores), 0.0001);
    }

    /**
     * parse_suspend_data() accepts a comma decimal separator (es_ES/fr_FR/de_DE),
     * keeping parity with the JS parser in the view.php shim.
     */
    public function test_parse_suspend_data_accepts_comma_decimals(): void {
        $suspend = '1. "Quiz"; Puntuación: 60,5%; Peso: 12,5%.';
        $parsed = track::parse_suspend_data($suspend);

        $this->assertArrayHasKey(1, $parsed);
        $this->assertEqualsWithDelta(60.5, $parsed[1]['scorepct'], 0.0001);
        $this->assertEqualsWithDelta(12.5, $parsed[1]['weighted'], 0.0001);
    }

    /**
     * parse_suspend_data() also decodes the VERSIONED `exe12/` payload written by
     * eXeLearning's SCORM 1.2 runtime (core PR #2209). Its records carry the activity
     * id, so the result is keyed by stable objectid instead of by a page-local index,
     * and the score is read from its own field — never derived from the counters.
     */
    public function test_parse_suspend_data_reads_the_versioned_exe12_payload(): void {
        // Captured verbatim from a package built by the new runtime: answered 0 of
        // total 4, yet the score is 100.
        $parsed = track::parse_suspend_data('exe12/1|ide-a;7;0;4;100;25;0;100');

        $this->assertSame(['ide-a'], array_keys($parsed));
        $this->assertSame('ide-a', $parsed['ide-a']['objectid']);
        $this->assertEqualsWithDelta(100.0, $parsed['ide-a']['scorepct'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $parsed['ide-a']['weighted'], 0.0001);
        // The versioned format drops titles.
        $this->assertSame('', $parsed['ide-a']['title']);

        // Several records, each scaled with its own min/max window: 5 in 0..10 = 50%.
        $parsed = track::parse_suspend_data('exe12/1|a;1;0;0;5;75;0;10|b;1;0;0;30;1;0;0');
        $this->assertSame(['a', 'b'], array_keys($parsed));
        $this->assertEqualsWithDelta(50.0, $parsed['a']['scorepct'], 0.0001);
        $this->assertEqualsWithDelta(75.0, $parsed['a']['weighted'], 0.0001);
        // A degenerate range (max <= min) falls back to a 100-wide window.
        $this->assertEqualsWithDelta(30.0, $parsed['b']['scorepct'], 0.0001);

        // Out-of-window scores are clamped, and the id is percent-decoded.
        $this->assertEqualsWithDelta(
            100.0,
            track::parse_suspend_data('exe12/1|q;1;0;0;250;1;0;100')['q']['scorepct'],
            0.0001
        );
        $this->assertSame(
            ['ide a|b'],
            array_keys(track::parse_suspend_data('exe12/1|ide%20a%7Cb;1;0;0;10;1;0;100'))
        );

        // A header with no records at all is valid: nothing has registered yet.
        $this->assertSame([], track::parse_suspend_data('exe12/1'));
    }

    /**
     * The versioned records that must never reach a gradebook column are dropped:
     * an unclaimed migrated legacy pool record (which names a page position, not an
     * iDevice), a non-evaluable activity, and one whose score field is still empty.
     */
    public function test_parse_suspend_data_skips_unusable_exe12_records(): void {
        $parsed = track::parse_suspend_data(
            'exe12/1|ok;1;0;0;40;1;0;100'          // Evaluable and scored: kept.
                . '|noscore;1;0;0;;1;0;100'        // Evaluable but no result yet.
                . '|notevaluable;6;0;0;80;1;0;100' // Flagged completionRequired + completed only.
                . '|3;40;1'                        // Unclaimed migrated legacy record.
                . '|;1;0;0;50;1;0;100'             // Empty id.
                . '|short;1;0;0'                   // Truncated record.
                . '|bad;x;0;0;50;1;0;100'          // Unreadable flags.
                . '|ide%zz;1;0;0;50;1;0;100'       // Malformed percent escape in the id.
        );

        $this->assertSame(['ok'], array_keys($parsed));
        $this->assertEqualsWithDelta(40.0, $parsed['ok']['scorepct'], 0.0001);
    }

    /**
     * A payload version this parser does not know yields NOTHING rather than a
     * best-effort parse: a future revision may reorder or repurpose fields, and
     * publishing a wrong grade is worse than publishing none.
     */
    public function test_parse_suspend_data_ignores_an_unsupported_exe12_version(): void {
        $this->assertSame([], track::parse_suspend_data('exe12/2|ide-a;7;0;4;100;25;0;100'));
        $this->assertDebuggingCalled();

        $this->assertSame([], track::parse_suspend_data('exe12/x|ide-a;7;0;4;100;25;0;100'));
        $this->assertDebuggingCalled();

        $this->assertSame([], track::parse_suspend_data('exe12/|ide-a;7;0;4;100;25;0;100'));
        $this->assertDebuggingCalled();
    }

    /**
     * objectid_scores() lifts out the entries that know their own identity. Only the
     * versioned format produces them; a legacy payload yields an empty array, which
     * is what keeps it on the page-local fallback path.
     */
    public function test_objectid_scores_only_returns_self_identifying_entries(): void {
        $versioned = track::parse_suspend_data('exe12/1|ide-a;7;0;4;100;25;0;100');
        $this->assertSame(['ide-a'], array_keys(track::objectid_scores($versioned)));

        $legacy = track::parse_suspend_data('1. "Quiz"; Puntuación: 80%; Peso: 100%');
        $this->assertSame([], track::objectid_scores($legacy));
        $this->assertSame([], track::objectid_scores([]));
    }

    /**
     * apply_legacy_peritem() treats its keys as itemnumbers, so it must refuse an
     * entry that carries an objectid: casting "ide-a" to int would route a real score
     * to itemnumber 0. ingest() never sends one here; this is the guard for any other
     * caller.
     */
    public function test_apply_legacy_peritem_refuses_objectid_entries(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();

        $attempt = local\attempts::resolve_attempt_number($instance->id, $student->id, 'sessV');
        $saved = track::apply_legacy_peritem($instance, $student->id, $attempt, [
            'ide-a' => ['scorepct' => 70.0, 'weighted' => 100.0, 'title' => '', 'objectid' => 'ide-a'],
        ], 'sessV');

        $this->assertSame([], $saved);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
    }

    /**
     * End to end: a client that sends no objectid map but whose package writes the
     * versioned payload is still graded per iDevice, because every record names its
     * own objectid. The overall is recomputed from those records (DEC-6-01), so the
     * client's cmi.core.score.raw is not trusted here either.
     */
    public function test_ingest_routes_a_versioned_suspend_payload_by_objectid(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);
        $obj2 = $this->objectid_for($instance, 2);

        $payload = [
            'session' => 'sessExe12',
            'cmi' => [
                'cmi.core.score.raw' => '0',
                'cmi.core.score.max' => '100',
                'cmi.core.lesson_status' => 'completed',
                'cmi.suspend_data' => 'exe12/1|' . rawurlencode($obj1) . ';7;0;4;80;100;0;100'
                    . '|' . rawurlencode($obj2) . ';7;0;4;40;100;0;100',
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        // The recomputed overall (60) diverges from the client's 0, which is logged.
        $this->assertDebuggingCalled();
        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(80.0, $result['peritem'][1], 0.0001);
        $this->assertEqualsWithDelta(40.0, $result['peritem'][2], 0.0001);
        $this->assertEqualsWithDelta(60.0, $result['rawscore'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * Loads the course and cm records for an instance (ingest() needs both for the
     * completion update).
     *
     * @param \stdClass $instance
     * @return array{0: \stdClass, 1: \stdClass} [course, cm]
     */
    protected function course_and_cm(\stdClass $instance): array {
        global $DB;
        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        return [$course, $cm];
    }

    /**
     * ingest() is the shared orchestration used by both the web track.php endpoint
     * and the save_track web service: it records the attempt, routes per-iDevice
     * scores by objectid and recomputes the overall server-side.
     */
    public function test_ingest_peritem_records_attempt_and_publishes_grades(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);
        $obj2 = $this->objectid_for($instance, 2);

        $payload = [
            'session' => 'sessIngest',
            'cmi' => [
                'cmi.core.score.raw' => '0',
                'cmi.core.score.max' => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => [
                $obj1 => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
                $obj2 => ['scorepct' => 40.0, 'weighted' => 100.0, 'title' => 'b'],
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        // The server-side overall (60) diverges from the client's cmi.core.score.raw
        // of 0, so the divergence is logged (DEC-6-01) — proving the client overall
        // is never trusted.
        $this->assertDebuggingCalled();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['attempt']);
        $this->assertEqualsWithDelta(80.0, $result['peritem'][1], 0.0001);
        $this->assertEqualsWithDelta(40.0, $result['peritem'][2], 0.0001);
        // Overall recomputed server-side from the item scores (mean of 80 and 40),
        // not taken from the client cmi.core.score.raw of 0.
        $this->assertEqualsWithDelta(60.0, $result['rawscore'], 0.0001);
        // Published per-iDevice gradebook columns match.
        $this->assertEqualsWithDelta(80.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * With the master grading switch off (DEC-13-07), ingest() records NOTHING
     * (DEC-126-01).
     *
     * Not a row marked ungraded, not a grade, not an event. The activity is a plain
     * resource while the switch is off, and a plain resource has no tracking table to
     * write to. This is what makes "work done while ungraded never becomes a grade"
     * (DEC-124-03) true by construction rather than by a mechanism that has to hold.
     *
     * @param int $grademodel The grade model to exercise.
     * @dataProvider grading_disabled_models_provider
     */
    public function test_ingest_with_grading_disabled_records_nothing(int $grademodel): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student([
            'gradeenabled' => 0,
            'grademodel'   => $grademodel,
        ]);
        [$course, $cm] = $this->course_and_cm($instance);

        $result = track::ingest($instance, $course, $cm, $student->id, [
            'session'    => 'sessOff',
            'cmi'        => [
                // A corruptible client-side value: nothing may trust it.
                'cmi.core.score.raw'     => '95',
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'passed',
            ],
            'itemscores' => [
                'ide-a' => ['scorepct' => 95.0, 'weighted' => 100.0, 'title' => 'a'],
            ],
        ], false);

        // Acknowledged, so the client does not retry, but nothing was done.
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['noop']);
        $this->assertArrayNotHasKey('rawscore', $result);

        // No attempt row at all. Note this asserts more than "the grade is null":
        // grade_update() would RECREATE a deleted grade item, so the item itself must
        // not come back either.
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id,
            'userid'        => $student->id,
        ]));
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertSame([], $grades->items);
    }

    /**
     * Turning grading on starts recording from that moment; nothing is recovered from
     * before it (DEC-126-01).
     *
     * @param int $grademodel The grade model to exercise.
     * @dataProvider grading_disabled_models_provider
     */
    public function test_switching_grading_on_starts_recording_from_that_moment(int $grademodel): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student([
            'gradeenabled' => 1,
            'grademodel'   => $grademodel,
        ]);
        [$course, $cm] = $this->course_and_cm($instance);
        $objectid = $this->objectid_for($instance, 1);

        // The activity becomes a plain resource before the learner starts.
        $DB->set_field('exelearning', 'gradeenabled', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);

        $payload = fn(string $session, float $pct) => [
            'session'    => $session,
            'cmi'        => [
                'cmi.core.score.raw'     => (string) $pct,
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => [
                $objectid => ['scorepct' => $pct, 'weighted' => 100.0, 'title' => 'A'],
            ],
        ];

        // While off: nothing.
        track::ingest($instance, $course, $cm, $student->id, $payload('sessWhileOff', 40.0), false);
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));

        // The teacher enables grading. The learner reloads, so a fresh session token.
        $DB->set_field('exelearning', 'gradeenabled', 1, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);
        track::ingest($instance, $course, $cm, $student->id, $payload('sessAfterOn', 90.0), false);

        // From here on it records normally, and the 40 earned while ungraded is gone.
        $rows = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]);
        $this->assertNotEmpty($rows, 'work done after the switch must be recorded');
        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row->attempt, 'the ungraded period minted no attempt');
        }
        $overall = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 0,
        ]);
        $this->assertNotFalse($overall);
        $this->assertEqualsWithDelta(90.0, (float) $overall->rawscore, 0.01);
    }

    /**
     * A session opened while the activity was ungraded cannot be used to win an extra
     * gradable attempt once grading comes back on (DEC-124-03).
     *
     * The cap has a $sessionknown escape hatch so an in-progress session is never cut off
     * mid-write. If a session that started during the ungraded period could later resolve
     * to a NEW gradable attempt, that exemption would carry across and hand the learner an
     * attempt beyond maxattempt.
     */
    public function test_an_ungraded_session_cannot_win_an_extra_gradable_attempt(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student([
            'gradeenabled' => 1,
            'grademodel'   => 0,
            'maxattempt'   => 1,
        ]);
        [$course, $cm] = $this->course_and_cm($instance);

        // The learner spends their single gradable attempt.
        track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sessGraded',
            'cmi'     => ['cmi.core.score.raw' => '50', 'cmi.core.score.max' => '100'],
        ], false);
        $this->assertSame(1, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'gradable' => 1,
        ]));

        // The teacher makes the activity ungraded; the learner opens a new session. The
        // cap must not refuse them here — it is a grading control and the activity is not
        // graded — but the attempt it produces is completion-only.
        $DB->set_field('exelearning', 'gradeenabled', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);
        $result = track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sessUngraded',
            'cmi'     => ['cmi.core.score.raw' => '95', 'cmi.core.score.max' => '100'],
        ], false);
        $this->assertTrue($result['ok'], 'An ungraded activity must not enforce the attempt cap');

        // Grading comes back on while that session is still open.
        $DB->set_field('exelearning', 'gradeenabled', 1, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);
        track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sessUngraded',
            'cmi'     => ['cmi.core.score.raw' => '95', 'cmi.core.score.max' => '100'],
        ], false);

        // Still exactly one gradable attempt: maxattempt was not circumvented.
        $this->assertSame(1, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
            'itemnumber' => 0, 'gradable' => 1,
        ]));
    }

    /**
     * Turning grading off stops recording; what was earned while it was on stays
     * (DEC-126-01).
     *
     * The mirror of the case above, and the reason the previous contract's
     * "poison the whole attempt" rule is gone: a mark earned while the activity was
     * graded was legitimately earned, and the switch going off later does not make it
     * retrospectively invalid. It just means nothing more is written.
     *
     * @param int $grademodel The grade model to exercise.
     * @dataProvider grading_disabled_models_provider
     */
    public function test_switching_grading_off_stops_recording_and_keeps_what_was_earned(
        int $grademodel
    ): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student([
            'gradeenabled' => 1,
            'grademodel'   => $grademodel,
            'maxattempt'   => 1,
        ]);
        [$course, $cm] = $this->course_and_cm($instance);
        $objectid = $this->objectid_for($instance, 1);

        $payload = fn(string $raw, float $pct) => [
            'session'    => 'sessFlipOff',
            'cmi'        => [
                'cmi.core.score.raw'     => $raw,
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => [
                $objectid => ['scorepct' => $pct, 'weighted' => 100.0, 'title' => 'A'],
            ],
        ];

        // Graded work first.
        track::ingest($instance, $course, $cm, $student->id, $payload('80', 80.0), false);
        $before = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]);
        $this->assertNotEmpty($before);

        // The teacher makes the activity a plain resource; the learner does not reload
        // and keeps submitting.
        $DB->set_field('exelearning', 'gradeenabled', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);
        $result = track::ingest($instance, $course, $cm, $student->id, $payload('95', 95.0), false);
        $this->assertTrue($result['noop']);

        // The 95 was not recorded, and the 80 is untouched.
        $after = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]);
        $this->assertSame(count($before), count($after), 'the ungraded POST created no rows');
        $overall = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'itemnumber' => 0,
        ]);
        $this->assertEqualsWithDelta(80.0, (float) $overall->rawscore, 0.01, 'the earned mark must survive');
    }

    /**
     * Work done while the activity was ungraded cannot consume maxattempt, because it
     * creates no attempt at all (DEC-126-01).
     *
     * maxattempt is a grading control — mod_form.php disables it with the rest of the
     * grade settings when the activity is not graded — so charging it for work done
     * outside assessment would produce a state with no way out: at maxattempt = 1 the
     * learner reaches the limit having never had a gradable attempt.
     */
    public function test_use_while_ungraded_does_not_consume_maxattempt(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student([
            'gradeenabled' => 0,
            'grademodel'   => 0,
            'maxattempt'   => 1,
        ]);
        [$course, $cm] = $this->course_and_cm($instance);

        // The learner uses the activity while it is a plain resource.
        track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sessUngradedTry',
            'cmi'     => ['cmi.core.score.raw' => '40', 'cmi.core.score.max' => '100'],
        ], false);
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
        $this->assertSame(0, \mod_exelearning\local\attempts::count_user_attempts(
            (int) $instance->id,
            (int) $student->id
        ));

        // The teacher turns the activity into a graded one.
        $DB->set_field('exelearning', 'gradeenabled', 1, ['id' => $instance->id]);
        $instance = $DB->get_record('exelearning', ['id' => $instance->id], '*', MUST_EXIST);
        exelearning_sync_grade_items($instance->id);

        // The learner still has their attempt.
        $result = track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sessFirstGradedTry',
            'cmi'     => ['cmi.core.score.raw' => '90', 'cmi.core.score.max' => '100'],
        ], false);

        $this->assertTrue($result['ok'], 'The learner must still have a gradable attempt available');
        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id, 'gradable' => 1,
        ]));
    }

    /**
     * Both grade models, because they publish through different code paths: OVERALL
     * through the grade_update() in ingest(), PERITEM through apply_one().
     *
     * @return array<string,array{int}>
     */
    public static function grading_disabled_models_provider(): array {
        return [
            'overall' => [EXELEARNING_GRADEMODEL_OVERALL],
            'peritem' => [EXELEARNING_GRADEMODEL_PERITEM],
        ];
    }


    /**
     * Two ingest() calls for the same user with different session tokens allocate
     * distinct, gap-free attempt numbers (1 then 2). The serializing per-(instance,
     * user) lock makes the sequential path identical to the unlocked one; a real
     * concurrent interleaving (the race the lock prevents) is not reproducible in
     * single-threaded PHPUnit, so this is the functional-equivalence guard.
     */
    /**
     * With the master grading switch off (DEC-13-07), ingest() writes nothing.
     *
     * Without the guard, gradeenabled=0 leaves the instance with no grade items, the
     * registered-objectid filter empties itemscores, the server-side recompute never
     * runs — and ingest falls back to trusting the CLIENT's cmi.core.score.raw,
     * writing attempt rows, gradebook updates and lifecycle events for an activity
     * its teacher configured as ungraded. The xAPI channel has had this guard since
     * its first version; the SCORM channel was missing it.
     */
    public function test_ingest_with_grading_disabled_writes_nothing(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student(['gradeenabled' => 0]);
        [$course, $cm] = $this->course_and_cm($instance);

        $payload = [
            'session' => 'sessOff',
            'cmi' => [
                // A corruptible client-side value: nothing may trust it.
                'cmi.core.score.raw' => '95',
                'cmi.core.score.max' => '100',
                'cmi.core.lesson_status' => 'passed',
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['noop']);
        $this->assertArrayNotHasKey('rawscore', $result);
        // No attempt rows, no gradebook column, no events were produced.
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertSame([], $grades->items);
    }

    /**
     * The grading guard sits after preview handling: preview keeps its own contract.
     */
    public function test_preview_acknowledges_even_with_grading_disabled(): void {
        [$instance, $student] = $this->create_activity_with_student(['gradeenabled' => 0]);
        [$course, $cm] = $this->course_and_cm($instance);

        $result = track::ingest($instance, $course, $cm, $student->id, [
            'cmi' => ['cmi.core.score.raw' => '80', 'cmi.core.score.max' => '100'],
        ], true);

        $this->assertTrue($result['ok']);
        $this->assertSame('preview', $result['mode']);
    }

    public function test_ingest_two_sessions_allocate_distinct_attempts(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);

        $payload = fn(string $session) => [
            'session' => $session,
            'cmi' => ['cmi.core.score.raw' => '50', 'cmi.core.score.max' => '100'],
            'itemscores' => [$obj1 => ['scorepct' => 50.0, 'weighted' => 100.0, 'title' => 'a']],
        ];

        $first = track::ingest($instance, $course, $cm, $student->id, $payload('sessA'), false);
        $second = track::ingest($instance, $course, $cm, $student->id, $payload('sessB'), false);

        // Both commits succeeded and were assigned consecutive attempt numbers (no
        // collision on the unique (exelearningid, userid, attempt, itemnumber) index,
        // no skipped number).
        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame(1, $first['attempt']);
        $this->assertSame(2, $second['attempt']);

        // The persisted rows confirm distinct attempts on the same item.
        $attempts = $DB->get_fieldset_select(
            'exelearning_attempt',
            'attempt',
            'exelearningid = ? AND userid = ? AND itemnumber = ?',
            [$instance->id, $student->id, 1]
        );
        sort($attempts);
        $this->assertSame([1, 2], array_map('intval', $attempts));
    }

    /**
     * A payload with no score is a no-op acknowledgement (nothing recorded).
     */
    public function test_ingest_noop_when_no_score(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);

        $result = track::ingest($instance, $course, $cm, $student->id, ['cmi' => []], false);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['noop']);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
    }

    /**
     * Preview mode acknowledges the score without touching the gradebook (DEC-0-06).
     */
    public function test_ingest_preview_does_not_grade(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);

        $result = track::ingest($instance, $course, $cm, $student->id, [
            'cmi' => ['cmi.core.score.raw' => '90', 'cmi.core.score.max' => '100'],
        ], true);

        $this->assertSame('preview', $result['mode']);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id, 'userid' => $student->id,
        ]));
        $this->assertNull($this->published_grade($instance, $student->id, 1));
    }

    /**
     * ingest() enforces maxattempt: once the cap is reached a fresh session is
     * rejected instead of opening a new attempt.
     */
    public function test_ingest_respects_maxattempt(): void {
        [$instance, $student] = $this->create_activity_with_student(['maxattempt' => 1]);
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);

        $payload = fn(string $session) => [
            'session' => $session,
            'cmi' => ['cmi.core.score.raw' => '50', 'cmi.core.score.max' => '100'],
            'itemscores' => [$obj1 => ['scorepct' => 50.0, 'weighted' => 100.0, 'title' => 'a']],
        ];

        // First session uses up the single allowed attempt.
        $first = track::ingest($instance, $course, $cm, $student->id, $payload('s1'), false);
        $this->assertTrue($first['ok']);

        // A second, different session is over the cap and is rejected.
        $second = track::ingest($instance, $course, $cm, $student->id, $payload('s2'), false);
        $this->assertFalse($second['ok']);
        $this->assertSame('maxattemptsreached', $second['error']);
    }

    /**
     * An itemscores map far larger than any real package is dropped wholesale
     * (size cap) instead of being routed, so a client cannot flood the grader.
     */
    public function test_ingest_drops_oversized_itemscores_map(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);

        // Build a map well beyond the sane cap (>1000 entries) of fabricated ids.
        $itemscores = [];
        for ($i = 0; $i <= 1000; $i++) {
            $itemscores['fake-' . $i] = ['scorepct' => 100.0, 'weighted' => 100.0, 'title' => 'x'];
        }
        $payload = [
            'session' => 'sessOversize',
            'cmi' => ['cmi.core.score.raw' => '10', 'cmi.core.score.max' => '100'],
            'itemscores' => $itemscores,
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        // The oversized map is dropped with a developer warning, and none of the
        // fabricated scores reach the per-iDevice gradebook columns.
        $this->assertDebuggingCalled();
        $this->assertTrue($result['ok']);
        $this->assertNull($this->published_grade($instance, $student->id, 1));
        $this->assertNull($this->published_grade($instance, $student->id, 2));
    }

    /**
     * Per-iDevice scores are clamped to 0..100 before they are scaled, so an
     * out-of-range client value cannot inflate (or underflow) a gradebook column.
     */
    public function test_ingest_clamps_out_of_range_item_scores(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);
        $obj2 = $this->objectid_for($instance, 2);

        // Clamped scores are 100 and 0; their weighted mean is 50, so set the
        // client overall to 50 to avoid an (also-correct) divergence warning.
        $payload = [
            'session' => 'sessClamp',
            'cmi' => ['cmi.core.score.raw' => '50', 'cmi.core.score.max' => '100'],
            'itemscores' => [
                $obj1 => ['scorepct' => 150.0, 'weighted' => 100.0, 'title' => 'a'],
                $obj2 => ['scorepct' => -25.0, 'weighted' => 100.0, 'title' => 'b'],
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        $this->assertTrue($result['ok']);
        // 150% clamps to grademax (100); -25% clamps to grademin (0).
        $this->assertEqualsWithDelta(100.0, $this->published_grade($instance, $student->id, 1), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->published_grade($instance, $student->id, 2), 0.0001);
    }

    /**
     * In OVERALL mode ingest() publishes the aggregated overall column (DEC-25-01).
     */
    public function test_ingest_overall_mode_publishes_overall_column(): void {
        [$instance, $student] = $this->create_activity_with_student(['grademodel' => 0]);
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);

        $payload = [
            'session' => 'sessOverall',
            'cmi' => ['cmi.core.score.raw' => '70', 'cmi.core.score.max' => '100'],
            'itemscores' => [$obj1 => ['scorepct' => 70.0, 'weighted' => 100.0, 'title' => 'a']],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(70.0, $this->published_grade($instance, $student->id, 0), 0.0001);
    }

    /**
     * With no objectid map, ingest() falls back to the legacy suspend_data path.
     */
    public function test_ingest_legacy_suspend_data_path(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);

        $payload = [
            'session' => 'sessLegacy',
            'cmi' => [
                'cmi.core.score.raw' => '50',
                'cmi.core.score.max' => '100',
                'cmi.suspend_data'   => '1. "Item 1"; Score: 50%; Weight: 100%.',
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);
        $this->assertTrue($result['ok']);
    }

    /**
     * A map mixing a registered objectid with an unknown one keeps the known
     * score and drops the unknown (the registered-objectid filter).
     */
    public function test_ingest_filters_unknown_objectid_in_mixed_map(): void {
        [$instance, $student] = $this->create_activity_with_student();
        [$course, $cm] = $this->course_and_cm($instance);
        $obj1 = $this->objectid_for($instance, 1);

        $payload = [
            'session' => 'sessMixed',
            'cmi' => ['cmi.core.score.raw' => '80', 'cmi.core.score.max' => '100'],
            'itemscores' => [
                $obj1            => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
                'fake-unknown-1' => ['scorepct' => 100.0, 'weighted' => 100.0, 'title' => 'x'],
            ],
        ];

        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);

        $this->assertTrue($result['ok']);
        $this->assertEqualsWithDelta(80.0, $this->published_grade($instance, $student->id, 1), 0.0001);
    }
}
