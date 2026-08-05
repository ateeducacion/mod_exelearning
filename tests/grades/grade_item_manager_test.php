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

namespace mod_exelearning\grades;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the grade item manager extracted from lib.php (DEC-71-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\grades\grade_item_manager
 */
final class grade_item_manager_test extends advanced_testcase {
    /**
     * format_name() labels a per-iDevice column "activity · page · type" and clamps
     * the result to the 255-char column width (B5, DEC-34-01).
     */
    public function test_format_name_labels_and_truncates(): void {
        $instance = (object) ['name' => 'My activity'];

        // With a page name: "activity · page · type".
        $withpage = grade_item_manager::format_name($instance, (object) [
            'idevicetype' => 'trueorfalse',
            'pagename'    => 'Lesson 1',
        ]);
        $this->assertSame('My activity · Lesson 1 · trueorfalse', $withpage);

        // Without a page name: "activity · type".
        $nopage = grade_item_manager::format_name($instance, (object) [
            'idevicetype' => 'guess',
            'pagename'    => '',
        ]);
        $this->assertSame('My activity · guess', $nopage);

        // An adversarially long page title is clamped to 255 chars (multibyte-safe).
        $longinstance = (object) ['name' => str_repeat('A', 250)];
        $clamped = grade_item_manager::format_name($longinstance, (object) [
            'idevicetype' => 'crossword',
            'pagename'    => str_repeat('B', 500),
        ]);
        $this->assertSame(255, \core_text::strlen($clamped));
    }

    /**
     * apply_category() reparents every grade item of the activity to the configured
     * grade category, and is a no-op when gradecat is 0.
     */
    public function test_apply_category_reparents_items(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);

        // A target category in this course's gradebook.
        $gcat = new \grade_category(['courseid' => $course->id, 'fullname' => 'Cat'], false);
        $catid = $gcat->insert();

        $instance->gradecat = $catid;
        grade_item_manager::apply_category($instance);

        $items = \grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $course->id,
        ]);
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertSame((int) $catid, (int) $item->categoryid);
        }

        // A gradecat of 0 leaves the items where they are (no exception, no reparent).
        $instance->gradecat = 0;
        grade_item_manager::apply_category($instance);
        $stillthere = $DB->record_exists('grade_items', [
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
        ]);
        $this->assertTrue($stillthere);
    }

    /**
     * remove_all() soft-deletes the plugin grade-item rows and removes the Moodle
     * gradebook columns (master grading switch off, DEC-13-07).
     */
    public function test_remove_all_soft_deletes_rows_and_columns(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);

        // The default fixture detects two gradable iDevices.
        $this->assertSame(2, $DB->count_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ]));

        grade_item_manager::remove_all($instance);

        // Every plugin row is now soft-deleted.
        $this->assertSame(0, $DB->count_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ]));
        // And no Moodle gradebook column remains for the activity.
        $items = \grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $course->id,
        ]);
        $this->assertEmpty($items);
    }

    /**
     * update_item() never creates an overall column in PERITEM mode: the guard
     * deletes any stray itemnumber=0 column (B2b follow-up, DEC-34-01 / DEC-25-01).
     */
    public function test_update_item_keeps_no_overall_in_peritem(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id, 'grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        grade_item_manager::update_item($instance);

        $overall = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $course->id,
        ]);
        $this->assertFalse($overall);
    }
}
