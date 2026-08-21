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
 * Serves the snapshot the editor uploaded for its opaque preview, over an
 * unguessable capability URL (docs/preview-serving-contract.md).
 *
 * Route: GET /mod/exelearning/preview.php/{previewId}/<path...>
 *   `previewId` is a server-minted UUID capability. No auth cookie is required or
 *   consulted (NO_MOODLE_COOKIES): the opaque preview iframe sends no SameSite
 *   cookie, so the route is gated purely on the unguessable previewId + idle TTL
 *   in the session store — the cookieless model the published package uses via
 *   tokenpluginfile.php + get_user_key('core_files', ...) in view.php.
 *
 * All response logic (tiered Cache-Control, the sandbox
 * CSP byte-identical to core previewCspHeader(), ETag/Range) lives in
 * \mod_exelearning\local\preview\serving; the store lives in snapshot_store. This
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
use mod_exelearning\local\preview\snapshot_store;

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
$parsed = serving::parse_capability_path((string) get_file_argument());
$previewid = $parsed['previewid'];
$relpath = $parsed['relpath'];

// Invalid capability shape -> 404 (with base headers, no CSP).
if (!preg_match(serving::UUID_RE, $previewid)) {
    exelearning_preview_send(serving::not_found());
}

// Bare capability root ("/{previewId}" or "/{previewId}/") -> 302 to index.html,
// so the opaque iframe's base URL is the session directory and document bytes are
// never served from the bare URL. The Location is RELATIVE (resolved against the
// request URL) so it is correct under any $CFG->wwwroot subdirectory. Pure URL
// canonicalization, done before the session lookup; the redirected request
// resolves the session and resource.
if ($parsed['bareroot']) {
    $location = serving::bare_root_location($previewid, $parsed['trailingslash']);
    exelearning_preview_send(serving::redirect_to_index($location));
}

// Cookieless capability lookup: unknown or idle-expired snapshot -> 404. The
// lookup also pushes the idle clock back, so a preview in use never expires
// under the author.
$contentdir = snapshot_store::get_content_dir($previewid);
if ($contentdir === null) {
    exelearning_preview_send(serving::not_found());
}

$response = serving::serve($contentdir, $relpath, [
    'ifnonematch' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
    'range' => $_SERVER['HTTP_RANGE'] ?? null,
]);
exelearning_preview_send($response);
