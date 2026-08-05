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
use cm_info;
use mod_exelearning\completion\custom_completion;
use mod_exelearning\local\attempts;
use mod_exelearning\local\track;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the mod_exelearning custom completion rule (DEC-69-01).
 *
 * Covers the module-level `completionstatusrequired` rule: the activity is marked
 * complete when the user has an attempt whose status reaches the required value.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\completion\custom_completion
 * @covers     ::exelearning_get_coursemodule_info
 */
final class completion_test extends advanced_testcase {
    /**
     * Course + exelearning instance (with the completion rule enabled) + student.
     *
     * @param int $required The EXELEARNING_COMPLETIONSTATUS_* value for the rule.
     * @return array{0: \stdClass, 1: \stdClass} [instance, student]
     */
    protected function create_activity_with_student(int $required = EXELEARNING_COMPLETIONSTATUS_ANY): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionstatusrequired' => $required,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        return [$instance, $student];
    }

    /**
     * Builds the custom_completion handler for an instance and user.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @return custom_completion
     */
    protected function handler(\stdClass $instance, int $userid): custom_completion {
        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $cminfo = cm_info::create($cm);
        return new custom_completion($cminfo, $userid);
    }

    /**
     * The rule is exposed as an available custom completion rule when enabled.
     */
    public function test_rule_is_available_when_enabled(): void {
        [$instance, $student] = $this->create_activity_with_student();
        $handler = $this->handler($instance, $student->id);

        $this->assertSame(['completionstatusrequired'], custom_completion::get_defined_custom_rules());
        $this->assertTrue($handler->is_available('completionstatusrequired'));
        $this->assertArrayHasKey('completionstatusrequired', $handler->get_custom_rule_descriptions());
    }

    /**
     * No attempt yet → the rule is incomplete.
     */
    public function test_no_attempt_is_incomplete(): void {
        [$instance, $student] = $this->create_activity_with_student();
        $handler = $this->handler($instance, $student->id);

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $handler->get_state('completionstatusrequired')
        );
    }

    /**
     * An attempt with status 'incomplete' does not satisfy the rule.
     */
    public function test_incomplete_attempt_is_incomplete(): void {
        [$instance, $student] = $this->create_activity_with_student();
        attempts::record_item($instance->id, $student->id, 1, 0, 10.0, 100.0, 'incomplete', 'sx');

        $handler = $this->handler($instance, $student->id);
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $handler->get_state('completionstatusrequired')
        );
    }

    /**
     * Ingesting a passed track via track::ingest() satisfies the "any" rule.
     */
    public function test_passed_track_completes_any_rule(): void {
        global $DB;
        [$instance, $student] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_ANY);

        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $result = track::ingest($instance, $course, $cm, $student->id, [
            'session' => 'sess1',
            'cmi' => [
                'cmi.core.score.raw' => '80',
                'cmi.core.score.max' => '100',
                'cmi.core.lesson_status' => 'passed',
            ],
        ], false);
        $this->assertTrue($result['ok']);

        $handler = $this->handler($instance, $student->id);
        $this->assertSame(
            COMPLETION_COMPLETE,
            $handler->get_state('completionstatusrequired')
        );
    }

    /**
     * A 'completed' attempt satisfies the "any" rule (either status is accepted).
     */
    public function test_completed_attempt_completes_any_rule(): void {
        [$instance, $student] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_ANY);
        attempts::record_item($instance->id, $student->id, 1, 0, 80.0, 100.0, 'completed', 'sx');

        $handler = $this->handler($instance, $student->id);
        $this->assertSame(
            COMPLETION_COMPLETE,
            $handler->get_state('completionstatusrequired')
        );
    }

    /**
     * The "passed" rule requires a passed attempt: a merely completed attempt is not
     * enough, while a passed attempt completes it.
     */
    public function test_passed_rule_requires_passed_status(): void {
        [$instance, $student] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_PASSED);

        // A completed (but not passed) attempt does not satisfy the passed rule.
        attempts::record_item($instance->id, $student->id, 1, 0, 80.0, 100.0, 'completed', 'sa');
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->handler($instance, $student->id)->get_state('completionstatusrequired')
        );

        // A passed attempt does.
        attempts::record_item($instance->id, $student->id, 2, 0, 90.0, 100.0, 'passed', 'sb');
        $this->assertSame(
            COMPLETION_COMPLETE,
            $this->handler($instance, $student->id)->get_state('completionstatusrequired')
        );
    }

    /**
     * The "completed" rule requires a completed attempt: a passed-only attempt is not
     * enough.
     */
    public function test_completed_rule_requires_completed_status(): void {
        [$instance, $student] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_COMPLETED);

        attempts::record_item($instance->id, $student->id, 1, 0, 90.0, 100.0, 'passed', 'sa');
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->handler($instance, $student->id)->get_state('completionstatusrequired')
        );

        attempts::record_item($instance->id, $student->id, 2, 0, 80.0, 100.0, 'completed', 'sb');
        $this->assertSame(
            COMPLETION_COMPLETE,
            $this->handler($instance, $student->id)->get_state('completionstatusrequired')
        );
    }

    /**
     * Regression: a per-iDevice row (itemnumber > 0) is always written as 'completed'
     * by track::apply_one(), so it must NOT complete the activity on its own. Only the
     * overall attempt row (itemnumber = 0) carries the real status.
     */
    public function test_per_item_completed_row_does_not_complete(): void {
        [$instance, $student] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_COMPLETED);

        // A scored iDevice (itemnumber 1) is 'completed', but the overall attempt
        // (itemnumber 0) is only 'incomplete': the rule must stay incomplete.
        attempts::record_item($instance->id, $student->id, 1, 1, 80.0, 100.0, 'completed', 'sx');
        attempts::record_item($instance->id, $student->id, 1, 0, 10.0, 100.0, 'incomplete', 'sx');

        $this->assertSame(
            COMPLETION_INCOMPLETE,
            $this->handler($instance, $student->id)->get_state('completionstatusrequired')
        );
    }

    /**
     * get_custom_rule_descriptions() reflects each configured status, and get_sort_order()
     * lists the rule among the standard completion rules.
     */
    public function test_descriptions_and_sort_order(): void {
        $modes = [
            EXELEARNING_COMPLETIONSTATUS_PASSED,
            EXELEARNING_COMPLETIONSTATUS_COMPLETED,
            EXELEARNING_COMPLETIONSTATUS_ANY,
        ];
        foreach ($modes as $required) {
            [$instance, $student] = $this->create_activity_with_student($required);
            $handler = $this->handler($instance, $student->id);

            $descriptions = $handler->get_custom_rule_descriptions();
            $this->assertArrayHasKey('completionstatusrequired', $descriptions);
            $this->assertNotEmpty($descriptions['completionstatusrequired']);
            $this->assertContains('completionstatusrequired', $handler->get_sort_order());
        }
    }

    /**
     * exelearning_get_coursemodule_info() exposes the rule to the completion API and
     * renders the intro when the description is shown.
     *
     * @covers ::exelearning_get_coursemodule_info
     */
    public function test_get_coursemodule_info_exposes_rule(): void {
        [$instance] = $this->create_activity_with_student(EXELEARNING_COMPLETIONSTATUS_PASSED);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $cm->showdescription = 1;

        $info = exelearning_get_coursemodule_info($cm);
        $this->assertInstanceOf(\cached_cm_info::class, $info);
        $this->assertSame(
            (int) EXELEARNING_COMPLETIONSTATUS_PASSED,
            (int) $info->customdata['customcompletionrules']['completionstatusrequired']
        );
    }

    /**
     * The module supports completion rules (DEC-69-01).
     */
    public function test_supports_completion_has_rules(): void {
        $this->assertTrue(exelearning_supports(FEATURE_COMPLETION_HAS_RULES));
    }
}
