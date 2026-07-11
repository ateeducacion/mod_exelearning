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
 * Authenticated, owner-scoped management API for the editor preview
 * (serving contract v2 — docs/preview-serving-contract.md, §A).
 *
 * A single AJAX dispatcher (the idiom of editor/save.php) covering the four
 * contract operations, gated by require_login + sesskey + capability on the
 * activity's module context, and owner-scoped to the authoring $USER:
 *
 *   action=create   → POST  /api/preview-session
 *   action=assets   → POST  /api/preview-session/{id}/assets   (multipart)
 *   action=revision → POST  /api/preview-session/{id}/revisions (multipart)
 *   action=delete   → DELETE /api/preview-session/{id}
 *
 * The authless serving counterpart is preview.php. All protocol logic lives in
 * \mod_exelearning\local\preview\serving so this script only authenticates,
 * marshals the request, and emits the JSON result.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// @codingStandardsIgnoreLine — path is fixed relative to /mod/exelearning/editor/.
require('../../../config.php');

use mod_exelearning\local\preview\serving;
use mod_exelearning\local\preview\session_store;

$cmid = required_param('cmid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

// Release the session lock early: serving GETs must not queue behind this write.
\core\session\manager::write_close();

header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a management result ({status, body}) and stop.
 *
 * @param array{status:int,body:array} $result
 * @return never
 */
function exelearning_preview_emit(array $result): void {
    http_response_code($result['status']);
    echo json_encode($result['body']);
    die;
}

try {
    if ($action === 'create') {
        exelearning_preview_emit(serving::create_session_response((int) $USER->id));
    }

    // Every other action targets an existing, owner-scoped session.
    $previewid = required_param('previewid', PARAM_ALPHANUMEXT);
    $owned = session_store::get_owned($previewid, (int) $USER->id);
    if ($owned['status'] !== 200) {
        $message = ($owned['status'] === 403) ? 'Access denied' : 'Preview session not found';
        exelearning_preview_emit(['status' => $owned['status'], 'body' => ['success' => false, 'error' => $message]]);
    }
    $session = $owned['session'];

    if ($action === 'assets') {
        $parts = exelearning_preview_uploaded_parts();
        exelearning_preview_emit(serving::handle_assets_request($session, $_POST['assets'] ?? null, $parts));
    }

    if ($action === 'revision') {
        $parts = exelearning_preview_uploaded_parts();
        exelearning_preview_emit(serving::handle_revision_request($session, $_POST['revision'] ?? null, $parts));
    }

    if ($action === 'delete') {
        session_store::delete_session($previewid);
        exelearning_preview_emit(['status' => 200, 'body' => ['success' => true]]);
    }

    exelearning_preview_emit(['status' => 400, 'body' => ['success' => false, 'error' => 'Unknown action']]);
} catch (Throwable $e) {
    debugging('mod_exelearning preview session endpoint failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    exelearning_preview_emit(['status' => 500, 'body' => ['success' => false, 'error' => get_string('error')]]);
}

/**
 * Read the multipart files[] upload into an index-aligned array of parts, each
 * carrying its PHP upload error code, name and (when readable) bytes. A per-file
 * upload error is NOT collapsed to empty content here — serving::collect_upload()
 * rejects the whole batch instead, so a document that exceeded the server upload
 * limit can never be published as a 0-byte file.
 *
 * @return array<int,array{error:int,name:string,bytes:?string}>
 */
function exelearning_preview_uploaded_parts(): array {
    if (empty($_FILES['files']) || !isset($_FILES['files']['tmp_name'])) {
        return [];
    }
    $tmpnames = $_FILES['files']['tmp_name'];
    $errors = $_FILES['files']['error'];
    $names = $_FILES['files']['name'] ?? [];
    // Normalize the single-file shape to arrays.
    if (!is_array($tmpnames)) {
        $tmpnames = [$tmpnames];
        $errors = [$errors];
        $names = [$names];
    }
    $parts = [];
    foreach ($tmpnames as $i => $tmpname) {
        $error = isset($errors[$i]) ? (int) $errors[$i] : UPLOAD_ERR_NO_FILE;
        $name = isset($names[$i]) ? (string) $names[$i] : '';
        $bytes = null;
        if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmpname)) {
            $read = file_get_contents($tmpname);
            $bytes = ($read === false) ? null : $read;
        }
        $parts[] = ['error' => $error, 'name' => $name, 'bytes' => $bytes];
    }
    return $parts;
}
