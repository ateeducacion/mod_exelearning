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

namespace mod_exelearning\local\xapi;

/**
 * Unit tests for exact xAPI weighted-score reconstruction.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\xapi\weighted_score_calculator
 */
final class weighted_score_calculator_test extends \advanced_testcase {
    public function test_unequal_weights_reconstruct_multipage_score(): void {
        $score = weighted_score_calculator::calculate([
            ['scorepct' => 100, 'weight' => 25, 'ideviceorder' => 1],
            ['scorepct' => 40, 'weight' => 75, 'ideviceorder' => 2],
        ]);

        $this->assertSame(55.0, $score);
    }

    public function test_publication_order_breaks_equal_remainder_ties(): void {
        // Arrival order is deliberately B, A, C. Publication order gives A the
        // remaining point: 34/33/33, so only A scoring 100 produces exactly 34.
        $score = weighted_score_calculator::calculate([
            ['scorepct' => 0, 'weight' => 1, 'ideviceorder' => 2],
            ['scorepct' => 100, 'weight' => 1, 'ideviceorder' => 1],
            ['scorepct' => 0, 'weight' => 1, 'ideviceorder' => 3],
        ]);

        $this->assertSame(34.0, $score);
    }

    public function test_invalid_rows_are_ignored_and_scores_are_clamped(): void {
        $score = weighted_score_calculator::calculate([
            ['scorepct' => 120, 'weight' => 20, 'ideviceorder' => 1],
            ['scorepct' => -10, 'weight' => 80, 'ideviceorder' => 2],
            ['scorepct' => 50, 'weight' => 0, 'ideviceorder' => 3],
            ['weight' => 50, 'ideviceorder' => 4],
        ]);

        $this->assertSame(20.0, $score);
        $this->assertNull(weighted_score_calculator::calculate([]));
    }
}
