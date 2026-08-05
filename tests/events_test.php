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
use context_module;
use context_course;
use mod_exelearning\event\attempt_completed;
use mod_exelearning\event\attempt_deleted;
use mod_exelearning\event\attempt_started;
use mod_exelearning\event\report_viewed;
use mod_exelearning\event\course_module_instance_list_viewed;
use mod_exelearning\local\track;

/**
 * Tests for the mod_exelearning events added for traceability (P2) and the
 * once-per-attempt tracking lifecycle events emitted by track::ingest() (DEC-68-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\event\attempt_completed
 * @covers     \mod_exelearning\event\attempt_deleted
 * @covers     \mod_exelearning\event\attempt_started
 * @covers     \mod_exelearning\event\report_viewed
 * @covers     \mod_exelearning\event\course_module_instance_list_viewed
 */
final class events_test extends advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $instance;
    /** @var \stdClass */
    protected $cm;
    /** @var \stdClass */
    protected $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('exelearning', $this->instance->id, 0, false, MUST_EXIST);
        $this->student = $this->getDataGenerator()->create_user();
    }

    public function test_attempt_deleted_event(): void {
        $context = context_module::instance($this->cm->id);
        $event = attempt_deleted::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
            'other'         => ['attemptid' => 3],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(attempt_deleted::class, $events[0]);
        $this->assertSame('d', $events[0]->crud);
        $this->assertSame(attempt_deleted::LEVEL_TEACHING, $events[0]->edulevel);
        $this->assertSame($context->id, $events[0]->contextid);
        $this->assertSame($this->student->id, $events[0]->relateduserid);
        $this->assertStringContainsString('3', $events[0]->get_description());
        $this->assertInstanceOf(\moodle_url::class, $events[0]->get_url());
    }

    public function test_attempt_deleted_requires_attemptid(): void {
        $context = context_module::instance($this->cm->id);
        $this->expectException(\coding_exception::class);
        attempt_deleted::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
        ])->trigger();
    }

    public function test_report_viewed_event(): void {
        $context = context_module::instance($this->cm->id);
        $event = report_viewed::create([
            'context'  => $context,
            'objectid' => $this->instance->id,
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(report_viewed::class, $events[0]);
        $this->assertSame('r', $events[0]->crud);
        $this->assertSame(report_viewed::LEVEL_TEACHING, $events[0]->edulevel);
        $this->assertStringContainsString('report', strtolower($events[0]->get_description()));
        $this->assertInstanceOf(\moodle_url::class, $events[0]->get_url());
    }

    public function test_instance_list_viewed_event(): void {
        $context = context_course::instance($this->course->id);
        $event = course_module_instance_list_viewed::create(['context' => $context]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(course_module_instance_list_viewed::class, $events[0]);
        $this->assertSame('r', $events[0]->crud);
    }

    /**
     * The restore objectid mappings are declared for each event.
     */
    public function test_event_objectid_mappings(): void {
        $this->assertIsArray(attempt_completed::get_objectid_mapping());
        $this->assertIsArray(attempt_deleted::get_objectid_mapping());
        $this->assertIsArray(attempt_started::get_objectid_mapping());
        $this->assertIsArray(report_viewed::get_objectid_mapping());
        $this->assertIsArray(\mod_exelearning\event\course_module_viewed::get_objectid_mapping());
    }

    /**
     * Filters a captured event list down to the two tracking lifecycle events,
     * preserving their trigger order (the sink also captures core completion/grade
     * events fired by the same ingest call).
     *
     * @param \core\event\base[] $events
     * @return \core\event\base[]
     */
    protected function tracking_events(array $events): array {
        return array_values(array_filter($events, function ($e) {
            return $e instanceof attempt_started || $e instanceof attempt_completed;
        }));
    }

    /**
     * A commit that scores and reaches a terminal status emits attempt_started then
     * attempt_completed, both for the learner, with the attempt/score/status payload.
     */
    public function test_tracking_events_fired_on_ingest(): void {
        global $DB;
        $exe = $DB->get_record('exelearning', ['id' => $this->instance->id], '*', MUST_EXIST);
        $payload = ['session' => 'sess-a', 'cmi' => [
            'cmi.core.score.raw'     => 60,
            'cmi.core.score.max'     => 100,
            'cmi.core.lesson_status' => 'completed',
        ]];

        $sink = $this->redirectEvents();
        $result = track::ingest($exe, $this->course, $this->cm, $this->student->id, $payload, false);
        $events = $this->tracking_events($sink->get_events());

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $events);
        $this->assertInstanceOf(attempt_started::class, $events[0]);
        $this->assertInstanceOf(attempt_completed::class, $events[1]);

        $completed = $events[1];
        $this->assertSame('u', $completed->crud);
        $this->assertSame(attempt_completed::LEVEL_PARTICIPATING, $completed->edulevel);
        $this->assertEquals($this->student->id, $completed->relateduserid);
        $this->assertEquals($result['attempt'], $completed->other['attempt']);
        $this->assertSame('completed', $completed->other['status']);
        $this->assertArrayHasKey('score', $completed->other);
        $this->assertStringContainsString('completed', strtolower($completed->get_description()));
        $this->assertInstanceOf(\moodle_url::class, $completed->get_url());
    }

    /**
     * Both lifecycle events fire at most once per attempt: a second commit in the same
     * session that stays terminal emits neither.
     */
    public function test_lifecycle_events_fire_once_per_attempt(): void {
        global $DB;
        $exe = $DB->get_record('exelearning', ['id' => $this->instance->id], '*', MUST_EXIST);
        $payload = ['session' => 'sess-b', 'cmi' => [
            'cmi.core.score.raw'     => 40,
            'cmi.core.score.max'     => 100,
            'cmi.core.lesson_status' => 'completed',
        ]];
        track::ingest($exe, $this->course, $this->cm, $this->student->id, $payload, false);

        // Second commit, same session token, still terminal -> no lifecycle event.
        $payload['cmi']['cmi.core.score.raw'] = 80;
        $sink = $this->redirectEvents();
        track::ingest($exe, $this->course, $this->cm, $this->student->id, $payload, false);

        $this->assertCount(0, $this->tracking_events($sink->get_events()));
    }

    /**
     * attempt_completed waits for the terminal transition: an incomplete commit emits
     * only attempt_started, and the later completing commit emits only attempt_completed.
     */
    public function test_attempt_completed_fires_on_terminal_transition(): void {
        global $DB;
        $exe = $DB->get_record('exelearning', ['id' => $this->instance->id], '*', MUST_EXIST);
        $payload = ['session' => 'sess-e', 'cmi' => [
            'cmi.core.score.raw'     => 30,
            'cmi.core.score.max'     => 100,
            'cmi.core.lesson_status' => 'incomplete',
        ]];

        $sink = $this->redirectEvents();
        track::ingest($exe, $this->course, $this->cm, $this->student->id, $payload, false);
        $first = $this->tracking_events($sink->get_events());
        $this->assertCount(1, $first);
        $this->assertInstanceOf(attempt_started::class, $first[0]);

        // Same session reaches a terminal status -> attempt_completed only.
        $payload['cmi']['cmi.core.score.raw']     = 90;
        $payload['cmi']['cmi.core.lesson_status'] = 'passed';
        $sink = $this->redirectEvents();
        track::ingest($exe, $this->course, $this->cm, $this->student->id, $payload, false);
        $second = $this->tracking_events($sink->get_events());

        $this->assertCount(1, $second);
        $this->assertInstanceOf(attempt_completed::class, $second[0]);
        $this->assertSame('passed', $second[0]->other['status']);
    }

    /**
     * A status-only commit (no score) is a no-op and emits no tracking event.
     */
    public function test_no_event_on_status_only_commit(): void {
        global $DB;
        $exe = $DB->get_record('exelearning', ['id' => $this->instance->id], '*', MUST_EXIST);

        $sink = $this->redirectEvents();
        $result = track::ingest($exe, $this->course, $this->cm, $this->student->id, [
            'session' => 'sess-c',
            'cmi'     => ['cmi.core.lesson_status' => 'completed'],
        ], false);

        $this->assertArrayHasKey('noop', $result);
        $this->assertCount(0, $this->tracking_events($sink->get_events()));
    }

    /**
     * A preview commit acknowledges without grading and emits no tracking event.
     */
    public function test_no_event_on_preview(): void {
        global $DB;
        $exe = $DB->get_record('exelearning', ['id' => $this->instance->id], '*', MUST_EXIST);

        $sink = $this->redirectEvents();
        $result = track::ingest($exe, $this->course, $this->cm, $this->student->id, [
            'session' => 'sess-d',
            'cmi'     => [
                'cmi.core.score.raw'     => 50,
                'cmi.core.score.max'     => 100,
                'cmi.core.lesson_status' => 'completed',
            ],
        ], true);

        $this->assertSame('preview', $result['mode']);
        $this->assertCount(0, $this->tracking_events($sink->get_events()));
    }

    public function test_attempt_completed_requires_status(): void {
        $context = context_module::instance($this->cm->id);
        $this->expectException(\coding_exception::class);
        attempt_completed::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
            'other'         => ['attempt' => 1, 'score' => 10.0],
        ])->trigger();
    }

    public function test_attempt_started_requires_attempt(): void {
        $context = context_module::instance($this->cm->id);
        $this->expectException(\coding_exception::class);
        attempt_started::create([
            'context'       => $context,
            'objectid'      => $this->instance->id,
            'relateduserid' => $this->student->id,
        ])->trigger();
    }
}
