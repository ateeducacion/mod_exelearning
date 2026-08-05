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

/**
 * Batch gradebook recalculation from the stored attempt history.
 *
 * Extracted verbatim from lib.php (DEC-71-01). The aggregation/publish logic is
 * unchanged — it still respects grademethod (DEC-0-07) and the grademodel column
 * rules (PERITEM has no overall column DEC-25-01; OVERALL has no per-iDevice
 * columns DEC-0-08) — and issues exactly one grade_update() per itemnumber with
 * grades keyed by userid (the batched, no-N+1 path from DEC-67-01 #006). lib.php
 * keeps thin delegators with the original `exelearning_*` signatures.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\grades;

use mod_exelearning\local\attempts;
use stdClass;

/**
 * Recalculates and re-publishes gradebook grades from exelearning_attempt.
 */
final class grade_recalculator {
    /**
     * Recalculates a student's gradebook grades from their attempt history,
     * respecting grademethod and grademodel. Used after deleting an attempt
     * (DEC-0-07 phase 2). If an item has no remaining attempts, clears its grade
     * (rawgrade=null).
     *
     * Single-user façade kept for its existing callers (report.php attempt deletion,
     * privacy provider erasure). Delegates to recalculate_for_users() so the
     * aggregation/publish logic lives in one place.
     *
     * @param stdClass $instance
     * @param int $userid
     */
    public static function recalculate_user(stdClass $instance, int $userid): void {
        self::recalculate_for_users($instance, [$userid]);
    }

    /**
     * Recalculates the gradebook grades of several users in a single batch.
     *
     * Bulk entry point for exelearning_update_grades($exelearning, 0): one SELECT for
     * every user's attempts (attempts::fetch_scaled_by_user_item()), an in-memory
     * group-by, and one grade_update() per itemnumber with the grades keyed by userid.
     * This replaces the former users × items N+1 (one SELECT and one grade_update()
     * per user per item).
     *
     * Aggregation respects grademethod (DEC-0-07, via attempts::aggregate_values()) and
     * the grademodel column rules: PERITEM has no overall column (DEC-25-01), OVERALL has
     * no per-iDevice columns (DEC-0-08). A user with no attempts for an item gets a null
     * rawgrade, clearing any stale grade.
     *
     * @param stdClass $instance
     * @param int[] $userids Users to recalculate; empty array is a no-op.
     */
    public static function recalculate_for_users(stdClass $instance, array $userids): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        if (empty($userids)) {
            return;
        }

        $grademax = (float) ($instance->grademax ?? 100);
        $grademethod = (int) ($instance->grademethod ?? attempts::GRADE_HIGHEST);
        $grademodel = (int) ($instance->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
        $base = [
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => $grademax,
            'grademin'  => $instance->grademin ?? 0,
            'display'   => (int) ($instance->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
        ];

        $items = [0 => clean_param($instance->name, PARAM_NOTAGS)];
        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber',
            'itemnumber, name'
        );
        foreach ($rows as $r) {
            $items[(int) $r->itemnumber] = $r->name;
        }

        // One query for every attempt of every user, grouped by user and item.
        $byuser = attempts::fetch_scaled_by_user_item($instance->id, $userids);

        foreach ($items as $itemnumber => $name) {
            unset($base['hidden']);
            // PERITEM has no overall column (DEC-25-01): never (re)publish item 0 there,
            // which would recreate it. OVERALL has no per-iDevice columns.
            if ($itemnumber === 0 && $grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
                continue;
            }
            if ($itemnumber > 0 && $grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
                continue;
            }
            // One grade_update() per item with the grades keyed by userid; core's
            // grade_update() accepts an array of grade objects.
            $grades = [];
            foreach ($userids as $uid) {
                $scaled = attempts::aggregate_values(
                    $byuser[$uid][$itemnumber] ?? [],
                    $grademethod
                );
                $grades[$uid] = (object) [
                    'userid'   => $uid,
                    'rawgrade' => ($scaled === null) ? null : ($scaled * $grademax),
                ];
            }
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                $itemnumber,
                $grades,
                $base + ['itemname' => $name]
            );
        }
    }
}
