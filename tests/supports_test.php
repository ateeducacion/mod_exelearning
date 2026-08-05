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
 * Tests for exelearning_supports() (functional classification) and file areas.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_supports
 * @covers     ::exelearning_get_file_areas
 */
final class supports_test extends advanced_testcase {
    /**
     * DEC-37-01: the module is an assessment-archetype activity. The classification
     * is fixed per module type and must not silently change.
     */
    public function test_supports_reports_assessment_classification(): void {
        $this->assertSame(MOD_ARCHETYPE_ASSIGNMENT, exelearning_supports(FEATURE_MOD_ARCHETYPE));
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, exelearning_supports(FEATURE_MOD_PURPOSE));
        $this->assertTrue(exelearning_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(exelearning_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(exelearning_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(exelearning_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(exelearning_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertFalse(exelearning_supports(FEATURE_GRADE_OUTCOMES));
        // DEC-69-01: custom completion rule completionstatusrequired is now offered.
        $this->assertTrue(exelearning_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertNull(exelearning_supports('a_feature_that_does_not_exist'));
    }

    /**
     * The plugin declares the content and package file areas.
     */
    public function test_get_file_areas_lists_content_and_package(): void {
        $areas = exelearning_get_file_areas(null, null, null);

        $this->assertArrayHasKey('content', $areas);
        $this->assertArrayHasKey('package', $areas);
    }
}
