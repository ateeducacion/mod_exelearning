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
 * Authenticated management endpoint for complete editor-preview snapshots.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_exelearning\local\preview\snapshot_store;

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../../config.php');

/**
 * Send a JSON response and stop.
 *
 * @param array<string,mixed> $payload Response object.
 * @param int $status HTTP status.
 * @return never
 */
function exelearning_preview_json(array $payload, int $status): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$argument = trim((string) get_file_argument(), '/');
$parts = $argument === '' ? [] : explode('/', $argument);
$cmid = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
$previewid = $parts[1] ?? null;
if ($cmid <= 0 || count($parts) > 2) {
    exelearning_preview_json(['error' => 'Invalid preview path'], 400);
}

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

$requestkey = $_SERVER['HTTP_X_MOODLE_SESSKEY'] ?? '';
if (!is_string($requestkey) || !confirm_sesskey($requestkey)) {
    exelearning_preview_json(['error' => 'Invalid session key'], 403);
}

$store = new snapshot_store();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'DELETE' && is_string($previewid)) {
    try {
        $deleted = $store->delete((int) $USER->id, $cmid, $previewid);
    } catch (UnexpectedValueException $error) {
        exelearning_preview_json(['error' => $error->getMessage()], 403);
    }
    exelearning_preview_json([], $deleted ? 204 : 404);
}

if ($method !== 'POST' || $previewid !== null) {
    exelearning_preview_json(['error' => 'Method not allowed'], 405);
}

$upload = $_FILES['snapshot'] ?? null;
if (!is_array($upload) || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
    exelearning_preview_json(['error' => 'Missing preview snapshot'], 400);
}
$replacementid = optional_param('previewId', null, PARAM_ALPHANUMEXT);
try {
    $id = $store->replace((int) $USER->id, $cmid, $upload['tmp_name'], $replacementid);
} catch (invalid_parameter_exception | LengthException $error) {
    exelearning_preview_json(['error' => $error->getMessage()], 400);
} catch (UnexpectedValueException $error) {
    exelearning_preview_json(['error' => $error->getMessage()], 403);
} catch (moodle_exception $error) {
    exelearning_preview_json(['error' => $error->getMessage()], 404);
}
exelearning_preview_json(['previewId' => $id], 200);
