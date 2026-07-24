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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for saving packages from the embedded eXeLearning editor.
 *
 * Receives an exported package, stores it in filearea "package", then updates
 * module metadata. Old package files are deleted only after the new one is
 * successfully stored and processed.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require('../../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

use mod_exelearning\exelearning_package_legacy;

$cmid = required_param('cmid', PARAM_INT);
$format = 'elpx';

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);
// Embedded editing can be switched off site-wide (DEC-0066): refuse saves too,
// not only the editor bootstrap — this is the state-changing half of the flow.
exelearning_require_embedded_editor_enabled();

header('Content-Type: application/json; charset=utf-8');

$newpackage = null;
$newrevision = (int)$exelearning->revision + 1;

try {
    if (empty($_FILES['package'])) {
        throw new moodle_exception('nofile', 'error');
    }

    $uploadedfile = $_FILES['package'];
    if ((int)$uploadedfile['error'] !== UPLOAD_ERR_OK) {
        throw new moodle_exception('uploadproblem', 'error');
    }
    $fs = get_file_storage();
    $defaultname = 'package.elpx';

    $filename = clean_filename($uploadedfile['name']);
    if (empty($filename)) {
        $filename = $defaultname;
    }

    $fileinfo = [
        'contextid' => $context->id,
        'component' => 'mod_exelearning',
        'filearea' => 'package',
        'itemid' => $newrevision,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $USER->id,
        'source' => $filename,
        'author' => fullname($USER),
        'license' => 'unknown',
    ];

    $newpackage = $fs->create_file_from_pathname($fileinfo, $uploadedfile['tmp_name']);

    // Keep backwards-compatible preview/index extraction when package contains website structure.
    $mainfile = false;
    try {
        $contentslist = exelearning_package_legacy::expand_package($newpackage);
        $mainfile = exelearning_package_legacy::get_mainfile(
            $contentslist,
            $newpackage->get_contextid(),
            $newpackage->get_itemid()
        );
    } catch (Throwable $e) {
        // ELPX may not include a web entrypoint; ignore content extraction errors.
        $mainfile = false;
    }

    if ($mainfile !== false) {
        file_set_sortorder(
            $context->id,
            'mod_exelearning',
            'content',
            $newpackage->get_itemid(),
            $mainfile->get_filepath(),
            $mainfile->get_filename(),
            1
        );
        $exelearning->entrypath = $mainfile->get_filepath();
        $exelearning->entryname = $mainfile->get_filename();
    }

    $exelearning->timemodified = time();
    $exelearning->usermodified = $USER->id;

    // Extract and validate the staged revision, then advance the stored pointer and prune
    // the superseded revision — all only on success (issue 73). A corrupt save throws here
    // (rolling back the staged content) BEFORE the pointer moves, so the previous package +
    // content (and revision) stay intact; the catch below drops the just-stored package, so
    // the activity remains servable and recoverable instead of being left empty. Editing in
    // the embedded editor can add or remove gradable iDevices, so the gradebook columns are
    // re-synced afterwards: new iDevices create columns, removed ones are marked deleted
    // (grade history preserved).
    \mod_exelearning\local\package_manager::store_and_activate_revision(
        $context->id,
        $exelearning,
        $newrevision
    );
    $delta = exelearning_sync_grade_items($exelearning->id, $context->id);
    // If editing changed the gradable set (added/removed/edited-options) and
    // attempts exist, warn that prior grades are not recomputed (DEC-0021). The
    // editor reloads view.php after a successful save (amd/src/editor_modal.js),
    // so this queued notification surfaces there without any JS change.
    exelearning_warn_if_grades_stale($exelearning->id, $delta, $cmid);

    echo json_encode([
        'success' => true,
        'revision' => $exelearning->revision,
        'format' => $format,
    ]);
} catch (Throwable $e) {
    if ($newpackage) {
        $newpackage->delete();
    }
    debugging('mod_exelearning editor save failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => get_string('error'),
    ]);
}
