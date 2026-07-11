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
 * Authless opaque HTTP preview serving endpoint (capability URL).
 *
 * The Moodle adapter of eXeLearning core's canonical preview serving contract v2
 * (docs/preview-serving-contract.md). It serves the three session layers —
 * generated documents, session assets, fixed installation resources — of the
 * opaque preview iframe over an unguessable capability URL.
 *
 * Route: GET /mod/exelearning/preview.php/{previewId}/<path...>
 *   `previewId` is a server-minted UUID capability. No auth cookie is required or
 *   consulted (NO_MOODLE_COOKIES): the opaque preview iframe sends no SameSite
 *   cookie, so the route is gated purely on the unguessable previewId + idle TTL
 *   in the session store — the cookieless model the published package uses via
 *   tokenpluginfile.php + get_user_key('core_files', ...) in view.php.
 *
 * All protocol logic (three-layer resolution, tiered Cache-Control, the sandbox
 * CSP byte-identical to core previewCspHeader(), ETag/Range) lives in
 * \mod_exelearning\local\preview\serving; the store lives in session_store. This
 * script only parses the capability URL and emits the computed response.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Authless capability URL: never start a session or touch cookies. The previewId
// is the only credential; an auth cookie must not influence the response.
define('NO_MOODLE_COOKIES', true);

// Raw byte-exact body: suppress any debug notice/warning that would otherwise be
// prepended to the served preview/asset bytes (and could defeat the headers/CSP
// contract) on a site with $CFG->debugdisplay enabled. The standard pattern for
// file-serving endpoints (tokenpluginfile / pluginfile-style scripts).
define('NO_DEBUG_DISPLAY', true);

// @codingStandardsIgnoreLine — path is fixed relative to /mod/exelearning/.
require(__DIR__ . '/../../config.php');

use mod_exelearning\local\preview\serving;
use mod_exelearning\local\preview\session_store;

/**
 * Emit a serving response ({status, headers, body}) and stop. The hardening
 * headers ride on every response, 404s included.
 *
 * @param array $response Serving response with 'status', 'headers' and 'body' keys.
 * @return never
 */
function exelearning_preview_send(array $response): void {
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    http_response_code($response['status']);
    echo $response['body'];
    die;
}

// Slash arguments: PATH_INFO is "/{previewId}/{relpath}". get_file_argument()
// reads it robustly whether or not $CFG->slasharguments is enabled (the same
// helper pluginfile.php / tokenpluginfile.php use).
$arg = ltrim((string) get_file_argument(), '/');
$slash = strpos($arg, '/');
$previewid = ($slash === false) ? $arg : substr($arg, 0, $slash);
$relpath = ($slash === false) ? '' : substr($arg, $slash + 1);

// Invalid capability shape -> 404 (with base headers, no CSP).
if (!preg_match(serving::UUID_RE, $previewid)) {
    exelearning_preview_send(serving::not_found());
}

// Cookieless capability lookup: unknown or idle-expired session -> 404.
$session = session_store::get_for_serving($previewid);
if ($session === null) {
    exelearning_preview_send(serving::not_found());
}

$response = serving::serve($session, $relpath, [
    'ifnonematch' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
    'range' => $_SERVER['HTTP_RANGE'] ?? null,
]);
exelearning_preview_send($response);
