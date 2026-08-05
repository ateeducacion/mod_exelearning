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
 * Completion-by-grade form validation relaxation for mod_exelearning.
 *
 * Extracted verbatim from lib.php (DEC-71-01). The relaxation rule is unchanged;
 * mod_form.php delegates through the thin lib.php wrapper. Kept as a pure
 * function (no moodleform_mod coupling) so it stays unit-testable, as the
 * original PHPDoc (B7, DEC-34-01) describes.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\grades;

/**
 * Relaxes core's completion-grade validation for a registered gradable item.
 */
final class completion_validator {
    /**
     * Relaxes core's "completion grade item has no grade field" validation error for a
     * registered gradable item (B7, DEC-34-01).
     *
     * Core's moodleform_mod::validation() rejects every completiongradeitemnumber with a
     * badcompletiongradeitemnumber error (key 'completionpassgrade') because
     * mod_exelearning maps 101 itemnumbers (gradeitems::MAX_ITEMNUMBER) but stores each
     * grade in its own table instead of exposing per-itemnumber grade_ideviceN form
     * fields — so core's "this item has no grade field" check always fails, making the
     * DEC-25-01 completion-by-grade feature impossible to save from the form. This
     * stopgap clears that specific error when "require passing grade" is OFF and the
     * chosen item is a real gradebook column (a per-iDevice item in PERITEM, or the
     * overall in OVERALL): it does carry a grade, just not via a core form field.
     * Because it only fires when completionpassgrade is unchecked, it never masks the
     * legitimate "grade to pass not set" validation. "Require passing grade" needs a
     * core_grades fieldname_mapping to validate the threshold and is left to that proper
     * fix (deferred). Kept as a pure function so it is unit-testable without building the
     * whole moodleform_mod (which couples to core availability/tags/completion fields).
     *
     * @param array $errors The errors array from moodleform_mod::validation().
     * @param array $data The submitted form data.
     * @param int $exelearningid The instance id (0 on a brand-new activity).
     * @return array The (possibly relaxed) errors array.
     */
    public static function relax_errors(array $errors, array $data, int $exelearningid): array {
        global $DB;

        $selected = $data['completiongradeitemnumber'] ?? null;
        if (
            $selected === null || $selected === ''
            || !empty($data['completionpassgrade'])
            || !isset($errors['completionpassgrade'])
        ) {
            return $errors;
        }

        $itemnumber = (int) $selected;
        $grademodel = (int) ($data['grademodel'] ?? EXELEARNING_GRADEMODEL_PERITEM);
        // A real gradebook column exists for the overall (0) only in OVERALL mode, and
        // for a per-iDevice item only in PERITEM mode — OVERALL deletes the per-iDevice
        // Moodle columns (DEC-25-01), so completion must not target one there even though
        // its exelearning_grade_item row is kept for the report.
        $registered = ($itemnumber === 0)
            ? ($grademodel === EXELEARNING_GRADEMODEL_OVERALL)
            : ($grademodel === EXELEARNING_GRADEMODEL_PERITEM
                && $DB->record_exists('exelearning_grade_item', [
                    'exelearningid' => $exelearningid,
                    'itemnumber'    => $itemnumber,
                    'deleted'       => 0,
                ]));
        if ($registered) {
            unset($errors['completionpassgrade']);
        }
        return $errors;
    }
}
