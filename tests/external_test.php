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
use core_external\external_api;
use mod_exelearning\external\get_exelearnings_by_courses;
use mod_exelearning\external\view_exelearning;
use mod_exelearning\external\get_exelearning_access_information;
use mod_exelearning\external\get_user_attempts;
use mod_exelearning\external\get_user_grades;
use mod_exelearning\external\save_track;

/**
 * Tests for the mod_exelearning external (mobile) web services.
 *
 * Covers parameter/return validation, context + login + capability enforcement and
 * the server-side safeguards of save_track.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\external\get_exelearnings_by_courses
 * @covers     \mod_exelearning\external\view_exelearning
 * @covers     \mod_exelearning\external\get_exelearning_access_information
 * @covers     \mod_exelearning\external\get_user_attempts
 * @covers     \mod_exelearning\external\get_user_grades
 * @covers     \mod_exelearning\external\save_track
 */
final class external_test extends advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $student;
    /** @var \stdClass */
    protected $other;
    /** @var \stdClass */
    protected $teacher;

    /**
     * Course + graded instance (two gradable iDevices) + a student, another
     * student and a teacher, all enrolled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $this->instance = $generator->create_instance(['course' => $this->course->id]);

        $this->student = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->other->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
    }

    /**
     * Objectid registered for a given itemnumber of the instance.
     *
     * @param int $itemnumber
     * @return string
     */
    protected function objectid_for(int $itemnumber): string {
        global $DB;
        return (string) $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $this->instance->id,
            'itemnumber'    => $itemnumber,
            'deleted'       => 0,
        ], MUST_EXIST);
    }

    public function test_view_exelearning_triggers_event_and_completion(): void {
        $this->setUser($this->student);
        $sink = $this->redirectEvents();

        $result = view_exelearning::execute($this->instance->id);
        $result = external_api::clean_returnvalue(view_exelearning::execute_returns(), $result);

        $this->assertTrue($result['status']);
        $events = array_filter($sink->get_events(), function ($e) {
            return $e instanceof \mod_exelearning\event\course_module_viewed;
        });
        $this->assertCount(1, $events);
    }

    public function test_view_exelearning_requires_enrolment(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\moodle_exception::class);
        view_exelearning::execute($this->instance->id);
    }

    public function test_get_by_courses_lists_instance_for_student(): void {
        $this->setUser($this->student);

        $result = get_exelearnings_by_courses::execute([$this->course->id]);
        $result = external_api::clean_returnvalue(get_exelearnings_by_courses::execute_returns(), $result);

        $this->assertCount(1, $result['exelearnings']);
        $this->assertSame((int) $this->instance->id, (int) $result['exelearnings'][0]['id']);
        // A student must not receive the source package download url.
        $this->assertArrayNotHasKey('packageurl', $result['exelearnings'][0]);
    }

    public function test_get_by_courses_exposes_packageurl_to_teacher_only(): void {
        $this->setUser($this->teacher);

        $result = get_exelearnings_by_courses::execute([$this->course->id]);
        $result = external_api::clean_returnvalue(get_exelearnings_by_courses::execute_returns(), $result);

        $this->assertArrayHasKey('packageurl', $result['exelearnings'][0]);
        $this->assertStringContainsString('pluginfile.php', $result['exelearnings'][0]['packageurl']);
    }

    public function test_get_by_courses_warns_on_inaccessible_course(): void {
        $this->setUser($this->student);
        $foreign = $this->getDataGenerator()->create_course();

        $result = get_exelearnings_by_courses::execute([$this->course->id, $foreign->id]);
        $result = external_api::clean_returnvalue(get_exelearnings_by_courses::execute_returns(), $result);

        $this->assertCount(1, $result['exelearnings']);
        $codes = array_column($result['warnings'], 'itemid');
        $this->assertContains((int) $foreign->id, $codes);
    }

    public function test_access_information_flags(): void {
        $this->setUser($this->student);

        $result = get_exelearning_access_information::execute($this->instance->id);
        $result = external_api::clean_returnvalue(get_exelearning_access_information::execute_returns(), $result);

        $this->assertTrue($result['canview']);
        $this->assertTrue($result['cansavetrack']);
        // A student cannot view reports nor manage the editor.
        $this->assertFalse($result['canviewreport']);
    }

    public function test_get_user_attempts_returns_own_attempts(): void {
        $this->record_overall_attempt($this->student->id, 1, 0.75, 'completed');
        $this->setUser($this->student);

        $result = get_user_attempts::execute($this->instance->id);
        $result = external_api::clean_returnvalue(get_user_attempts::execute_returns(), $result);

        $this->assertCount(1, $result['attempts']);
        $this->assertSame(1, $result['attempts'][0]['attempt']);
        $this->assertEqualsWithDelta(75.0, $result['attempts'][0]['scorepercent'], 0.0001);
    }

    /**
     * The service tells a client which attempts count towards the grade, and how many the
     * server actually charges against maxattempt (DEC-124-03).
     *
     * The attempts list is deliberately unfiltered — a learner's history is their history
     * — so a client cannot derive the cap from its length. Without both fields the Moodle
     * App would show "Attempt 1 - 95%" for work the gradebook does not carry, and would
     * render "2 of 1 attempts used" for a learner the server will still accept.
     */
    public function test_get_user_attempts_exposes_gradable_and_the_enforced_count(): void {
        \mod_exelearning\local\attempts::record_item(
            $this->instance->id,
            $this->student->id,
            1,
            0,
            95.0,
            100.0,
            'completed',
            'sungraded',
            false
        );
        $this->record_overall_attempt($this->student->id, 2, 0.6, 'completed');
        $this->setUser($this->student);

        $result = get_user_attempts::execute($this->instance->id);
        $result = external_api::clean_returnvalue(get_user_attempts::execute_returns(), $result);

        // Both attempts are returned; only one of them counts.
        $this->assertCount(2, $result['attempts']);
        $bygradable = [];
        foreach ($result['attempts'] as $a) {
            $bygradable[(int) $a['attempt']] = (int) $a['gradable'];
        }
        $this->assertSame(0, $bygradable[1]);
        $this->assertSame(1, $bygradable[2]);

        // The count the cap is enforced against excludes the ungraded-period attempt.
        $this->assertSame(1, $result['usedattempts']);
    }

    public function test_get_user_attempts_other_user_requires_viewreport(): void {
        $this->record_overall_attempt($this->other->id, 1, 0.5, 'completed');

        // A peer student cannot read another student's attempts.
        $this->setUser($this->student);
        try {
            get_user_attempts::execute($this->instance->id, $this->other->id);
            $this->fail('Expected a capability exception');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        // A teacher can.
        $this->setUser($this->teacher);
        $result = get_user_attempts::execute($this->instance->id, $this->other->id);
        $result = external_api::clean_returnvalue(get_user_attempts::execute_returns(), $result);
        $this->assertCount(1, $result['attempts']);
    }

    public function test_get_user_grades_returns_per_item_grades(): void {
        // Grade the student via the same pipeline the service uses.
        $this->setUser($this->student);
        $this->save_two_item_scores(80.0, 40.0);

        $result = get_user_grades::execute($this->instance->id);
        $result = external_api::clean_returnvalue(get_user_grades::execute_returns(), $result);

        $byitem = [];
        foreach ($result['grades'] as $g) {
            $byitem[$g['itemnumber']] = $g;
        }
        $this->assertEqualsWithDelta(80.0, $byitem[1]['grade'], 0.0001);
        $this->assertEqualsWithDelta(40.0, $byitem[2]['grade'], 0.0001);
    }

    public function test_get_user_grades_other_user_requires_viewreport(): void {
        $this->setUser($this->student);
        $this->expectException(\required_capability_exception::class);
        get_user_grades::execute($this->instance->id, $this->other->id);
    }

    public function test_save_track_records_grades_and_recomputes_overall(): void {
        $this->setUser($this->student);

        $result = save_track::execute($this->instance->id, [
            'session'  => 'mobileSess',
            'scoreraw' => 0, // The client overall (0) must be ignored.
            'scoremax' => 100,
            'status'   => 'completed',
            'itemscores' => [
                ['objectid' => $this->objectid_for(1), 'scorepct' => 80.0, 'weighted' => 100.0],
                ['objectid' => $this->objectid_for(2), 'scorepct' => 40.0, 'weighted' => 100.0],
            ],
        ]);
        // The server-side overall recompute (mean 60) diverges from the client 0,
        // which is logged (DEC-6-01).
        $this->assertDebuggingCalled();
        $result = external_api::clean_returnvalue(save_track::execute_returns(), $result);

        $this->assertTrue($result['status']);
        $this->assertSame(1, $result['attempt']);
        $this->assertEqualsWithDelta(60.0, $result['score'], 0.0001);
        $this->assertEqualsWithDelta(80.0, $this->published_grade(1), 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->published_grade(2), 0.0001);
    }

    public function test_save_track_ignores_unknown_objectid(): void {
        global $DB;
        $this->setUser($this->student);

        $result = save_track::execute($this->instance->id, [
            'session'  => 'mobileSess2',
            'scoreraw' => 50,
            'scoremax' => 100,
            'status'   => 'completed',
            'itemscores' => [
                ['objectid' => 'totally-unknown-objectid', 'scorepct' => 99.0, 'weighted' => 100.0],
            ],
        ]);
        $result = external_api::clean_returnvalue(save_track::execute_returns(), $result);

        $this->assertTrue($result['status']);
        // No per-iDevice grade was published for the bogus objectid.
        $this->assertNull($this->published_grade(1));
        $this->assertNull($this->published_grade(2));
        // The bogus objectid (scorepct 99) did not skew the overall: the recorded
        // overall attempt keeps the client's declared score (50%), not 99%.
        $overall = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $this->instance->id, 'userid' => $this->student->id, 'itemnumber' => 0,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(0.5, (float) $overall->scaledscore, 0.0001);
    }

    public function test_save_track_status_only_commit_is_noop(): void {
        global $DB;
        $this->setUser($this->student);

        // A status-only commit (scoreraw omitted): the student opened and closed the
        // activity without answering. It must be a no-op, not a recorded 0-score
        // attempt that drags the grade down and burns a maxattempt slot (B6,
        // DEC-34-01). The web track.php path no-ops the same payload.
        $result = save_track::execute($this->instance->id, [
            'session' => 'mobileEmpty',
            'status'  => 'incomplete',
        ]);
        $result = external_api::clean_returnvalue(save_track::execute_returns(), $result);

        $this->assertSame(0, $result['attempt']);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $this->instance->id,
            'userid'        => $this->student->id,
        ]));
        $this->assertNull($this->published_grade(1));
        $this->assertNull($this->published_grade(2));
    }

    public function test_save_track_requires_savetrack_capability(): void {
        // Strip savetrack from the student role in this context.
        $studentrole = $this->getDataGenerator()->create_role();
        $context = \context_module::instance(
            get_coursemodule_from_instance('exelearning', $this->instance->id)->id
        );
        $blocked = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($blocked->id, $this->course->id, 'student');
        assign_capability('mod/exelearning:savetrack', CAP_PROHIBIT, $studentrole, $context->id);
        role_assign($studentrole, $blocked->id, $context->id);

        $this->setUser($blocked);
        $this->expectException(\required_capability_exception::class);
        save_track::execute($this->instance->id, [
            'session' => 's', 'scoreraw' => 10, 'scoremax' => 100, 'status' => 'completed', 'itemscores' => [],
        ]);
    }

    public function test_save_track_maxattempt_warning(): void {
        global $DB;
        $DB->set_field('exelearning', 'maxattempt', 1, ['id' => $this->instance->id]);
        $this->setUser($this->student);

        $payload = fn(string $s) => [
            'session' => $s, 'scoreraw' => 50, 'scoremax' => 100, 'status' => 'completed',
            'itemscores' => [['objectid' => $this->objectid_for(1), 'scorepct' => 50.0, 'weighted' => 100.0]],
        ];

        $first = save_track::execute($this->instance->id, $payload('m1'));
        $this->assertTrue($first['status']);

        $second = save_track::execute($this->instance->id, $payload('m2'));
        $second = external_api::clean_returnvalue(save_track::execute_returns(), $second);
        $this->assertFalse($second['status']);
        $this->assertNotEmpty($second['warnings']);
        $this->assertSame('maxattemptsreached', $second['warnings'][0]['warningcode']);
    }

    // Helpers.

    /**
     * Record an overall (itemnumber=0) attempt directly for a user.
     *
     * @param int $userid
     * @param int $attempt
     * @param float $scaled 0..1 scaled score
     * @param string $status
     */
    protected function record_overall_attempt(int $userid, int $attempt, float $scaled, string $status): void {
        \mod_exelearning\local\attempts::record_item(
            $this->instance->id,
            $userid,
            $attempt,
            0,
            $scaled * 100,
            100.0,
            $status,
            'seed' . $attempt
        );
    }

    /**
     * Grade the current user's two iDevices through save_track.
     *
     * @param float $s1
     * @param float $s2
     */
    protected function save_two_item_scores(float $s1, float $s2): void {
        save_track::execute($this->instance->id, [
            'session' => 'seedSess',
            'scoreraw' => 0, 'scoremax' => 100, 'status' => 'completed',
            'itemscores' => [
                ['objectid' => $this->objectid_for(1), 'scorepct' => $s1, 'weighted' => 100.0],
                ['objectid' => $this->objectid_for(2), 'scorepct' => $s2, 'weighted' => 100.0],
            ],
        ]);
        // This fixture helper grades via save_track; absorb the DEC-6-01 divergence log.
        $this->assertDebuggingCalled();
    }

    /**
     * Published gradebook grade for the current student on an itemnumber.
     *
     * @param int $itemnumber
     * @return float|null
     */
    protected function published_grade(int $itemnumber): ?float {
        $grades = grade_get_grades($this->instance->course, 'mod', 'exelearning', $this->instance->id, $this->student->id);
        if (!isset($grades->items[$itemnumber]->grades[$this->student->id])) {
            return null;
        }
        $grade = $grades->items[$itemnumber]->grades[$this->student->id]->grade;
        return ($grade === null) ? null : (float) $grade;
    }
}
