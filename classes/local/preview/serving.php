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

namespace mod_exelearning\local\preview;

/**
 * Response layer for the opaque editor preview.
 *
 * This class is the Moodle mirror of eXeLearning core's
 * src/services/preview-serving.ts. It owns everything that must stay
 * byte-identical to core so the two can never drift:
 *
 * - the sandbox-first Content-Security-Policy ({@see self::csp_header()},
 *   byte-identical to previewCspHeader());
 * - traversal-safe path normalization and MIME/charset resolution
 *   ({@see self::normalize_content_path()}, {@see self::content_type_for()});
 * - the tiered serving response (scriptable documents no-store, everything else
 *   no-cache + ETag + Range; the sandbox CSP on every scriptable type;
 *   hardening headers on every response including 404s).
 *
 * The layered protocol-v2 helpers that used to live here — create-session,
 * two-stage asset upload, revision publication — went with the store they
 * served: the editor sends one whole snapshot per refresh, so there is no
 * protocol left to negotiate.
 *
 * preview.php is a thin HTTP adapter over this: it parses the capability URL,
 * calls {@see self::serve()} and emits the result.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class serving {
    /**
     * previewId capability shape (server-minted UUID). Case-insensitive to match
     * core UUID_RE; \core\uuid::generate() emits lowercase.
     *
     * @var string
     */
    const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Content-map MIME table, byte-identical to core src/utils/mime-types.ts.
     *
     * @var array<string,string>
     */
    const MIME_TYPES = [
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'html' => 'text/html',
        'htm' => 'text/html',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];

    /**
     * The sandbox-first preview CSP. MUST stay byte-identical to eXe core
     * previewCspHeader() (src/shared/security/previewSandbox.ts): a single line,
     * directives joined by "; ", no trailing ";". The sandbox tokens are hardcoded
     * to allow-scripts allow-popups allow-forms (== PREVIEW_SANDBOX): the preview
     * is ALWAYS opaque and must NOT inherit the published-content escape hatch
     * (player_iframe::sandbox_tokens() can add allow-same-origin under the dev-only
     * legacy hatch, which would defeat opacity — never reuse it here).
     *
     * @return string
     */
    public static function csp_header(): string {
        return implode('; ', [
            'sandbox allow-scripts allow-popups allow-forms',
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
     * Permissions-Policy value for every preview response (== previewPermissionsPolicy();
     * the 4-feature preview value, NOT the published-content superset).
     *
     * @return string
     */
    public static function permissions_policy(): string {
        return implode(', ', ['camera=()', 'microphone=()', 'geolocation=()', 'payment=()']);
    }

    /**
     * Hardening headers applied to EVERY serving response, 404s included.
     * Cache-Control is NOT here — it is tiered per resolution layer by the caller.
     * Access-Control-Allow-Origin: * is safe on this authless, cookieless route
     * (never pair it with Access-Control-Allow-Credentials).
     *
     * @return array<string,string>
     */
    public static function base_headers(): array {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => self::permissions_policy(),
            'Access-Control-Allow-Origin' => '*',
        ];
    }

    /**
     * Scriptable document types that MUST carry the sandbox CSP. Not just
     * text/html: an author-supplied image/svg+xml (or XML) runs its inline
     * <script> same-origin when opened top-level, so those get the CSP too
     * (nosniff does not help — SVG is already a scriptable document type).
     *
     * @param string $mime
     * @return bool
     */
    public static function is_scriptable(string $mime): bool {
        $base = strtolower(trim(explode(';', $mime)[0]));
        return $base === 'text/html'
            || $base === 'image/svg+xml'
            || $base === 'application/xml'
            || $base === 'text/xml'
            || $base === 'application/xhtml+xml';
    }

    /**
     * Resolve the Content-Type for a content-map path, appending a UTF-8 charset
     * to textual types so responses paired with nosniff stay strict and readable.
     * Byte-identical to core contentTypeFor().
     *
     * @param string $path Normalized served path.
     * @return string
     */
    public static function content_type_for(string $path): string {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contenttype = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $istextual = strpos($contenttype, 'text/') === 0
            || in_array($ext, ['js', 'mjs', 'json', 'svg', 'xml'], true);
        if ($istextual && strpos($contenttype, 'charset') === false) {
            $contenttype .= '; charset=utf-8';
        }
        return $contenttype;
    }

    /**
     * Normalize a requested relative path against a content-map root. Returns a
     * safe, root-relative POSIX path, or null when the request escapes the root
     * (traversal, encoded or literal), contains a NUL byte, or carries malformed
     * percent-encoding. Byte-for-byte behaviour of core normalizeContentPath().
     *
     * @param string $relpath
     * @return string|null
     */
    public static function normalize_content_path(string $relpath): ?string {
        $path = explode('?', $relpath, 2)[0];
        $path = explode('#', $path, 2)[0];
        // JS decodeURIComponent throws on malformed percent-encoding; mirror that.
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) {
            return null;
        }
        $path = rawurldecode($path);
        // JS decodeURIComponent also throws on percent-sequences that decode to
        // invalid UTF-8 (overlong forms like %C0%AF, lone continuation bytes,
        // lone surrogates); reject those too so an overlong-encoded separator
        // cannot slip through as raw bytes.
        if (mb_check_encoding($path, 'UTF-8') === false) {
            return null;
        }
        // Backslashes are literal (not separators) in the content map; a NUL is invalid.
        if (strpos($path, "\0") !== false) {
            return null;
        }
        $path = preg_replace('#^/+#', '', $path);
        if ($path === '') {
            $path = 'index.html';
        }
        $norm = self::posix_normalize($path);
        if ($norm === '..' || strncmp($norm, '../', 3) === 0 || strncmp($norm, '/', 1) === 0) {
            return null;
        }
        return $norm;
    }

    /**
     * Collapse '.' and '..' segments without touching the filesystem (a PHP
     * equivalent of Node's path.posix.normalize for the cases we accept).
     *
     * @param string $path
     * @return string
     */
    private static function posix_normalize(string $path): string {
        $isabsolute = strncmp($path, '/', 1) === 0;
        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!empty($stack) && end($stack) !== '..') {
                    array_pop($stack);
                } else if (!$isabsolute) {
                    $stack[] = '..';
                }
                continue;
            }
            $stack[] = $segment;
        }
        $result = implode('/', $stack);
        if ($isabsolute) {
            $result = '/' . $result;
        }
        if ($result === '') {
            $result = $isabsolute ? '/' : '.';
        }
        return $result;
    }

    /**
     * Parse a single-range Range header against a body of $totalsize bytes.
     *
     * Returns null when no usable range applies — no header at all, OR a header
     * that is ignored per RFC 9110: a non-"bytes" unit, a multi-range set,
     * unparseable garbage, or an invalid spec whose last-byte-pos is below its
     * first-byte-pos (e.g. bytes=5-2). All of those are served as a normal 200
     * full response (an ignored Range is NOT a 416). Returns ['start'=>int,
     * 'end'=>int] for a satisfiable inclusive window (206), or the string
     * 'unsatisfiable' (416) for a syntactically VALID single range that cannot be
     * satisfied against this body: first-byte-pos >= length, or a zero/empty suffix.
     *
     * @param string|null $value
     * @param int $totalsize
     * @return array{start:int,end:int}|string|null
     */
    public static function parse_range(?string $value, int $totalsize) {
        if ($value === null || $value === '') {
            return null;
        }
        // Malformed grammar, a multi-range set (comma), or a non-"bytes" unit is
        // ignored: RFC 7233 says an unparsable Range must be treated as absent
        // (serve the full 200), never as unsatisfiable.
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($value), $match)) {
            return null;
        }
        $rawstart = $match[1];
        $rawend = $match[2];
        // A "bytes=-" header carries neither a position nor a suffix: malformed, ignore.
        if ($rawstart === '' && $rawend === '') {
            return null;
        }
        if ($rawstart === '') {
            $suffix = (int) $rawend;
            if ($suffix === 0 || $totalsize === 0) {
                return 'unsatisfiable';
            }
            return ['start' => max(0, $totalsize - $suffix), 'end' => $totalsize - 1];
        }
        $start = (int) $rawstart;
        if ($rawend === '') {
            // Open-ended range: satisfiable iff the first-byte-pos is within the body.
            return ($start >= $totalsize) ? 'unsatisfiable' : ['start' => $start, 'end' => $totalsize - 1];
        }
        $end = (int) $rawend;
        // Structural validity is checked BEFORE satisfiability: an inverted spec
        // (last-byte-pos < first-byte-pos, e.g. bytes=15-2) is an invalid
        // byte-range-spec per RFC 9110, so the header is ignored (200 full) — even
        // when the first-byte-pos is also beyond the body, which alone would 416.
        if ($end < $start) {
            return null;
        }
        if ($start >= $totalsize) {
            return 'unsatisfiable';
        }
        return ['start' => $start, 'end' => min($end, $totalsize - 1)];
    }

    /**
     * Loose If-None-Match evaluation: any listed entity tag (or *) matches.
     *
     * @param string|null $headervalue
     * @param string $etag
     * @return bool
     */
    public static function if_none_match_matches(?string $headervalue, string $etag): bool {
        if ($headervalue === null || $headervalue === '') {
            return false;
        }
        foreach (explode(',', $headervalue) as $candidate) {
            $cleaned = preg_replace('/^W\//i', '', trim($candidate));
            $cleaned = trim($cleaned, '"');
            if ($cleaned === '*' || $cleaned === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * The 404 response (base hardening headers + no-store), returned for an
     * invalid capability, an unknown/expired session, and an unresolved path.
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function not_found(): array {
        $headers = self::base_headers();
        $headers['Cache-Control'] = 'no-store';
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        return ['status' => 404, 'headers' => $headers, 'body' => 'Not found'];
    }

    /**
     * Split the capability URL tail "/{previewId}/{relpath}" into its parts. The
     * bare form ("/{previewId}" or "/{previewId}/", i.e. an empty relpath) is
     * flagged so the serving endpoint can 302-redirect it to index.html rather
     * than serve document bytes from the bare URL (relative resources in the
     * document resolve against the session directory, not the bare capability).
     *
     * @param string $arg The raw slash-argument tail (a leading slash is optional).
     * @return array{previewid:string,relpath:string,bareroot:bool,trailingslash:bool}
     */
    public static function parse_capability_path(string $arg): array {
        $arg = ltrim($arg, '/');
        $trailingslash = ($arg !== '' && substr($arg, -1) === '/');
        $slash = strpos($arg, '/');
        $previewid = ($slash === false) ? $arg : substr($arg, 0, $slash);
        $relpath = ($slash === false) ? '' : substr($arg, $slash + 1);
        return [
            'previewid' => $previewid,
            'relpath' => $relpath,
            'bareroot' => ($relpath === ''),
            'trailingslash' => $trailingslash,
        ];
    }

    /**
     * The RELATIVE Location for the bare-root 302, resolved by the browser
     * against the current request URL (so it stays correct under any BASE_PATH or
     * the app:// origin — never hardcode the host). Without a trailing slash the
     * previewId is the last path segment, so the target is `{previewId}/index.html`;
     * with a trailing slash the request is already the session directory, so the
     * target is just `index.html`.
     *
     * @param string $previewid
     * @param bool $trailingslash Whether the requested bare URL ended with a slash.
     * @return string
     */
    public static function bare_root_location(string $previewid, bool $trailingslash): string {
        return $trailingslash ? 'index.html' : $previewid . '/index.html';
    }

    /**
     * The 302 response for the bare capability root: redirect to the session's
     * index.html so the opaque iframe's base URL is the session directory and no
     * document bytes are ever served from the bare URL. Carries the base
     * hardening headers + no-store, like every other serving response, and never
     * a CSP (a redirect has no scriptable body).
     *
     * @param string $location Absolute URL of the session's index.html.
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function redirect_to_index(string $location): array {
        $headers = self::base_headers();
        $headers['Cache-Control'] = 'no-store';
        $headers['Location'] = $location;
        return ['status' => 302, 'headers' => $headers, 'body' => ''];
    }
    /**
     * Serve one file out of a snapshot.
     *
     * The sandbox CSP rides on every scriptable type. A document is rewritten on
     * every opaque refresh so it is never cached; everything else revalidates
     * cheaply and supports Range, which is what makes a video inside the snapshot
     * seekable.
     *
     * @param string $contentdir Absolute snapshot content directory.
     * @param string $relpath The requested path (below the capability prefix).
     * @param array $reqheaders Optional 'ifnonematch' and 'range' request-header values.
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function serve(string $contentdir, string $relpath, array $reqheaders): array {
        $file = self::resolve_file($contentdir, $relpath);
        if ($file === null) {
            return self::not_found();
        }
        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return self::not_found();
        }
        $mime = self::content_type_for($file);
        $scriptable = self::is_scriptable($mime);

        $headers = self::base_headers();
        $headers['Content-Type'] = $mime;
        if ($scriptable) {
            $headers['Content-Security-Policy'] = self::csp_header();
            $headers['Cache-Control'] = 'no-store';
            return ['status' => 200, 'headers' => $headers, 'body' => $bytes];
        }

        $etag = md5($bytes);
        $headers['Cache-Control'] = 'no-cache';
        $headers['ETag'] = '"' . $etag . '"';
        $headers['Accept-Ranges'] = 'bytes';
        if (self::if_none_match_matches($reqheaders['ifnonematch'] ?? null, $etag)) {
            return ['status' => 304, 'headers' => $headers, 'body' => ''];
        }

        $total = strlen($bytes);
        $range = self::parse_range($reqheaders['range'] ?? null, $total);
        if ($range === 'unsatisfiable') {
            $headers['Content-Range'] = 'bytes */' . $total;
            return ['status' => 416, 'headers' => $headers, 'body' => ''];
        }
        if (is_array($range)) {
            $body = substr($bytes, $range['start'], $range['end'] - $range['start'] + 1);
            $headers['Content-Range'] = 'bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total;
            $headers['Content-Length'] = (string) strlen($body);
            return ['status' => 206, 'headers' => $headers, 'body' => $body];
        }
        return ['status' => 200, 'headers' => $headers, 'body' => $bytes];
    }

    /**
     * Resolve a request path to a real file inside the snapshot.
     *
     * normalize_content_path() already refuses traversal, but the result is
     * joined to a real directory here and the resolved path confirmed to sit
     * under the snapshot root, so a symlink cannot aim the response outside it.
     *
     * @param string $contentdir Absolute snapshot content directory.
     * @param string $relpath Requested path.
     * @return string|null Absolute file path, or null when it does not resolve.
     */
    private static function resolve_file(string $contentdir, string $relpath): ?string {
        $norm = self::normalize_content_path($relpath);
        if ($norm === null) {
            return null;
        }
        $root = realpath($contentdir);
        $real = realpath($contentdir . '/' . $norm);
        if ($root === false || $real === false || !is_file($real)) {
            return null;
        }
        if (strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
            return null;
        }
        return $real;
    }
}
