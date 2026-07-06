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
 * REFERENCE SKELETON. This adapter mirrors eXeLearning core's canonical
 * "Preview Serving Contract" — exelearning/doc/development/preview-serving-contract.md
 * and its previewCspHeader() in exelearning/src/shared/security/previewSandbox.ts.
 * The Content-Security-Policy emitted here MUST stay BYTE-IDENTICAL to
 * previewCspHeader() (single line, directives joined by "; ", no trailing ";").
 * A drift check should assert equality against the core string.
 *
 * Route: GET /mod/exelearning/preview.php/{previewId}/<path...>
 *   `previewId` is a server-minted UUID capability. No auth cookie is required or
 *   consulted: the opaque preview iframe sends no SameSite cookie, so the route is
 *   gated purely on the unguessable previewId + idle TTL in the session store —
 *   the same cookieless model the PUBLISHED package uses via tokenpluginfile.php +
 *   get_user_key('core_files', ..., getremoteaddr(), ...) in view.php.
 *
 * NOTE (follow-up): the content-addressed session store and the authenticated
 * management API (POST /api/preview-session, /:id/manifest, /:id/blobs, DELETE)
 * are NOT implemented yet. The store lookup below is stubbed with a TODO. Full
 * store + management API + tests ship separately. See docs/preview-serving-contract.md.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Authless capability URL: never start a session or touch cookies. The previewId
// is the only credential; an auth cookie must not influence the response.
define('NO_MOODLE_COOKIES', true);

// @codingStandardsIgnoreLine — path is fixed relative to /mod/exelearning/.
require(__DIR__ . '/../../config.php');

/** UUID v-any 8-4-4-4-12, lowercase hex (the previewId shape). */
const EXELEARNING_PREVIEW_UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

/**
 * Scriptable document types that MUST carry the sandbox CSP. Not just text/html:
 * an author-supplied SVG runs its inline <script> same-origin when opened
 * top-level, so image/svg+xml et al. get the CSP too. nosniff does not help.
 *
 * @param string $mime
 * @return bool
 */
function exelearning_preview_is_scriptable(string $mime): bool {
    $mime = strtolower(trim($mime));
    return strpos($mime, 'text/html') === 0
        || $mime === 'image/svg+xml'
        || $mime === 'application/xml'
        || $mime === 'application/xhtml+xml';
}

/**
 * Hardening headers emitted on EVERY serving response, including 404s.
 * `Access-Control-Allow-Origin: *` is safe: the route is authless and cookieless,
 * so the wildcard grants nothing beyond the capability URL. NEVER pair it with
 * Access-Control-Allow-Credentials.
 *
 * The Permissions-Policy here is the preview contract's exact 4-feature value
 * (== previewPermissionsPolicy()); it deliberately does NOT reuse
 * player_iframe::permissions_policy(), which is the published-content SUPERSET.
 *
 * @return array<string,string>
 */
function exelearning_preview_base_headers(): array {
    return [
        'X-Content-Type-Options'      => 'nosniff',
        'Referrer-Policy'             => 'no-referrer',
        'Cache-Control'               => 'no-store',
        'Permissions-Policy'          => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Access-Control-Allow-Origin' => '*',
    ];
}

/**
 * The sandbox-first preview CSP. MUST be byte-identical to eXe core
 * previewCspHeader() (exelearning/src/shared/security/previewSandbox.ts). Only the
 * `sandbox` token list is reused from the published-content policy via
 * player_iframe::sandbox_tokens() (it already equals "allow-scripts allow-popups
 * allow-forms"); the rest is inlined here to preserve byte-identity.
 *
 * TODO(follow-up): promote this into a unit-tested
 * \mod_exelearning\local\ui\player_iframe::preview_content_security_policy(),
 * sibling to content_security_policy(), and add a drift check vs previewCspHeader().
 *
 * @return string
 */
function exelearning_preview_csp(): string {
    $sandbox = \mod_exelearning\local\ui\player_iframe::sandbox_tokens();
    return implode('; ', [
        'sandbox ' . $sandbox,
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "media-src 'self' data: blob: https:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com",
        "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com",
        "object-src 'none'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
}

/**
 * Emit the base headers, a 404 status and a plain-text body, then stop.
 * The hardening headers ride on 404s too (contract requirement).
 *
 * @return never
 */
function exelearning_preview_not_found(): void {
    foreach (exelearning_preview_base_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Not Found', true, 404);
    echo 'Not found';
    die;
}

// --- Request handling -------------------------------------------------------

// Slash arguments: PATH_INFO is "/{previewId}/{relpath}". get_file_argument()
// reads it robustly whether or not $CFG->slasharguments is enabled (same helper
// pluginfile.php / tokenpluginfile.php use).
$arg = ltrim((string) get_file_argument(), '/');
$slash = strpos($arg, '/');
$previewid = ($slash === false) ? $arg : substr($arg, 0, $slash);
$relpath   = ($slash === false) ? '' : substr($arg, $slash + 1);

// Invalid capability shape -> 404 (with base headers, no CSP).
if (!preg_match(EXELEARNING_PREVIEW_UUID_RE, $previewid)) {
    exelearning_preview_not_found();
}

// TODO(follow-up): real content-addressed store. get_for_serving() must:
//   - return null for an unknown or idle-expired session (=> 404),
//   - touch the idle-TTL clock on a hit,
//   - be gated ONLY on the previewId (no auth cookie).
// Reference home: \mod_exelearning\local\preview\session_store::get_for_serving().
$session = \mod_exelearning\local\preview\session_store::get_for_serving($previewid); // TODO: implement store.
if ($session === null) {
    exelearning_preview_not_found();
}

// Resolve from the ACTIVE manifest only. Exact-key lookup; a traversal or unknown
// path returns null (never touches the real filesystem) -> 404.
$file = $session->get_file($relpath); // TODO: returns {contents, filename, mimetype} or null.
if ($file === null) {
    exelearning_preview_not_found();
}

// Blobs are re-hashed server-side on upload; the store returns the verified MIME.
$mime = !empty($file->mimetype) ? $file->mimetype : mimeinfo('type', $file->filename);

foreach (exelearning_preview_base_headers() as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Type: ' . $mime);
if (exelearning_preview_is_scriptable($mime)) {
    header('Content-Security-Policy: ' . exelearning_preview_csp());
}

// TODO(follow-up): stream large blobs from the store rather than buffering.
echo $file->contents;
die;