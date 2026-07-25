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
 * Authenticated, owner-scoped management API for the opaque editor preview.
 *
 * Gated by require_login + sesskey + capability on the activity's module context
 * and scoped to the authoring $USER; `cmid` and `sesskey` are query parameters on
 * every request. Two operations, because the editor sends the whole project each
 * time rather than patching it:
 *
 *   POST   {script}   multipart: snapshot=<zip>, previewId? → {previewId}
 *   DELETE {script}   ?previewId={id}
 *
 * This replaces the four-operation protocol v2 (create / assets / revisions /
 * delete) that the current editor no longer speaks.
 *
 * The authless serving counterpart is preview.php.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

// @codingStandardsIgnoreLine — path is fixed relative to /mod/exelearning/editor/.
require('../../../config.php');

use mod_exelearning\local\preview\snapshot_store;

$cmid = required_param('cmid', PARAM_INT);

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
 * @param array $result Management result with 'status' and 'body' keys.
 * @return never
 */
function exelearning_preview_emit(array $result): void {
    http_response_code($result['status']);
    echo json_encode($result['body']);
    die;
}

/**
 * Map a store error code onto an HTTP status. Shared by both verbs so owner
 * scoping cannot answer differently depending on the method.
 *
 * @param string $error Error code from the snapshot store.
 * @return int
 */
function exelearning_preview_status(string $error): int {
    return [
        'previewforbidden' => 403,
        'missingpreview' => 404,
        'previewtoolarge' => 413,
        'previewtoomanyfiles' => 413,
    ][$error] ?? 400;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// DELETE carries previewId in the query, not as a path segment: the editor's
// client builds this URL from a template and appending a segment to a URL that
// already has a query string would drop cmid and sesskey.
if ($method === 'DELETE') {
    $previewid = required_param('previewId', PARAM_ALPHANUMEXT);
    // Owner scoping comes from the same store verdict the publish path uses, so
    // the two verbs cannot drift and both go through one status table.
    $deleted = snapshot_store::delete_owned($previewid, (int) $USER->id, (int) $cm->id);
    if ($deleted !== true) {
        exelearning_preview_emit([
            'status' => exelearning_preview_status($deleted),
            'body' => ['success' => false, 'error' => $deleted],
        ]);
    }
    exelearning_preview_emit(['status' => 200, 'body' => ['success' => true]]);
}

if ($method !== 'POST') {
    exelearning_preview_emit(['status' => 405, 'body' => ['success' => false, 'error' => 'methodnotallowed']]);
}

// One whole-project ZIP per refresh: the editor replaces the snapshot instead of
// patching it, so there is a single upload field and no revision to track.
$upload = $_FILES['snapshot'] ?? null;
if (
    !is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($upload['tmp_name'] ?? '')
) {
    exelearning_preview_emit(['status' => 400, 'body' => ['success' => false, 'error' => 'missingsnapshot']]);
}

// Absent on the first refresh (mint a capability), present afterwards (replace
// in place). snapshot_store refuses an id that is unknown or owned by someone
// else, so this cannot be used to claim another author's capability.
$previewid = optional_param('previewId', null, PARAM_ALPHANUMEXT);

$result = snapshot_store::replace((int) $USER->id, (int) $cm->id, $upload['tmp_name'], $previewid);
if (isset($result['error'])) {
    exelearning_preview_emit([
        'status' => exelearning_preview_status($result['error']),
        'body' => ['success' => false, 'error' => $result['error']],
    ]);
}

// No previewUrl: the client derives it from servingBaseUrl + /{previewId}/index.html,
// which keeps one source of truth for how a capability URL is shaped.
exelearning_preview_emit([
    'status' => 200,
    'body' => ['success' => true, 'previewId' => $result['previewid']],
]);
