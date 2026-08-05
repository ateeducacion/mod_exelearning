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
use mod_exelearning\local\migration\source\classification;

/**
 * Unit tests for the classification value object (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\classification
 */
final class classification_test extends advanced_testcase {
    /**
     * ok() is migratable and carries the optional entry/itemid details.
     */
    public function test_ok(): void {
        $bare = classification::ok();
        $this->assertTrue($bare->is_ok());
        $this->assertSame(classification::OK, $bare->status);
        $this->assertNull($bare->elpxentry);
        $this->assertNull($bare->itemid);

        $detailed = classification::ok('content/elp.elpx', 3);
        $this->assertTrue($detailed->is_ok());
        $this->assertSame('content/elp.elpx', $detailed->elpxentry);
        $this->assertSame(3, $detailed->itemid);
    }

    /**
     * The blocked factories carry the matching status and are not ok.
     */
    public function test_blocked_factories(): void {
        $cases = [
            [classification::nosource(), migration_result::STATUS_NOSOURCE],
            [classification::ambiguoussource(), migration_result::STATUS_AMBIGUOUSSOURCE],
            [classification::unsupported(), migration_result::STATUS_UNSUPPORTED],
        ];
        foreach ($cases as [$verdict, $expectedstatus]) {
            $this->assertFalse($verdict->is_ok());
            $this->assertSame($expectedstatus, $verdict->status);
            $this->assertNull($verdict->elpxentry);
            $this->assertNull($verdict->itemid);
        }
    }
}
