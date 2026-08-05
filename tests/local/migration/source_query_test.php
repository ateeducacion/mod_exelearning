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
use mod_exelearning\local\migration\source\source_query;

/**
 * Unit tests for the shared source enumeration SQL builder (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\source_query
 */
final class source_query_test extends advanced_testcase {
    /**
     * The query targets the right table, joins the metadata and the migration map,
     * and appends sibling-specific columns.
     */
    public function test_build_includes_joins_and_extra_columns(): void {
        $sql = source_query::build('exeweb', 'a.revision');

        // Targets the sibling table and the shared joins.
        $this->assertStringContainsString('FROM {exeweb} a', $sql);
        $this->assertStringContainsString('JOIN {course_modules} cm', $sql);
        $this->assertStringContainsString('cm.deletioninprogress = 0', $sql);
        $this->assertStringContainsString('JOIN {context} ctx', $sql);
        $this->assertStringContainsString('LEFT JOIN {exelearning_migration} mig', $sql);
        // Carries the cm metadata and the migration id used by preflight.
        $this->assertStringContainsString('cm.availability AS cmavailability', $sql);
        $this->assertStringContainsString('mig.id AS migrationid', $sql);
        // Named params used by the handlers.
        $this->assertStringContainsString(':moduleid', $sql);
        $this->assertStringContainsString(':ctxlevel', $sql);
        $this->assertStringContainsString(':component', $sql);
        // The extra columns are appended.
        $this->assertStringContainsString('a.revision', $sql);
    }

    /**
     * The exescorm variant appends its own columns; empty extras add no trailing comma.
     */
    public function test_build_variants(): void {
        $scorm = source_query::build('exescorm', 'a.exescormtype, a.reference');
        $this->assertStringContainsString('FROM {exescorm} a', $scorm);
        $this->assertStringContainsString('a.exescormtype, a.reference', $scorm);

        $bare = source_query::build('exeweb', '');
        $this->assertStringContainsString('mig.id AS migrationid', $bare);
        $this->assertStringNotContainsString('mig.id AS migrationid,', $bare);
    }
}
