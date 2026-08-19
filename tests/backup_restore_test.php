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
use backup;
use backup_controller;
use restore_controller;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Loaded for the EXELEARNING_COMPLETIONSTATUS_* constants used in the completion
// round-trip test.
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Backup and restore roundtrip test for mod_exelearning.
 *
 * Verifies that a duplicated/restored activity keeps its instance config, the
 * registered grade items (by stable objectid) and — with user data — the students'
 * attempts.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_exelearning_activity_task
 * @covers     \restore_exelearning_activity_task
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Backs up a course and restores it into a fresh course (with user data).
     *
     * @param \stdClass $srccourse
     * @return int The new course id.
     */
    protected function backup_and_restore(\stdClass $srccourse): int {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $srccourse->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course(
            $srccourse->fullname,
            $srccourse->shortname . '_restored',
            $srccourse->category
        );
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    public function test_backup_restore_preserves_gradeitems_and_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Source state: two registered grade items and a student attempt.
        $srcitems = $DB->get_records('exelearning_grade_item', ['exelearningid' => $instance->id, 'deleted' => 0]);
        $this->assertCount(2, $srcitems);
        $srcobjectids = array_values(array_map(fn($r) => $r->objectid, $srcitems));
        sort($srcobjectids);

        \mod_exelearning\local\attempts::record_item($instance->id, $student->id, 1, 0, 70.0, 100.0, 'completed', 'sx');
        \mod_exelearning\local\attempts::record_item(
            $instance->id,
            $student->id,
            1,
            1,
            80.0,
            100.0,
            'completed',
            'sx',
            25.0,
            3
        );

        // Stamp a known contenthash on a source grade item: it backs the
        // stale-grades warning (DEC-12-01) and must round-trip through backup.
        $DB->set_field(
            'exelearning_grade_item',
            'contenthash',
            sha1('plan003-fixture'),
            ['exelearningid' => $instance->id, 'itemnumber' => 1]
        );

        // Stamp the learned package census on the same grade item: it is package
        // metadata learned once per package, so losing it in a restore would send
        // every learner of the restored course back to the no-census fallback.
        $DB->set_field('exelearning_grade_item', 'xapiweight', 25.0,
            ['exelearningid' => $instance->id, 'itemnumber' => 1]);
        $DB->set_field('exelearning_grade_item', 'xapiorder', 3,
            ['exelearningid' => $instance->id, 'itemnumber' => 1]);

        // Roundtrip.
        $newcourseid = $this->backup_and_restore($course);

        // The restored course has exactly one exelearning instance.
        $restored = $DB->get_records('exelearning', ['course' => $newcourseid]);
        $this->assertCount(1, $restored);
        $restoredinstance = reset($restored);
        $this->assertNotSame((int) $instance->id, (int) $restoredinstance->id);

        // Its grade items survived with the same stable objectids.
        $restoreditems = $DB->get_records('exelearning_grade_item', [
            'exelearningid' => $restoredinstance->id, 'deleted' => 0,
        ]);
        $this->assertCount(2, $restoreditems);
        $restoredobjectids = array_values(array_map(fn($r) => $r->objectid, $restoreditems));
        sort($restoredobjectids);
        $this->assertSame($srcobjectids, $restoredobjectids);

        // The stamped contenthash round-tripped: the stale-grades warning
        // metadata (DEC-12-01) survives backup/restore instead of going NULL.
        $restoredrow = $DB->get_record('exelearning_grade_item', [
            'exelearningid' => $restoredinstance->id, 'itemnumber' => 1,
        ], '*', MUST_EXIST);
        $this->assertSame(sha1('plan003-fixture'), $restoredrow->contenthash);

        // The learned census columns round-tripped too (backup includes them; this
        // pins that the restore step maps them instead of silently dropping them).
        $this->assertEqualsWithDelta(25.0, (float) $restoredrow->xapiweight, 0.0001);
        $this->assertSame(3, (int) $restoredrow->xapiorder);

        // The student's attempts came across with user data.
        $attempts = $DB->get_records('exelearning_attempt', [
            'exelearningid' => $restoredinstance->id, 'userid' => $student->id,
        ]);
        $this->assertCount(2, $attempts);
        $restoreditemattempt = $DB->get_record('exelearning_attempt', [
            'exelearningid' => $restoredinstance->id,
            'userid' => $student->id,
            'itemnumber' => 1,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(25.0, (float) $restoreditemattempt->xapiweight, 0.0001);
        $this->assertSame(3, (int) $restoreditemattempt->xapiorder);
    }

    /**
     * A deliberately ungraded activity (gradeenabled=0, DEC-13-07) must stay
     * ungraded after a backup/restore. The master grading switch and the grade
     * category were missing from the backup field list, so the restored copy fell
     * back to the install.xml default (gradeenabled=1) and re-created gradebook
     * columns on first view (B4, DEC-34-01).
     */
    public function test_backup_restore_preserves_gradeenabled_off(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id, 'gradeenabled' => 0]);

        // An ungraded activity registers no grade items.
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('exelearning', ['course' => $newcourseid], '*', MUST_EXIST);
        // The switch survives: the copy stays ungraded instead of silently
        // re-enabling grading from the install.xml default.
        $this->assertSame(0, (int) $restored->gradeenabled);
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $restored->id, 'deleted' => 0]
        ));
    }

    /**
     * The custom completion rule config (completionstatusrequired, DEC-69-01) must
     * round-trip through backup/restore so the restored copy keeps the rule.
     */
    public function test_backup_restore_preserves_completionstatusrequired(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_exelearning')
            ->create_instance([
                'course' => $course->id,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionstatusrequired' => EXELEARNING_COMPLETIONSTATUS_PASSED,
            ]);

        $this->assertSame(
            EXELEARNING_COMPLETIONSTATUS_PASSED,
            (int) $DB->get_field('exelearning', 'completionstatusrequired', ['id' => $instance->id])
        );

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('exelearning', ['course' => $newcourseid], '*', MUST_EXIST);
        // The rule's stored config came across instead of reverting to NULL.
        $this->assertSame(
            EXELEARNING_COMPLETIONSTATUS_PASSED,
            (int) $restored->completionstatusrequired
        );
    }
}
