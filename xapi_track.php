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
 * mod_exelearning xAPI tracking endpoint (DEC-85-01).
 *
 * The xAPI counterpart of `track.php`. The `js/xapi_listener.js` listener in `view.php`
 * receives `exe-xapi-statement` postMessages from the package iframe, validates the
 * origin, and POSTs each statement here. This script does the web-specific part —
 * sesskey + capability authentication and the JSON response — and hands the statement
 * to the shared, unit-tested {@see \mod_exelearning\local\xapi\ingestor::ingest()},
 * which ignores the statement actor (grading is attributed to $USER), validates the
 * statement (DEC-0063) and routes it through the existing grade pipeline (DEC-17-01).
 *
 * Endpoint: POST `{ id: <cmid>, sesskey: "<key>", statement: {...}, registration: "<token>" }`.
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

// Preview mode (DEC-0006): only when the caller has management capability. A regular
// student who changes ?mode=preview falls back to grading silently, like track.php.
$ispreview = ($mode === 'preview')
        && has_capability('moodle/course:manageactivities', $context);
if (!$ispreview) {
    require_capability('mod/exelearning:savetrack', $context);
}

// Master switch (DEC-85-01): when xAPI-primary grading is off site-wide, accept and ignore.
// The eXeLearning emitter is always-on in the export, so a statement can still reach here
// (a stale page or a crafted POST); it must not grade — the package is graded via SCORM.
if (!exelearning_xapi_primary_enabled()) {
    echo json_encode(['ok' => true, 'disabled' => true]);
    die;
}

if (!isset($payload['statement'])) {
    throw new \moodle_exception('invalidparameter', 'error');
}
// The listener posts the statement as a nested object; tolerate a JSON-encoded string too.
$statement = is_array($payload['statement'])
        ? $payload['statement']
        : json_decode((string) $payload['statement'], true);
if (!is_array($statement)) {
    throw new \moodle_exception('invalidparameter', 'error');
}
// Bound the attempt-grouping token to the char(40) column width (and the
// PARAM_ALPHANUMEXT charset) so a long or foreign value cannot overflow
// exelearning_attempt.sessiontoken / _tracking_events.registration. A non-string body
// value is ignored; the statement's own context.registration — bounded the same way in
// the normaliser — is the fallback when the host sends none.
$registration = (isset($payload['registration']) && is_string($payload['registration']))
        ? substr(clean_param($payload['registration'], PARAM_ALPHANUMEXT), 0, 40) : '';

// All validation (DEC-0063), normalisation, objectid routing, idempotency, attempt
// recording, grading and completion live in the shared, unit-tested ingestor.
$result = \mod_exelearning\local\xapi\ingestor::ingest(
    $exelearning,
    $course,
    $cm,
    (int) $USER->id,
    $statement,
    $registration,
    $ispreview
);

// The attempt cap is a conflict, not a server error: signal it with HTTP 409 as the
// listener expects (the JSON body carries the details either way).
if (!empty($result['error']) && $result['error'] === 'maxattemptsreached') {
    http_response_code(409);
}

echo json_encode($result);
