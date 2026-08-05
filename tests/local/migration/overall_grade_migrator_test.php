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

namespace mod_exelearning\local\migration;

use advanced_testcase;
use mod_exelearning\local\migration\grade\overall_grade_migrator;
use mod_exelearning\local\migration\target\activity_builder;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the overall grade migrator (issue #13 #3, DEC-13-12).
 *
 * The source grade item is seeded on a real installed module (mod_assign stands in
 * for mod_exescorm, which is not installed in CI). The migrator is component-agnostic,
 * so this exercises the same read + write path.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\grade\overall_grade_migrator
 */
final class overall_grade_migrator_test extends advanced_testcase {
    use helper_trait;

    /**
     * Builds a target exelearning activity in the given course.
     *
     * @param int $courseid Course id.
     * @return \stdClass The activity_builder result {cm, instance, contextid}.
     */
    private function build_target(int $courseid): \stdClass {
        $source = $this->make_source_row(['course' => $courseid, 'name' => 'Migrated SCORM']);
        return activity_builder::create_from_source($source, EXELEARNING_GRADEMODEL_OVERALL);
    }

    /**
     * Each user's source final grade is copied to the target's overall grade item.
     */
    public function test_migrates_each_user_final_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $u2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $assignitem = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0,
        ]);
        $assignitem->update_final_grade($u1->id, 80.0);
        $assignitem->update_final_grade($u2->id, 55.0);

        $target = $this->build_target((int) $course->id);
        $migrated = overall_grade_migrator::migrate(
            (int) $course->id,
            'mod_assign',
            (int) $assign->id,
            $target->instance
        );
        $this->assertSame(2, $migrated);

        $grades = grade_get_grades($course->id, 'mod', 'exelearning', $target->instance->id, [$u1->id, $u2->id]);
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$u1->id]->grade, 0.001);
        $this->assertEqualsWithDelta(55.0, (float) $grades->items[0]->grades[$u2->id]->grade, 0.001);

        // No exelearning_attempt rows are created for migrated grades.
        $this->assertSame(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $target->instance->id]));
    }

    /**
     * Grades are rescaled when the source maximum differs from the target maximum.
     */
    public function test_rescales_when_source_max_differs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Source graded out of 50; the target overall item is out of 100.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 50]);
        $assignitem = \grade_item::fetch([
            'courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $assign->id, 'itemnumber' => 0,
        ]);
        $assignitem->update_final_grade($u1->id, 40.0);

        $target = $this->build_target((int) $course->id);
        $migrated = overall_grade_migrator::migrate(
            (int) $course->id,
            'mod_assign',
            (int) $assign->id,
            $target->instance
        );
        $this->assertSame(1, $migrated);

        $grades = grade_get_grades($course->id, 'mod', 'exelearning', $target->instance->id, [$u1->id]);
        // 40 out of 50 rescales to 80 out of 100.
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$u1->id]->grade, 0.001);
    }

    /**
     * A source with no grade item migrates nothing.
     */
    public function test_no_source_item_migrates_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $target = $this->build_target((int) $course->id);

        $migrated = overall_grade_migrator::migrate(
            (int) $course->id,
            'mod_assign',
            424242,
            $target->instance
        );
        $this->assertSame(0, $migrated);
    }
}
