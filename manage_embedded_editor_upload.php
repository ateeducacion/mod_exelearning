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
 * Same-origin upload endpoint for installing the embedded editor in Playground.
 *
 * The browser downloads the ZIP and posts it here so the PHP WASM runtime only
 * performs local extraction/installation work.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_once($CFG->dirroot . '/mod/exelearning/classes/local/embedded_editor_installer.php');
require_once($CFG->dirroot . '/mod/exelearning/classes/external/manage_embedded_editor.php');

use mod_exelearning\external\manage_embedded_editor;
use mod_exelearning\local\embedded_editor_installer;

/**
 * Emit a JSON response and terminate the request.
 *
 * @param array $payload Response payload.
 * @param int $status HTTP status code.
 * @return never
 */
function mod_exelearning_emit_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

require_login();
require_sesskey();

$context = \context_system::instance();
require_capability('moodle/site:config', $context);
require_capability('mod/exelearning:manageembeddededitor', $context);

$action = required_param('action', PARAM_ALPHA);
$version = required_param('version', PARAM_TEXT);
$validactions = ['install', 'update', 'repair'];
if (!in_array($action, $validactions, true)) {
    mod_exelearning_emit_json([
        'success' => false,
        'message' => get_string('invalidaction', 'mod_exelearning', $action),
    ], 400);
}

$file = $_FILES['editorzip'] ?? null;
if (!is_array($file) || empty($file['tmp_name'])) {
    mod_exelearning_emit_json([
        'success' => false,
        'message' => get_string('editoruploadmissingfile', 'mod_exelearning'),
    ], 400);
}

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    mod_exelearning_emit_json([
        'success' => false,
        'message' => get_string('editoruploadfailed', 'mod_exelearning', (string)($file['error'] ?? UPLOAD_ERR_NO_FILE)),
    ], 400);
}

$tmpname = (string)($file['tmp_name'] ?? '');
if ($tmpname === '' || !is_file($tmpname)) {
    mod_exelearning_emit_json([
        'success' => false,
        'message' => get_string('editoruploadmissingfile', 'mod_exelearning'),
    ], 400);
}

try {
    $installer = new embedded_editor_installer();
    if ($action === 'repair') {
        $installer->uninstall();
    }
    $result = $installer->install_from_local_zip($tmpname, $version);

    mod_exelearning_emit_json([
        'success' => true,
        'action' => $action,
        'message' => manage_embedded_editor::get_action_success_string($action),
        'version' => $result['version'] ?? '',
        'installed_at' => $result['installed_at'] ?? '',
    ]);
} catch (\moodle_exception $e) {
    // Intentional, localised error messages (invalid ZIP, digest mismatch,
    // concurrent install...) are meant to be shown to the admin.
    mod_exelearning_emit_json([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
} catch (\Throwable $e) {
    // Unexpected failures may carry internal details (paths, SQL...): log
    // them for developers and return a generic message, as editor/save.php
    // already does.
    debugging('mod_exelearning editor upload failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    mod_exelearning_emit_json([
        'success' => false,
        'message' => get_string('error'),
    ], 500);
}
