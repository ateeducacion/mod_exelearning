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
 * mod_exelearning test data generator.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * mod_exelearning test data generator.
 *
 * Builds activity instances backed by a real ELPX fixture so that the package
 * is stored, extracted and the gradable iDevices are detected exactly as in
 * production. Mirrors mod_h5pactivity / mod_scorm generators.
 */
class mod_exelearning_generator extends \testing_module_generator {
    /**
     * Default ELPX fixture (relative to plugin root) used when no package is given.
     *
     * Contains two gradable iDevices (trueorfalse + guess) so that
     * exelearning_sync_grade_items() detects two grade items.
     */
    const DEFAULT_FIXTURE = 'research/fixtures/elpx/actividad-evaluable.elpx';

    /**
     * Create a new mod_exelearning instance backed by an ELPX package.
     *
     * Supported $record overrides (beyond the standard module fields):
     *  - packagefilepath: relative (to plugin root) or absolute path to an .elpx
     *                     fixture to upload instead of the default.
     *  - package:         an already-built draft itemid (skips fixture upload).
     *  - any exelearning DB column (grademax, grademodel, grademethod, ...).
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        global $CFG, $USER;

        // Ensure the record can be modified without affecting calling code.
        $record = (object) (array) $record;

        // Sensible grading defaults so the instance behaves like the real form.
        $defaults = [
            'grademax'         => 100,
            'grademin'         => 0,
            'gradepass'        => 0,
            'grademethod'      => 0, // GRADE_HIGHEST.
            'grademodel'       => 1, // EXELEARNING_GRADEMODEL_PERITEM.
            'gradeenabled'     => 1, // Graded activity (DEC-13-07).
            'maxattempt'       => 0,
            'reviewmode'       => 1, // REVIEW_ALWAYS.
            'gradedisplaytype' => 0,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->{$field})) {
                $record->{$field} = $value;
            }
        }

        // A packagefilepath of false (or an explicit empty string) creates the
        // instance with no package at all — the create-from-scratch path
        // (issue #13 #1, DEC-13-03): the teacher authors it later in the embedded
        // editor. Otherwise resolve the fixture path (defaulting when omitted).
        $nopackage = property_exists($record, 'packagefilepath')
            && ($record->packagefilepath === false || $record->packagefilepath === '');

        if (!$nopackage) {
            if (!isset($record->packagefilepath)) {
                $record->packagefilepath = $CFG->dirroot . '/mod/exelearning/' . self::DEFAULT_FIXTURE;
            } else if (
                strpos($record->packagefilepath, $CFG->dirroot) !== 0
                    && strpos($record->packagefilepath, '/') !== 0
            ) {
                // Treat as relative to the plugin root.
                $record->packagefilepath = $CFG->dirroot . '/mod/exelearning/' . ltrim($record->packagefilepath, '/');
            }
        } else {
            // Drop the helper-only flag so it is not mistaken for a DB column.
            unset($record->packagefilepath);
        }

        // Build the draft area for the ELPX unless a draft itemid was provided
        // or this is an explicit no-package (create-from-scratch) instance.
        if (!$nopackage && empty($record->package)) {
            // Ensure we have a real user context to host the draft file.
            if (!isloggedin() || isguestuser()) {
                $this->set_user(get_admin());
            }
            if (!file_exists($record->packagefilepath)) {
                throw new coding_exception("ELPX fixture not found: {$record->packagefilepath}");
            }

            $usercontext = context_user::instance($USER->id);
            $record->package = file_get_unused_draft_itemid();

            $filerecord = [
                'component' => 'user',
                'filearea'  => 'draft',
                'contextid' => $usercontext->id,
                'itemid'    => $record->package,
                'filepath'  => '/',
                'filename'  => basename($record->packagefilepath),
                'userid'    => $USER->id,
            ];
            $fs = get_file_storage();
            $fs->create_file_from_pathname($filerecord, $record->packagefilepath);
        }

        return parent::create_instance($record, (array) $options);
    }
}
