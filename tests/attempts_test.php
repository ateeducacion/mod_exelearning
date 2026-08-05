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
use mod_exelearning\local\attempts;

/**
 * Unit tests for \mod_exelearning\local\attempts.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\attempts
 */
final class attempts_test extends advanced_testcase {
    /** @var \stdClass exelearning instance under test. */
    protected $instance;

    /** @var \stdClass enrolled student. */
    protected $student;

    /**
     * Create a course, an exelearning instance and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $this->instance = $generator->create_instance(['course' => $course->id]);

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
    }

    /**
     * Same session token reuses the attempt number; a fresh token allocates +1.
     */
    public function test_resolve_attempt_number(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        // First commit of session A allocates attempt 1.
        $attempta = attempts::resolve_attempt_number($eid, $uid, 'session-a');
        $this->assertSame(1, $attempta);
        attempts::record_item($eid, $uid, $attempta, 0, 5, 10, 'completed', 'session-a');

        // A second commit of the same session resolves to the same number.
        $this->assertSame(1, attempts::resolve_attempt_number($eid, $uid, 'session-a'));

        // A new session token allocates the next attempt number.
        $attemptb = attempts::resolve_attempt_number($eid, $uid, 'session-b');
        $this->assertSame(2, $attemptb);
        attempts::record_item($eid, $uid, $attemptb, 0, 8, 10, 'completed', 'session-b');
        $this->assertSame(2, attempts::resolve_attempt_number($eid, $uid, 'session-b'));
    }

    /**
     * record_item upserts on (attempt, itemnumber) and computes scaledscore.
     */
    public function test_record_item_upsert(): void {
        global $DB;

        $eid = $this->instance->id;
        $uid = $this->student->id;

        attempts::record_item($eid, $uid, 1, 1, 5, 10, 'completed', 'tok');
        attempts::record_item($eid, $uid, 1, 1, 7, 10, 'completed', 'tok');

        $rows = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $eid,
            'userid'        => $uid,
            'attempt'       => 1,
            'itemnumber'    => 1,
        ]);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertEquals(7, (float) $row->rawscore);
        $this->assertEqualsWithDelta(0.7, (float) $row->scaledscore, 0.0001);
    }

    /**
     * aggregate_scaled honours each grademethod across three attempts.
     */
    public function test_aggregate_scaled(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        // Three attempts, scaled 0.6, 0.9, 0.7 (rawscore over maxscore 10).
        attempts::record_item($eid, $uid, 1, 0, 6, 10, 'completed', 's1');
        attempts::record_item($eid, $uid, 2, 0, 9, 10, 'completed', 's2');
        attempts::record_item($eid, $uid, 3, 0, 7, 10, 'completed', 's3');

        $this->assertEqualsWithDelta(
            0.9,
            attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_HIGHEST),
            0.0001
        );
        $this->assertEqualsWithDelta(
            (0.6 + 0.9 + 0.7) / 3,
            attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_AVERAGE),
            0.0001
        );
        $this->assertEqualsWithDelta(
            0.6,
            attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_FIRST),
            0.0001
        );
        $this->assertEqualsWithDelta(
            0.7,
            attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_LAST),
            0.0001
        );
        $this->assertEqualsWithDelta(
            0.6,
            attempts::aggregate_scaled($eid, $uid, 0, attempts::GRADE_LOWEST),
            0.0001
        );

        // No attempts on an unused item returns null.
        $this->assertNull(attempts::aggregate_scaled($eid, $uid, 99, attempts::GRADE_HIGHEST));
    }

    /**
     * count_user_attempts counts distinct attempt numbers, not rows.
     */
    public function test_count_user_attempts(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        $this->assertSame(0, attempts::count_user_attempts($eid, $uid));

        // Attempt 1 with two items still counts as one attempt.
        attempts::record_item($eid, $uid, 1, 0, 5, 10, 'completed', 's1');
        attempts::record_item($eid, $uid, 1, 1, 5, 10, 'completed', 's1');
        $this->assertSame(1, attempts::count_user_attempts($eid, $uid));

        // A second attempt bumps the count to two.
        attempts::record_item($eid, $uid, 2, 0, 8, 10, 'completed', 's2');
        $this->assertSame(2, attempts::count_user_attempts($eid, $uid));
    }

    /**
     * count_user_attempts tracks the attempt ceiling used to enforce maxattempt.
     *
     * Several page-load sessions each allocate a fresh attempt number, so the
     * distinct-attempt count grows by one per session, which is what the
     * maxattempt comparison in track.php relies on.
     */
    public function test_count_user_attempts_maxattempt_semantics(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;

        // Simulate three separate page-load sessions, each a new attempt.
        foreach (['ses-1', 'ses-2', 'ses-3'] as $i => $token) {
            $attempt = attempts::resolve_attempt_number($eid, $uid, $token);
            $this->assertSame($i + 1, $attempt);
            attempts::record_item($eid, $uid, $attempt, 0, 5, 10, 'completed', $token);
        }
        $this->assertSame(3, attempts::count_user_attempts($eid, $uid));

        // A repeated commit within the last session does not allocate a new one.
        $attempt = attempts::resolve_attempt_number($eid, $uid, 'ses-3');
        attempts::record_item($eid, $uid, $attempt, 0, 9, 10, 'completed', 'ses-3');
        $this->assertSame(3, attempts::count_user_attempts($eid, $uid));
    }

    /**
     * participation_summary counts attempted users and the mean best percent.
     */
    public function test_participation_summary(): void {
        $eid = $this->instance->id;

        // A second enrolled student to make total=2.
        $course = get_course($this->instance->course);
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $userids = [$this->student->id, $student2->id];

        // No attempts yet: total=2, none attempted, no mean.
        $empty = attempts::participation_summary($eid, $userids);
        $this->assertSame(2, $empty['total']);
        $this->assertSame(0, $empty['attempted']);
        $this->assertNull($empty['meanpercent']);

        // Student 1 records an overall (itemnumber=0) best scaled 0.8 (80%).
        attempts::record_item($eid, $this->student->id, 1, 0, 6, 10, 'completed', 'a');
        attempts::record_item($eid, $this->student->id, 2, 0, 8, 10, 'completed', 'b');

        $summary = attempts::participation_summary($eid, $userids);
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['attempted']);
        // Mean of the single attempted user's best (0.8) → 80.0%.
        $this->assertEqualsWithDelta(80.0, (float) $summary['meanpercent'], 0.0001);
    }

    /**
     * participation_summary with an empty user list returns the zero summary.
     */
    public function test_participation_summary_empty_userids(): void {
        $summary = attempts::participation_summary($this->instance->id, []);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['attempted']);
        $this->assertNull($summary['meanpercent']);
    }

    /**
     * participation_summary aggregates per the grademethod (DEC-0-07), so the
     * front-page mean matches the gradebook instead of always using the best.
     */
    public function test_participation_summary_respects_grademethod(): void {
        $eid = $this->instance->id;
        $uid = $this->student->id;
        $userids = [$uid];

        // Two overall attempts: scaled 1.0 then 0.0 (ordered by attempt ASC).
        attempts::record_item($eid, $uid, 1, 0, 10, 10, 'completed', 's1');
        attempts::record_item($eid, $uid, 2, 0, 0, 10, 'completed', 's2');

        // Highest keeps the old behaviour (best attempt → 100%).
        $highest = attempts::participation_summary($eid, $userids, attempts::GRADE_HIGHEST);
        $this->assertEqualsWithDelta(100.0, (float) $highest['meanpercent'], 0.001);

        // Average aggregates the two attempts → 50%.
        $average = attempts::participation_summary($eid, $userids, attempts::GRADE_AVERAGE);
        $this->assertEqualsWithDelta(50.0, (float) $average['meanpercent'], 0.001);

        // Last takes the most recent attempt → 0%.
        $last = attempts::participation_summary($eid, $userids, attempts::GRADE_LAST);
        $this->assertEqualsWithDelta(0.0, (float) $last['meanpercent'], 0.001);
    }

    /**
     * aggregate_values applies the five GRADE_* methods to an ordered list and
     * returns null for the empty list (DEC-0-07).
     */
    public function test_aggregate_values(): void {
        // Ordered by attempt ASC: first 0.6, last 0.7, highest 0.9, lowest 0.6.
        $scaled = [0.6, 0.9, 0.7];

        $this->assertEqualsWithDelta(0.9, attempts::aggregate_values($scaled, attempts::GRADE_HIGHEST), 0.0001);
        $this->assertEqualsWithDelta(
            (0.6 + 0.9 + 0.7) / 3,
            attempts::aggregate_values($scaled, attempts::GRADE_AVERAGE),
            0.0001
        );
        $this->assertEqualsWithDelta(0.6, attempts::aggregate_values($scaled, attempts::GRADE_FIRST), 0.0001);
        $this->assertEqualsWithDelta(0.7, attempts::aggregate_values($scaled, attempts::GRADE_LAST), 0.0001);
        $this->assertEqualsWithDelta(0.6, attempts::aggregate_values($scaled, attempts::GRADE_LOWEST), 0.0001);

        // An empty list has no aggregate.
        $this->assertNull(attempts::aggregate_values([], attempts::GRADE_HIGHEST));
    }

    /**
     * The option/stringkey maps expose the expected method and review constants.
     */
    public function test_option_maps(): void {
        $this->assertSame([
            attempts::GRADE_HIGHEST => 'grademethod_highest',
            attempts::GRADE_AVERAGE => 'grademethod_average',
            attempts::GRADE_FIRST   => 'grademethod_first',
            attempts::GRADE_LAST    => 'grademethod_last',
            attempts::GRADE_LOWEST  => 'grademethod_lowest',
        ], attempts::grademethod_options());

        $this->assertSame([
            attempts::REVIEW_ALWAYS          => 'reviewmode_always',
            attempts::REVIEW_AFTERCOMPLETION => 'reviewmode_aftercompletion',
            attempts::REVIEW_NONE            => 'reviewmode_none',
        ], attempts::reviewmode_options());

        // The string-key helper resolves known values and falls back to highest.
        $this->assertSame('grademethod_average', attempts::grademethod_stringkey(attempts::GRADE_AVERAGE));
        $this->assertSame('grademethod_last', attempts::grademethod_stringkey(attempts::GRADE_LAST));
        $this->assertSame('grademethod_highest', attempts::grademethod_stringkey(999));
    }
}
