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
 * Protocol + response layer for the editor preview serving contract v2.
 *
 * This class is the Moodle mirror of eXeLearning core's
 * src/services/preview-serving.ts. It owns everything that must stay
 * byte-identical to core so the two can never drift:
 *
 * - the sandbox-first Content-Security-Policy ({@see self::csp_header()},
 *   byte-identical to previewCspHeader());
 * - traversal-safe path normalization and MIME/charset resolution
 *   ({@see self::normalize_content_path()}, {@see self::content_type_for()});
 * - the tiered serving response (documents no-store / assets no-cache + ETag +
 *   Range / fixed private,max-age=31536000; the sandbox CSP on every scriptable
 *   type from ANY layer; hardening headers on every response including 404s);
 * - the management-endpoint helpers (create-session body, two-stage asset
 *   upload, revision publication) the authenticated endpoint delegates to.
 *
 * The two thin HTTP adapters — preview.php (serving) and
 * editor/preview_session.php (management) — carry no protocol logic of their
 * own; they parse the request, call the helpers here, and emit the result.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class serving {
    /** @var int Contract protocol version advertised by create-session. */
    const PROTOCOL_VERSION = 2;

    /** @var int Advertised upper bound (bytes) for one upload request; clients batch below it. */
    const RECOMMENDED_BATCH_BYTES = 67108864;

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
     * Returns null when no range was requested, ['start'=>int,'end'=>int] for a
     * satisfiable inclusive window, or the string 'unsatisfiable' for anything
     * else (multi-range, malformed, out of bounds) — those answer 416.
     *
     * @param string|null $value
     * @param int $totalsize
     * @return array{start:int,end:int}|string|null
     */
    public static function parse_range(?string $value, int $totalsize) {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($value), $match)) {
            return 'unsatisfiable';
        }
        $rawstart = $match[1];
        $rawend = $match[2];
        if ($rawstart === '' && $rawend === '') {
            return 'unsatisfiable';
        }
        if ($rawstart === '') {
            $suffix = (int) $rawend;
            if ($suffix === 0 || $totalsize === 0) {
                return 'unsatisfiable';
            }
            return ['start' => max(0, $totalsize - $suffix), 'end' => $totalsize - 1];
        }
        $start = (int) $rawstart;
        if ($start >= $totalsize) {
            return 'unsatisfiable';
        }
        if ($rawend === '') {
            return ['start' => $start, 'end' => $totalsize - 1];
        }
        $end = (int) $rawend;
        if ($end < $start) {
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
     * The contract's reference serving logic: three-layer resolution through the
     * session, then tiered response headers. The sandbox CSP rides on every
     * scriptable document type whatever layer resolved it.
     *
     * @param preview_session $session The active session (already looked up).
     * @param string $relpath The requested path (below the capability prefix).
     * @param array{ifnonematch?:string|null,range?:string|null} $reqheaders
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function serve(preview_session $session, string $relpath, array $reqheaders): array {
        $file = $session->get_file($relpath);
        if ($file === null) {
            return self::not_found();
        }

        $headers = self::base_headers();
        $headers['Content-Type'] = $file->contenttype;
        if ($file->scriptable) {
            $headers['Content-Security-Policy'] = self::csp_header();
        }

        if ($file->kind === 'document') {
            $headers['Cache-Control'] = 'no-store';
            return ['status' => 200, 'headers' => $headers, 'body' => $file->bytes];
        }

        if ($file->kind === 'fixed') {
            $headers['Cache-Control'] = 'private, max-age=31536000';
            return ['status' => 200, 'headers' => $headers, 'body' => $file->bytes];
        }

        // Session asset: revalidating cache tier with ETag + single-range support.
        $headers['Cache-Control'] = 'no-cache';
        $headers['ETag'] = '"' . $file->etag . '"';
        $headers['Accept-Ranges'] = 'bytes';

        if (self::if_none_match_matches($reqheaders['ifnonematch'] ?? null, $file->etag)) {
            return ['status' => 304, 'headers' => $headers, 'body' => ''];
        }

        $total = strlen($file->bytes);
        $range = self::parse_range($reqheaders['range'] ?? null, $total);
        if ($range === 'unsatisfiable') {
            $headers['Content-Range'] = 'bytes */' . $total;
            return ['status' => 416, 'headers' => $headers, 'body' => ''];
        }
        if (is_array($range)) {
            $body = substr($file->bytes, $range['start'], $range['end'] - $range['start'] + 1);
            $headers['Content-Range'] = 'bytes ' . $range['start'] . '-' . $range['end'] . '/' . $total;
            $headers['Content-Length'] = (string) strlen($body);
            return ['status' => 206, 'headers' => $headers, 'body' => $body];
        }
        return ['status' => 200, 'headers' => $headers, 'body' => $file->bytes];
    }

    // Management-endpoint helpers (authenticated, owner-scoped).

    /**
     * 201 body for create-session.
     *
     * @param int $owneruserid
     * @return array{status:int,body:array}
     */
    public static function create_session_response(int $owneruserid): array {
        $previewid = session_store::create_session($owneruserid);
        $limits = session_store::limits();
        return [
            'status' => 201,
            'body' => [
                'previewId' => $previewid,
                'protocolVersion' => self::PROTOCOL_VERSION,
                'revision' => 0,
                'limits' => [
                    'maxFilesPerSession' => $limits['maxfilespersession'],
                    'maxBytesPerSession' => $limits['maxbytespersession'],
                    'maxAssetBytes' => $limits['maxassetbytes'],
                    'recommendedBatchBytes' => self::RECOMMENDED_BATCH_BYTES,
                ],
            ],
        ];
    }

    /**
     * Handle an asset upload. The byte budget is enforced twice — on DECLARED
     * sizes before buffering and on ACTUAL bytes while buffering — so an
     * under-reported size can never amplify memory. Per-entry validation (key
     * shape, immutability, declared-vs-actual mismatch, per-asset cap) is
     * delegated to session_store::store_assets().
     *
     * @param preview_session $session
     * @param mixed $rawassets The 'assets' field: a JSON string, or a pre-decoded array.
     * @param string[] $files Raw file bytes, index-aligned with the assets array.
     * @return array{status:int,body:array}
     */
    public static function handle_assets_upload(preview_session $session, $rawassets, array $files): array {
        $entries = self::decode_json_field($rawassets);
        if (!is_array($entries) || !array_is_list($entries)) {
            return self::error(400, 'assets must be an array of { key, size } entries');
        }
        foreach ($entries as $entry) {
            if (
                !is_array($entry) || !isset($entry['key']) || !is_string($entry['key'])
                    || !isset($entry['size']) || !is_int($entry['size'])
            ) {
                return self::error(400, 'assets must be an array of { key, size } entries');
            }
        }
        if (count($files) !== count($entries)) {
            return self::error(400, 'assets and files must be index-aligned');
        }

        $limits = session_store::limits();
        $remaining = $limits['maxbytespersession'] - $session->session_bytes();
        $declared = 0;
        foreach ($entries as $entry) {
            $declared += $entry['size'];
            if ($declared > $remaining) {
                return self::error(413, 'Upload exceeds the preview session byte budget');
            }
        }

        $buffered = 0;
        $prepared = [];
        foreach ($files as $i => $bytes) {
            $buffered += strlen($bytes);
            if ($buffered > $remaining) {
                return self::error(413, 'Upload exceeds the preview session byte budget');
            }
            $prepared[] = [
                'key' => $entries[$i]['key'],
                'declaredsize' => $entries[$i]['size'],
                'bytes' => $bytes,
            ];
        }

        $result = session_store::store_assets($session, $prepared);
        return ['status' => 200, 'body' => $result];
    }

    /**
     * Handle a revision publication. All write bytes are buffered (with an
     * amplification guard) BEFORE session_store::apply_revision() runs its
     * revision re-check and single atomic publish, so a concurrent GET never
     * observes a partial revision.
     *
     * @param preview_session $session
     * @param mixed $rawrevision The 'revision' field: a JSON string, or a pre-decoded object.
     * @param string[] $files Raw file bytes, index-aligned with meta.writes.
     * @return array{status:int,body:array}
     */
    public static function handle_revision_upload(preview_session $session, $rawrevision, array $files): array {
        $meta = self::decode_json_field($rawrevision);
        if (!is_array($meta) || array_is_list($meta)) {
            return self::error(400, 'revision must be a JSON object');
        }
        if (
            !isset($meta['baseRevision']) || !is_int($meta['baseRevision'])
                || !isset($meta['nextRevision']) || !is_int($meta['nextRevision'])
        ) {
            return self::error(400, 'baseRevision and nextRevision must be integers');
        }
        $writes = $meta['writes'] ?? [];
        if (!is_array($writes) || !self::is_string_list($writes)) {
            return self::error(400, 'writes must be an array of paths');
        }
        $deletes = $meta['deletes'] ?? [];
        if (!is_array($deletes) || !self::is_string_list($deletes)) {
            return self::error(400, 'deletes must be an array of paths');
        }
        $assetrefs = $meta['assetRefs'] ?? [];
        $fixedrefs = $meta['fixedRefs'] ?? [];
        if (!self::is_string_map($assetrefs) || !self::is_string_map($fixedrefs)) {
            return self::error(400, 'assetRefs and fixedRefs must map paths to string ids');
        }
        if (count($files) !== count($writes)) {
            return self::error(400, 'revision writes and files must be index-aligned');
        }

        $limits = session_store::limits();
        $remainingfordocuments = $limits['maxbytespersession'] - $session->asset_bytes();
        $bufferedwrites = [];
        $buffered = 0;
        foreach ($files as $i => $bytes) {
            $buffered += strlen($bytes);
            if ($buffered > $remainingfordocuments) {
                return self::error(413, 'Revision exceeds the preview session byte budget');
            }
            $bufferedwrites[] = ['path' => $writes[$i], 'bytes' => $bytes];
        }

        $result = session_store::apply_revision($session, [
            'baserevision' => $meta['baseRevision'],
            'nextrevision' => $meta['nextRevision'],
            'deletes' => array_values($deletes),
            'assetrefs' => $assetrefs,
            'fixedrefs' => $fixedrefs,
        ], $bufferedwrites);

        if (isset($result['active'])) {
            return ['status' => 200, 'body' => ['revision' => $result['revision'], 'active' => true]];
        }
        if ($result['status'] === 409) {
            return ['status' => 409, 'body' => ['reason' => 'revision-conflict', 'currentRevision' => $result['currentrevision']]];
        }
        if ($result['status'] === 422 && $result['reason'] === 'missing-assets') {
            return ['status' => 422, 'body' => ['reason' => 'missing-assets', 'missing' => $result['missing']]];
        }
        if ($result['status'] === 422 && $result['reason'] === 'unknown-fixed-resources') {
            return ['status' => 422, 'body' => ['reason' => 'unknown-fixed-resources', 'resources' => $result['resources']]];
        }
        return self::error($result['status'], $result['message'] ?? 'Revision rejected');
    }

    /**
     * Validate a multipart files[] batch and extract its bytes in order, or fail
     * the WHOLE batch. A per-file PHP upload error must NEVER be silently mapped
     * to empty content: a document that trips php.ini upload_max_filesize (a large
     * search index, a media-heavy page) would otherwise publish as a 0-byte file
     * and the preview would show a blank page with no error reported. A size error
     * (UPLOAD_ERR_INI_SIZE / _FORM_SIZE) is a 413; any other upload error, or a
     * temp file that cannot be read, is a 400. The offending index/name is named.
     *
     * @param array<int,array{error:int,name:string,bytes:?string}> $parts
     * @return array{ok:true,files:string[]}|array{ok:false,status:int,error:string}
     */
    public static function collect_upload(array $parts): array {
        $files = [];
        foreach ($parts as $index => $part) {
            $error = isset($part['error']) ? (int) $part['error'] : UPLOAD_ERR_NO_FILE;
            $name = (string) ($part['name'] ?? '');
            $label = '#' . $index . ($name !== '' ? ' (' . $name . ')' : '');
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                return self::upload_failure(413, 'Uploaded file ' . $label . ' exceeds the server upload size limit');
            }
            if ($error !== UPLOAD_ERR_OK) {
                return self::upload_failure(400, 'Upload of file ' . $label . ' failed (error ' . $error . ')');
            }
            if (!is_string($part['bytes'] ?? null)) {
                return self::upload_failure(400, 'Uploaded file ' . $label . ' could not be read');
            }
            $files[] = $part['bytes'];
        }
        return ['ok' => true, 'files' => $files];
    }

    /**
     * Endpoint entry for an asset upload: validate the multipart batch (rejecting
     * any failed or oversized part BEFORE storing anything), then delegate.
     *
     * @param preview_session $session
     * @param mixed $rawassets
     * @param array<int,array{error:int,name:string,bytes:?string}> $parts
     * @return array{status:int,body:array}
     */
    public static function handle_assets_request(preview_session $session, $rawassets, array $parts): array {
        $collected = self::collect_upload($parts);
        if (!$collected['ok']) {
            return self::error($collected['status'], $collected['error']);
        }
        return self::handle_assets_upload($session, $rawassets, $collected['files']);
    }

    /**
     * Endpoint entry for a revision publish: validate the multipart batch
     * (rejecting any failed or oversized part BEFORE apply_revision, so a partial
     * upload never publishes a document as empty), then delegate.
     *
     * @param preview_session $session
     * @param mixed $rawrevision
     * @param array<int,array{error:int,name:string,bytes:?string}> $parts
     * @return array{status:int,body:array}
     */
    public static function handle_revision_request(preview_session $session, $rawrevision, array $parts): array {
        $collected = self::collect_upload($parts);
        if (!$collected['ok']) {
            return self::error($collected['status'], $collected['error']);
        }
        return self::handle_revision_upload($session, $rawrevision, $collected['files']);
    }

    /**
     * Build a collect_upload() failure result.
     *
     * @param int $status
     * @param string $message
     * @return array{ok:false,status:int,error:string}
     */
    private static function upload_failure(int $status, string $message): array {
        return ['ok' => false, 'status' => $status, 'error' => $message];
    }

    /**
     * Decode a management field that carries JSON. Multipart parsers deliver it
     * as a string; accept a pre-decoded value too.
     *
     * @param mixed $raw
     * @return mixed The decoded value, or null on malformed JSON.
     */
    private static function decode_json_field($raw) {
        if (!is_string($raw)) {
            return $raw;
        }
        return json_decode($raw, true);
    }

    /**
     * Whether every element of an array is a string.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_string_list($value): bool {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is an associative array mapping strings to strings.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_string_map($value): bool {
        if (!is_array($value)) {
            return false;
        }
        if (array_is_list($value) && $value !== []) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A uniform management-error body.
     *
     * @param int $status
     * @param string $message
     * @return array{status:int,body:array}
     */
    private static function error(int $status, string $message): array {
        return ['status' => $status, 'body' => ['success' => false, 'error' => $message]];
    }
}
