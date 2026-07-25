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
 * mod_exelearning tracking endpoint (SCORM bridge).
 *
 * Receives CMI pairs from the SCORM 1.2 shim that lives in `view.php` and hands
 * them to {@see \mod_exelearning\local\track::ingest()}, the shared scoring
 * pipeline (also used by the `save_track` web service for the mobile app). This
 * script only does the web-specific part: sesskey + capability authentication and
 * the JSON response (including the 409 status when the attempt cap is reached).
 *
 * Endpoint: POST `{ id: <cmid>, sesskey: "<key>", cmi: { "cmi.core.score.raw": "85", ... } }`.
 * The session key is part of the JSON body, never a query-string parameter (SEC-04);
 * {@see \mod_exelearning\local\tracking_endpoint::require_body_sesskey()} confirms it.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$cmid = required_param('id', PARAM_INT);
$mode = optional_param('mode', 'grading', PARAM_ALPHA);

// The sesskey arrives in the POST body rather than the query string (SEC-04), so the
// body is decoded first and the request authenticated from it. Decoding untrusted JSON
// before authenticating is safe; nothing is read out of it until the key is confirmed.
$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    throw new \moodle_exception('invalidparameter', 'error');
}
\mod_exelearning\local\tracking_endpoint::require_body_sesskey($payload);

$cm = get_coursemodule_from_id('exelearning', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

// Preview mode (DEC-0006): only when the caller has management capability.
// A regular student who changes ?mode=preview falls back to grading silently.
$ispreview = ($mode === 'preview')
        && has_capability('moodle/course:manageactivities', $context);
if (!$ispreview) {
    require_capability('mod/exelearning:savetrack', $context);
}

if (!isset($payload['cmi']) || !is_array($payload['cmi'])) {
    throw new \moodle_exception('invalidparameter', 'error');
}

// All scoring logic (normalisation, objectid routing, server-side overall
// recompute, attempt cap and completion) lives in the shared, unit-tested
// ingest() so the web and web-service paths cannot diverge.
$result = \mod_exelearning\local\track::ingest($exelearning, $course, $cm, $USER->id, $payload, $ispreview);

// The attempt cap is a conflict, not a server error: signal it with HTTP 409 as
// the client shim expects (the JSON body carries the details either way).
if (!empty($result['error']) && $result['error'] === 'maxattemptsreached') {
    http_response_code(409);
}

echo json_encode($result);
