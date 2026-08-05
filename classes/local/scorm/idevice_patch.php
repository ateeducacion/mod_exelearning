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
 * Serve-time patch for the `form`/`scrambled-list` iDevice save guards (issue #13).
 *
 * Extracted verbatim from lib.php (DEC-71-01). The idempotent str_replace logic is
 * unchanged; lib.php keeps a thin delegator. See DEC-13-11 (and upstream
 * exelearning/exelearning#1925) for the rationale.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\scorm;

/**
 * Patches the score-save guard of the two iDevices that gate on `body.exe-scorm`.
 */
final class idevice_patch {
    /**
     * Drop the `body.exe-scorm` condition from the score-save guard of the `form` and
     * `scrambled-list` iDevices in the extracted package (issue #13).
     *
     * eXeLearning's `exe-scorm` body class is its "running as a SCORM export" switch.
     * The web/elpx export we serve does not carry it, and 49 of 51 iDevices either do
     * not touch it or only use it to load the SCORM wrapper (which we already inject).
     * Only `form` and `scrambled-list` put `body.hasClass('exe-scorm')` in front of
     * their `sendScore()` call, so they never persist their score here — their
     * cmi.suspend_data entry stays at the seeded 0 (the gradebook shows 0).
     *
     * Rather than add `exe-scorm` to the body (which would also switch on the SCO page
     * lifecycle and the SCORM presentation CSS), we apply the same one-line change
     * upstream describes (exelearning/exelearning#1925) at serve time, only to these
     * two save guards, so they behave like every other gradable iDevice (save on
     * `isScorm > 0`). The patch targets the unique `data.isScorm` variant of the guard
     * (the init-time guards use `ldata.isScorm`), is idempotent (the matched string is
     * removed), and degrades safely: if a future producer reformats the guard the
     * replace is a no-op and behaviour reverts to today's. See research ADR DEC-13-11.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function patch(int $contextid, int $revision): void {
        $fs = get_file_storage();
        // Map of iDevice JS filename => [ exact save-guard string => replacement ].
        $patches = [
            'form.js' => [
                '$(\'body\').hasClass(\'exe-scorm\') && data.isScorm > 0' => 'data.isScorm > 0',
            ],
            'scrambled-list.js' => [
                'document.body.classList.contains(\'exe-scorm\') && data.isScorm > 0' => 'data.isScorm > 0',
            ],
        ];
        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'content',
            $revision,
            'filepath, filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $name = $file->get_filename();
            if (!isset($patches[$name])) {
                continue;
            }
            $content = $file->get_content();
            $newcontent = $content;
            foreach ($patches[$name] as $search => $replace) {
                if (strpos($newcontent, $search) !== false) {
                    $newcontent = str_replace($search, $replace, $newcontent);
                }
            }
            if ($newcontent === $content) {
                continue;
            }
            $file->delete();
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => $revision,
                'filepath'  => $file->get_filepath(),
                'filename'  => $name,
            ], $newcontent);
        }
    }
}
