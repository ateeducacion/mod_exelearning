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
use mod_exelearning\local\attempts;
use mod_exelearning\local\migration\target\activity_builder;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the target activity builder (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\target\activity_builder
 */
final class activity_builder_test extends advanced_testcase {
    use helper_trait;

    /**
     * The created course module mirrors the source cm's visibility, groups, intro,
     * availability and completion settings.
     */
    public function test_created_module_mirrors_source_cm_metadata(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'numsections' => 3]);
        $grouping = $this->getDataGenerator()->create_grouping(['courseid' => $course->id]);
        // A non-empty restriction: an empty availability tree is legitimately nulled by core.
        $availability = '{"op":"&","c":[{"type":"date","d":">=","t":1700000000}],"showc":[true]}';

        $source = $this->make_source_row([
            'course'                => (int) $course->id,
            'sectionnum'            => 2,
            'name'                  => 'Kept metadata',
            'intro'                 => '<p>Original intro</p>',
            'introformat'           => FORMAT_HTML,
            'cmvisible'             => 0,
            'cmvisibleoncoursepage' => 1,
            'cmgroupmode'           => SEPARATEGROUPS,
            'cmgroupingid'          => (int) $grouping->id,
            'cmavailability'        => $availability,
            'cmcompletion'          => COMPLETION_TRACKING_MANUAL,
            'cmcompletionview'      => 1,
        ]);

        $target = activity_builder::create_from_source($source, EXELEARNING_GRADEMODEL_OVERALL);

        $cm = $DB->get_record('course_modules', ['id' => $target->cm->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $cm->visible);
        $this->assertSame(SEPARATEGROUPS, (int) $cm->groupmode);
        $this->assertSame((int) $grouping->id, (int) $cm->groupingid);
        $this->assertSame($availability, $cm->availability);
        $this->assertSame(COMPLETION_TRACKING_MANUAL, (int) $cm->completion);
        $this->assertSame(1, (int) $cm->completionview);
        $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $cm->section]);
        $this->assertSame(2, $sectionnum);
        $this->assertSame('Kept metadata', $target->instance->name);
        $this->assertSame('<p>Original intro</p>', $target->instance->intro);
    }

    /**
     * The source idnumber is never copied: the source survives in the same course,
     * so copying it would create a course-wide duplicate (DEC-13-12).
     */
    public function test_idnumber_is_never_copied(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $source = $this->make_source_row([
            'course' => (int) $course->id,
            'name'   => 'Has idnumber',
        ]);
        // Even if the source row carried an idnumber, the builder must not copy it.
        $source->cmidnumber = 'LEGACY-1';

        $target = activity_builder::create_from_source($source, EXELEARNING_GRADEMODEL_PERITEM);

        $cm = $DB->get_record('course_modules', ['id' => $target->cm->id], '*', MUST_EXIST);
        $this->assertSame('', (string) $cm->idnumber);
    }

    /**
     * Grade defaults match the pre-refactor behaviour (model, max, highest aggregation).
     */
    public function test_grade_defaults_preserved(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $source = $this->make_source_row(['course' => (int) $course->id, 'name' => 'Defaults']);

        $target = activity_builder::create_from_source($source, EXELEARNING_GRADEMODEL_OVERALL);

        $this->assertSame((int) EXELEARNING_GRADEMODEL_OVERALL, (int) $target->instance->grademodel);
        $this->assertSame((int) attempts::GRADE_HIGHEST, (int) $target->instance->grademethod);
    }

    /**
     * When completion is disabled site-wide the builder skips completion without error.
     */
    public function test_completion_settings_skipped_when_completion_disabled(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 0;

        $course = $this->getDataGenerator()->create_course();
        $source = $this->make_source_row([
            'course'           => (int) $course->id,
            'name'             => 'No completion',
            'cmcompletion'     => COMPLETION_TRACKING_MANUAL,
            'cmcompletionview' => 1,
        ]);

        $target = activity_builder::create_from_source($source, EXELEARNING_GRADEMODEL_PERITEM);

        $cm = $DB->get_record('course_modules', ['id' => $target->cm->id], '*', MUST_EXIST);
        $this->assertSame(COMPLETION_TRACKING_NONE, (int) $cm->completion);
    }
}
