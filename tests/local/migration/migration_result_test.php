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

/**
 * Unit tests for the migration_result value object (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\migration_result
 */
final class migration_result_test extends advanced_testcase {
    /**
     * The constructor stores every field and defaults message/targetcmid.
     */
    public function test_constructor_stores_fields(): void {
        $result = new migration_result(5, 7, 'Course name', 'Activity', migration_result::STATUS_MIGRATED, 'msg', 42);
        $this->assertSame(5, $result->sourcecmid);
        $this->assertSame(7, $result->courseid);
        $this->assertSame('Course name', $result->coursename);
        $this->assertSame('Activity', $result->name);
        $this->assertSame(migration_result::STATUS_MIGRATED, $result->status);
        $this->assertSame('msg', $result->message);
        $this->assertSame(42, $result->targetcmid);

        $defaults = new migration_result(1, 2, 'C', 'A', migration_result::STATUS_NOSOURCE);
        $this->assertSame('', $defaults->message);
        $this->assertSame(0, $defaults->targetcmid);
    }

    /**
     * from_source() maps a source row, tolerating a missing coursename.
     */
    public function test_from_source_maps_row(): void {
        $source = (object) ['cmid' => 9, 'course' => 3, 'coursename' => 'C3', 'name' => 'Src'];
        $result = migration_result::from_source($source, migration_result::STATUS_ERROR, 'boom', 11);
        $this->assertSame(9, $result->sourcecmid);
        $this->assertSame(3, $result->courseid);
        $this->assertSame('C3', $result->coursename);
        $this->assertSame('Src', $result->name);
        $this->assertSame(migration_result::STATUS_ERROR, $result->status);
        $this->assertSame('boom', $result->message);
        $this->assertSame(11, $result->targetcmid);

        $nocourse = (object) ['cmid' => 1, 'course' => 1, 'name' => 'X'];
        $this->assertSame('', migration_result::from_source($nocourse, migration_result::STATUS_MIGRATED)->coursename);
    }

    /**
     * is_blocked() is true only for the skip statuses.
     */
    public function test_is_blocked(): void {
        $blocked = [
            migration_result::STATUS_NOSOURCE,
            migration_result::STATUS_AMBIGUOUSSOURCE,
            migration_result::STATUS_UNSUPPORTED,
        ];
        foreach ($blocked as $status) {
            $this->assertTrue(migration_result::from_source((object) ['cmid' => 1, 'course' => 1, 'name' => 'A'], $status)
                ->is_blocked());
        }
        $notblocked = [
            migration_result::STATUS_MIGRATED,
            migration_result::STATUS_ALREADYMIGRATED,
            migration_result::STATUS_ERROR,
        ];
        foreach ($notblocked as $status) {
            $this->assertFalse(migration_result::from_source((object) ['cmid' => 1, 'course' => 1, 'name' => 'A'], $status)
                ->is_blocked());
        }
    }
}
