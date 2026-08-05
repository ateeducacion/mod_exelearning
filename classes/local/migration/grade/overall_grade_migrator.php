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
 * Copies source final grades into the target overall grade item (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\grade;

/**
 * Republishes each user's source final grade through the target's overall grade item.
 *
 * No exelearning_attempt rows are created — the gradebook grade is sufficient and
 * authoritative for migrated data. Component-agnostic: the source is addressed by
 * frankenstyle, so the sibling plugin need not be installed.
 */
final class overall_grade_migrator {
    /**
     * Migrates the source activity's overall grades to the target's overall item (itemnumber 0).
     *
     * @param int $courseid Course id (shared by source and target).
     * @param string $sourcecomponent Frankenstyle of the source module (e.g. mod_exescorm).
     * @param int $sourceinstanceid Source activity instance id.
     * @param \stdClass $target Target exelearning instance row.
     * @return int Number of user grades migrated.
     */
    public static function migrate(
        int $courseid,
        string $sourcecomponent,
        int $sourceinstanceid,
        \stdClass $target
    ): int {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $itemmodule = substr($sourcecomponent, strlen('mod_'));
        // Read the source activity's overall grade item and every stored grade
        // directly (covers all users with a grade, not just currently enrolled ones).
        $sourceitem = \grade_item::fetch([
            'courseid'     => $courseid,
            'itemtype'     => 'mod',
            'itemmodule'   => $itemmodule,
            'iteminstance' => $sourceinstanceid,
            'itemnumber'   => 0,
        ]);
        if (!$sourceitem) {
            return 0;
        }
        // Collect the users with a stored grade, then read their computed final
        // grades. grade_get_grades() triggers a regrade if the final grade is
        // pending and requires explicit user ids to return the values.
        $sourcerows = \grade_grade::fetch_all(['itemid' => $sourceitem->id]);
        if (!$sourcerows) {
            return 0;
        }
        $userids = array_values(array_unique(array_map(
            static fn($row) => (int) $row->userid,
            $sourcerows
        )));
        $sourcegrades = grade_get_grades($courseid, 'mod', $itemmodule, $sourceinstanceid, $userids);
        $sourceitemgrades = $sourcegrades->items[0]->grades ?? [];

        $targetmax = (float) ($target->grademax ?? 100);
        $sourcemax = (float) ($sourcegrades->items[0]->grademax ?? ($sourceitem->grademax ?: 100));

        $grades = [];
        foreach ($sourceitemgrades as $userid => $gradeinfo) {
            if (!isset($gradeinfo->grade) || $gradeinfo->grade === null || $gradeinfo->grade === '') {
                continue;
            }
            $value = (float) $gradeinfo->grade;
            // Rescale only when the source and target maxima differ, then clamp.
            if ($sourcemax > 0 && abs($sourcemax - $targetmax) > 0.00001) {
                $value = ($value / $sourcemax) * $targetmax;
            }
            $value = max(0.0, min($targetmax, $value));
            $grades[(int) $userid] = (object) ['userid' => (int) $userid, 'rawgrade' => $value];
        }

        if (empty($grades)) {
            return 0;
        }
        exelearning_grade_item_update($target, $grades);
        return count($grades);
    }
}
