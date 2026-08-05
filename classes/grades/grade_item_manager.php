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
 * Grade item lifecycle helpers for mod_exelearning.
 *
 * Extracted verbatim from lib.php (DEC-71-01): create/guard the overall grade
 * item, format per-iDevice column names, remove all grade items and place every
 * item under the configured grade category. lib.php keeps thin delegators with
 * the original `exelearning_*` signatures (Moodle calls
 * `exelearning_grade_item_update()` directly), so behaviour is unchanged.
 *
 * The grade-model constants (EXELEARNING_GRADEMODEL_OVERALL / _PERITEM) and the
 * Moodle gradebook globals (grade_update, grade_item, GRADE_TYPE_VALUE …) are
 * defined by lib.php/gradelib.php in the global scope, which lib.php requires
 * before delegating here.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\grades;

use grade_item;
use stdClass;

/**
 * Creates, names, removes and re-categorises the activity's gradebook items.
 */
final class grade_item_manager {
    /**
     * Stub for the canonical grade item (itemnumber=0). In multi-item mode the rest
     * are created directly in grade_sync::sync().
     *
     * @param stdClass $exelearning
     * @param mixed $grades
     * @param array $itemdetails Extra grade item fields passed to grade_update().
     * @return int
     */
    public static function update_item($exelearning, $grades = null, array $itemdetails = []) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        // The overall (itemnumber=0) gradebook column only exists in OVERALL mode for a
        // graded activity (DEC-0-08, DEC-25-01). Core's grade_update_mod_grades() calls
        // this function UNCONDITIONALLY (before exelearning_update_grades()) on every
        // regrade — cron needsupdate, course reset "remove all grades", grade-item
        // unlock, user-undelete history recovery — so without this guard a PERITEM or
        // ungraded activity would get a phantom overall column that inflates the course
        // total (B2b follow-up, DEC-34-01). When the overall must not exist, delete any
        // stray one instead of creating it.
        $grademodel = (int) ($exelearning->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);
        if (empty($exelearning->gradeenabled) || $grademodel !== EXELEARNING_GRADEMODEL_OVERALL) {
            return grade_update(
                'mod/exelearning',
                $exelearning->course,
                'mod',
                'exelearning',
                $exelearning->id,
                0,
                null,
                ['deleted' => true]
            );
        }

        $item = [
            'itemname'  => clean_param($exelearning->name, PARAM_NOTAGS),
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax'  => $exelearning->grademax ?? 100,
            'grademin'  => $exelearning->grademin ?? 0,
            'gradepass' => $exelearning->gradepass ?? 0,
            'display'   => (int) ($exelearning->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
        ];
        $item += $itemdetails;

        return grade_update(
            'mod/exelearning',
            $exelearning->course,
            'mod',
            'exelearning',
            $exelearning->id,
            0,
            $grades,
            $item
        );
    }

    /**
     * Human-readable label for the gradebook column of an iDevice.
     *
     * @param stdClass $instance
     * @param stdClass $detected
     * @return string
     */
    public static function format_name(stdClass $instance, stdClass $detected): string {
        $type = clean_param($detected->idevicetype, PARAM_TEXT);
        $page = trim((string) ($detected->pagename ?? ''));
        $base = clean_param($instance->name, PARAM_NOTAGS);
        $name = ($page !== '') ? ($base . ' · ' . $page . ' · ' . $type) : ($base . ' · ' . $type);
        // Clamp to the exelearning_grade_item.name column width (char 255). The page
        // title comes from author-controlled content.xml and is unbounded; combined
        // with an up-to-255-char activity name it can exceed 255 and throw a
        // dml_write_exception on sync — which, via the view.php self-heal, is a
        // student-facing fatal (B5, DEC-34-01). core_text::substr is multibyte-safe and
        // deterministic, so re-sync does not thrash the stored name.
        return \core_text::substr($name, 0, 255);
    }

    /**
     * Removes all gradebook items of an activity (master grading switch off, DEC-13-07).
     *
     * Soft-deletes the plugin's grade-item mapping rows and deletes the matching Moodle
     * grade items, including the overall item (itemnumber 0), so nothing shows in the
     * gradebook. The attempt history (exelearning_attempt) is preserved, so re-enabling
     * grading re-detects and recomputes from it.
     *
     * @param stdClass $instance The exelearning instance row.
     * @return void
     */
    public static function remove_all(stdClass $instance): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $rows = $DB->get_records('exelearning_grade_item', ['exelearningid' => $instance->id, 'deleted' => 0]);
        foreach ($rows as $row) {
            $row->deleted = 1;
            $row->timemodified = time();
            $DB->update_record('exelearning_grade_item', $row);
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                (int) $row->itemnumber,
                null,
                ['deleted' => true]
            );
        }
        // Remove the overall item (itemnumber 0) too.
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            0,
            null,
            ['deleted' => true]
        );
    }

    /**
     * Places every grade item of the activity under the configured grade category.
     *
     * The grade category selector (DEC-19-01) is stored on exelearning.gradecat, but
     * grade_update() silently ignores the categoryid key (it is not in its allowed
     * field list), so the parent category must be set with grade_item::set_parent() —
     * the same API course/modlib.php uses for core's "Grade category" dropdown.
     * Applied to the overall and every per-iDevice item so a re-upload that adds
     * columns inherits the category too. gradecat=0 leaves the items where Moodle put
     * them (the course top category). A target category that no longer exists (e.g. a
     * cross-course restore) makes set_parent() a no-op, so items stay valid.
     *
     * @param stdClass $instance The exelearning instance (must carry id, course, gradecat).
     * @return void
     */
    public static function apply_category(stdClass $instance): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $categoryid = (int) ($instance->gradecat ?? 0);
        if ($categoryid <= 0) {
            return;
        }
        $items = grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $instance->course,
        ]);
        if (!$items) {
            return;
        }
        foreach ($items as $item) {
            if ((int) $item->categoryid !== $categoryid) {
                $item->set_parent($categoryid);
            }
        }
    }
}
