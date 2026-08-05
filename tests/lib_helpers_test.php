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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Tests for lib.php helpers: course reset and gradebook deep-link URLs.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_reset_userdata
 * @covers     ::exelearning_get_package_url
 * @covers     ::exelearning_grade_item_view_url
 * @covers     ::exelearning_grade_analysis_url
 * @covers     ::exelearning_embedded_editor_enabled
 * @covers     ::exelearning_require_embedded_editor_enabled
 * @covers     ::exelearning_get_embedded_editor_index_source
 * @covers     ::exelearning_get_embedded_editor_local_static_dir
 * @covers     ::exelearning_xapi_primary_enabled
 * @covers     \mod_exelearning\local\urls
 * @covers     \mod_exelearning\local\package_manager
 */
final class lib_helpers_test extends advanced_testcase {
    /**
     * Course + graded instance backed by the default 2-iDevice fixture.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [course, instance, cm]
     */
    private function make(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [$course, $instance, $cm];
    }

    /**
     * exelearning_reset_userdata() clears attempts only when the reset flag is set.
     */
    public function test_reset_userdata_deletes_attempts(): void {
        global $DB;
        [$course, $instance] = $this->make();
        $student = $this->getDataGenerator()->create_user();
        local\attempts::record_item($instance->id, $student->id, 1, 1, 80.0, 100.0, 'completed', 'sess');
        $this->assertSame(1, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));

        // Without the reset flag it is a no-op.
        $this->assertSame([], exelearning_reset_userdata((object) ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));

        // With the flag, attempts are cleared.
        $status = exelearning_reset_userdata((object) ['courseid' => $course->id, 'reset_exelearning' => 1]);
        $this->assertNotEmpty($status);
        $this->assertSame(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));
    }

    /**
     * exelearning_xapi_primary_enabled() defaults to on and the admin switch turns it off.
     */
    public function test_xapi_primary_grading_defaults_on_and_can_be_disabled(): void {
        $this->resetAfterTest();
        // Unset (e.g. a site upgraded before the setting existed) is treated as enabled.
        $this->assertTrue(exelearning_xapi_primary_enabled());

        set_config('xapiprimaryenabled', '0', 'exelearning');
        $this->assertFalse(exelearning_xapi_primary_enabled());

        set_config('xapiprimaryenabled', '1', 'exelearning');
        $this->assertTrue(exelearning_xapi_primary_enabled());
    }

    /**
     * exelearning_get_package_url() points at the stored package via pluginfile.
     */
    public function test_get_package_url(): void {
        [, $instance, $cm] = $this->make();
        $context = \context_module::instance($cm->id);

        $url = exelearning_get_package_url($instance, $context);

        $this->assertInstanceOf(\moodle_url::class, $url);
        $this->assertStringContainsString('pluginfile.php', $url->out(false));
        $this->assertStringContainsString('/package/', $url->out(false));
    }

    /**
     * exelearning_grade_item_view_url() deep-links per-iDevice items by objectid.
     */
    public function test_grade_item_view_url_deeplinks_by_objectid(): void {
        global $DB;
        [, $instance, $cm] = $this->make();

        // Overall (0) links to the front page with no idevice parameter.
        $overall = exelearning_grade_item_view_url($instance, $cm->id, 0);
        $this->assertStringContainsString('/mod/exelearning/view.php', $overall->out(false));
        $this->assertNull($overall->param('idevice'));

        // A per-iDevice item deep-links with its stable objectid.
        $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => 1,
            'deleted'       => 0,
        ]);
        $view = exelearning_grade_item_view_url($instance, $cm->id, 1);
        $this->assertSame($objectid, $view->param('idevice'));
    }

    /**
     * exelearning_grade_analysis_url() routes by capability: a grader lands on the
     * attempts report, a student on the iDevice view.
     */
    public function test_grade_analysis_url_by_capability(): void {
        [$course, $instance, $cm] = $this->make();
        $context = \context_module::instance($cm->id);

        // Admin (has viewreport) -> attempts report.
        $teacherurl = exelearning_grade_analysis_url($instance, $cm->id, 1, $context, 7);
        $this->assertStringContainsString('/mod/exelearning/report.php', $teacherurl->out(false));

        // Student (no viewreport) -> falls back to the iDevice view.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $studenturl = exelearning_grade_analysis_url($instance, $cm->id, 1, $context, 0);
        $this->assertStringContainsString('/mod/exelearning/view.php', $studenturl->out(false));
    }

    /**
     * The embedded-editor lib wrappers reflect the bundled editor (DEC-106-01).
     */
    public function test_embedded_editor_wrappers_reflect_bundle(): void {
        global $CFG;
        $this->resetAfterTest();

        $dir = make_temp_directory('mod_exelearning/lw-' . random_string(6)) . '/static';
        make_writable_directory($dir . '/app');
        file_put_contents($dir . '/index.html', 'x');
        $CFG->mod_exelearning_bundled_editor_dir = $dir;

        $this->assertTrue(exelearning_embedded_editor_enabled());
        $this->assertSame($dir . '/index.html', exelearning_get_embedded_editor_index_source());
        $this->assertSame($dir, exelearning_get_embedded_editor_local_static_dir());

        // With no editordisabled config at all (fresh site), editing stays on and
        // the endpoint guard passes.
        exelearning_require_embedded_editor_enabled();
    }

    /**
     * The site-wide editordisabled toggle switches embedded editing off even when
     * a valid bundle is present (DEC-108-01): the button helper reports false and
     * the editor endpoints' guard throws.
     */
    public function test_admin_toggle_disables_embedded_editing(): void {
        global $CFG;
        $this->resetAfterTest();

        $dir = make_temp_directory('mod_exelearning/lw-' . random_string(6)) . '/static';
        make_writable_directory($dir . '/app');
        file_put_contents($dir . '/index.html', 'x');
        $CFG->mod_exelearning_bundled_editor_dir = $dir;

        set_config('editordisabled', 1, 'exelearning');

        $this->assertFalse(exelearning_embedded_editor_enabled());
        $this->expectException(\moodle_exception::class);
        exelearning_require_embedded_editor_enabled();
    }
}
