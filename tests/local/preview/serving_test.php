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

use advanced_testcase;

/**
 * Unit tests for the preview serving protocol/response helpers (contract v2).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\serving
 */
final class serving_test extends advanced_testcase {
    /**
     * The emitted CSP MUST be byte-identical to eXe core previewCspHeader():
     * a single line, directives joined by "; ", no trailing ";", sandbox first.
     */
    public function test_csp_header_is_byte_identical_to_core(): void {
        $expected = "sandbox allow-scripts allow-popups allow-forms; "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https:; "
            . "media-src 'self' data: blob: https:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
            . "child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
            . "object-src 'none'; "
            . "base-uri 'none'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'";
        $this->assertSame($expected, serving::csp_header());
        // The preview CSP is ALWAYS opaque: it must never carry allow-same-origin,
        // even if the published-content legacy escape hatch is enabled.
        putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME=1');
        try {
            $this->assertStringNotContainsString('allow-same-origin', serving::csp_header());
        } finally {
            putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
        }
    }

    /**
     * The Permissions-Policy is the 4-feature preview value.
     */
    public function test_permissions_policy(): void {
        $this->assertSame('camera=(), microphone=(), geolocation=(), payment=()', serving::permissions_policy());
    }

    /**
     * Base headers carry the hardening set and deliberately NOT Cache-Control
     * (that is tiered per resolution layer by the caller).
     */
    public function test_base_headers(): void {
        $headers = serving::base_headers();
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertArrayNotHasKey('Cache-Control', $headers);
    }

    /**
     * The sandbox CSP rides on every scriptable document type, not just HTML.
     */
    public function test_is_scriptable(): void {
        $this->assertTrue(serving::is_scriptable('text/html; charset=utf-8'));
        $this->assertTrue(serving::is_scriptable('image/svg+xml; charset=utf-8'));
        $this->assertTrue(serving::is_scriptable('application/xml'));
        $this->assertTrue(serving::is_scriptable('text/xml'));
        $this->assertTrue(serving::is_scriptable('application/xhtml+xml'));
        $this->assertFalse(serving::is_scriptable('text/css'));
        $this->assertFalse(serving::is_scriptable('image/png'));
        $this->assertFalse(serving::is_scriptable('application/javascript'));
    }

    /**
     * Content types mirror core: textual types (incl. svg/xml/js/json) get a
     * UTF-8 charset; binary types do not; unknown extensions fall back.
     */
    public function test_content_type_for(): void {
        $this->assertSame('text/html; charset=utf-8', serving::content_type_for('index.html'));
        $this->assertSame('image/svg+xml; charset=utf-8', serving::content_type_for('a/icon.svg'));
        $this->assertSame('text/css; charset=utf-8', serving::content_type_for('theme/content.css'));
        $this->assertSame('application/javascript; charset=utf-8', serving::content_type_for('libs/x.js'));
        $this->assertSame('application/json; charset=utf-8', serving::content_type_for('data.json'));
        $this->assertSame('application/xml; charset=utf-8', serving::content_type_for('feed.xml'));
        $this->assertSame('image/png', serving::content_type_for('img/photo.png'));
        $this->assertSame('video/mp4', serving::content_type_for('media/clip.mp4'));
        $this->assertSame('application/octet-stream', serving::content_type_for('blob.unknownext'));
    }

    /**
     * Path normalization is traversal-safe: it defaults to index.html, strips
     * leading slashes, resolves '.'/'..', and rejects escapes (literal and
     * percent-encoded), NUL bytes and malformed encoding.
     */
    public function test_normalize_content_path(): void {
        $this->assertSame('index.html', serving::normalize_content_path(''));
        $this->assertSame('foo/bar', serving::normalize_content_path('/foo/bar'));
        $this->assertSame('foo/bar', serving::normalize_content_path('///foo/bar'));
        $this->assertSame('b', serving::normalize_content_path('a/../b'));
        $this->assertSame('c', serving::normalize_content_path('a/b/../../c'));
        $this->assertSame('foo bar.png', serving::normalize_content_path('foo%20bar.png'));
        $this->assertSame('index.html', serving::normalize_content_path('?x=1'));

        $this->assertNull(serving::normalize_content_path('../secret'));
        $this->assertNull(serving::normalize_content_path('%2e%2e%2fsecret'));
        $this->assertNull(serving::normalize_content_path('..'));
        $this->assertNull(serving::normalize_content_path("with\0nul"));
        $this->assertNull(serving::normalize_content_path('bad%zz'));
    }

    /**
     * Byte-for-byte parity with core: JS decodeURIComponent throws (=> null =>
     * 404) on percent-sequences that decode to invalid UTF-8, so the PHP mirror
     * must reject them too — an overlong '/', a lone continuation byte, and a
     * lone surrogate — rather than passing the raw bytes through. Valid encoding
     * (ASCII and multibyte) still resolves.
     */
    public function test_normalize_content_path_rejects_invalid_utf8(): void {
        $this->assertNull(serving::normalize_content_path('%C0%AF'));
        $this->assertNull(serving::normalize_content_path('a%C0%AFb'));
        $this->assertNull(serving::normalize_content_path('%80'));
        $this->assertNull(serving::normalize_content_path('%ED%A0%80'));

        $this->assertSame('html/page-2.html', serving::normalize_content_path('html/page-2.html'));
        $this->assertSame("resum\u{00e9}.html", serving::normalize_content_path('resum%C3%A9.html'));
    }

    /**
     * The authless serving endpoint must suppress debug output: any notice or
     * warning printed on a $CFG->debugdisplay-on site would prepend garbage to
     * the byte-exact preview/asset body (and could defeat the headers/CSP
     * contract). preview.php defines NO_DEBUG_DISPLAY before requiring config.
     */
    public function test_serving_endpoint_suppresses_debug_output(): void {
        $source = file_get_contents(__DIR__ . '/../../../preview.php');
        $this->assertNotFalse($source);
        $definepos = strpos($source, "define('NO_DEBUG_DISPLAY', true);");
        $requirepos = strpos($source, "require(__DIR__ . '/../../config.php');");
        $this->assertNotFalse($definepos, 'preview.php must define NO_DEBUG_DISPLAY');
        $this->assertNotFalse($requirepos);
        $this->assertLessThan($requirepos, $definepos, 'NO_DEBUG_DISPLAY must be defined before config.php');
    }

    /**
     * A single-range Range header parses to an inclusive window or a suffix
     * window; a syntactically valid but unsatisfiable single range is the
     * 'unsatisfiable' sentinel (416); no header, a malformed header, a multi-range
     * set, or a non-"bytes" unit are all ignored (null ⇒ a normal 200 full body).
     */
    public function test_parse_range(): void {
        $this->assertNull(serving::parse_range(null, 10));
        $this->assertNull(serving::parse_range('', 10));
        $this->assertSame(['start' => 2, 'end' => 4], serving::parse_range('bytes=2-4', 10));
        $this->assertSame(['start' => 2, 'end' => 9], serving::parse_range('bytes=2-', 10));
        $this->assertSame(['start' => 7, 'end' => 9], serving::parse_range('bytes=-3', 10));
        $this->assertSame(['start' => 2, 'end' => 9], serving::parse_range('bytes=2-100', 10));

        // Syntactically valid single ranges that cannot be satisfied → 416:
        // first-byte-pos >= length, and a zero suffix.
        $this->assertSame('unsatisfiable', serving::parse_range('bytes=99-', 10));
        $this->assertSame('unsatisfiable', serving::parse_range('bytes=-0', 10));

        // Ignored (served as full 200): non-"bytes" unit, multi-range, garbage,
        // "bytes=-" (no bounds), and an inverted spec (last < first, RFC-invalid).
        $this->assertNull(serving::parse_range('bytes=5-2', 10));
        // Structural invalidity wins over satisfiability: an inverted spec whose
        // first-byte-pos is ALSO beyond the body is ignored (200), never a 416.
        $this->assertNull(serving::parse_range('bytes=15-2', 10));
        $this->assertNull(serving::parse_range('bytes=-', 10));
        $this->assertNull(serving::parse_range('kilobytes=1-2', 10));
        $this->assertNull(serving::parse_range('bytes=0-1,3-4', 10));
        $this->assertNull(serving::parse_range('bytes=abc', 10));
        $this->assertNull(serving::parse_range('bytes=1-2-3', 10));
    }

    /**
     * If-None-Match matches any listed (optionally weak) tag or the wildcard.
     */
    public function test_if_none_match_matches(): void {
        $this->assertFalse(serving::if_none_match_matches(null, 'key'));
        $this->assertTrue(serving::if_none_match_matches('"key"', 'key'));
        $this->assertTrue(serving::if_none_match_matches('W/"key"', 'key'));
        $this->assertTrue(serving::if_none_match_matches('*', 'key'));
        $this->assertTrue(serving::if_none_match_matches('"x", "key"', 'key'));
        $this->assertFalse(serving::if_none_match_matches('"other"', 'key'));
    }

    /**
     * The 404 response carries base headers + no-store + a plain-text body and
     * never a CSP.
     */
    public function test_not_found(): void {
        $response = serving::not_found();
        $this->assertSame(404, $response['status']);
        $this->assertSame('no-store', $response['headers']['Cache-Control']);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
        $this->assertSame('*', $response['headers']['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $response['headers']);
        $this->assertSame('Not found', $response['body']);
    }

    /**
     * The capability-path split flags the bare-root form ("/{previewId}" and
     * "/{previewId}/") so the endpoint can redirect it, and otherwise returns the
     * previewId + the relative path (a leading slash is optional).
     */
    public function test_parse_capability_path(): void {
        $id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000';

        $bare = serving::parse_capability_path('/' . $id);
        $this->assertSame($id, $bare['previewid']);
        $this->assertSame('', $bare['relpath']);
        $this->assertTrue($bare['bareroot']);
        $this->assertFalse($bare['trailingslash']);

        $bareslash = serving::parse_capability_path('/' . $id . '/');
        $this->assertSame($id, $bareslash['previewid']);
        $this->assertSame('', $bareslash['relpath']);
        $this->assertTrue($bareslash['bareroot']);
        $this->assertTrue($bareslash['trailingslash']);

        $withpath = serving::parse_capability_path('/' . $id . '/html/page-2.html');
        $this->assertSame($id, $withpath['previewid']);
        $this->assertSame('html/page-2.html', $withpath['relpath']);
        $this->assertFalse($withpath['bareroot']);
        $this->assertFalse($withpath['trailingslash']);

        // The leading slash is optional (get_file_argument may omit it).
        $noslash = serving::parse_capability_path($id . '/index.html');
        $this->assertSame($id, $noslash['previewid']);
        $this->assertSame('index.html', $noslash['relpath']);
        $this->assertFalse($noslash['bareroot']);
    }

    /**
     * The bare-root Location is RELATIVE and resolves to the session's index.html
     * against the request URL: "{previewId}/index.html" without a trailing slash,
     * just "index.html" with one.
     */
    public function test_bare_root_location(): void {
        $id = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000';
        $this->assertSame($id . '/index.html', serving::bare_root_location($id, false));
        $this->assertSame('index.html', serving::bare_root_location($id, true));
    }

    /**
     * The bare-root redirect is a 302 to index.html carrying the base hardening
     * headers + no-store, and never a CSP.
     */
    public function test_redirect_to_index(): void {
        $location = 'https://moodle.example/mod/exelearning/preview.php/'
            . 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000/index.html';
        $response = serving::redirect_to_index($location);
        $this->assertSame(302, $response['status']);
        $this->assertSame($location, $response['headers']['Location']);
        $this->assertSame('no-store', $response['headers']['Cache-Control']);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options']);
        $this->assertSame('*', $response['headers']['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $response['headers']);
        $this->assertSame('', $response['body']);
    }

    /**
     * The serving endpoint wires the bare-root redirect: preview.php splits the
     * capability path and, on the bare-root form, emits redirect_to_index rather
     * than serving document bytes (the entry-point script is out of coverage
     * scope, so this asserts the wiring at the source level, like the
     * NO_DEBUG_DISPLAY check above).
     */
    public function test_serving_endpoint_redirects_bare_root(): void {
        $source = file_get_contents(__DIR__ . '/../../../preview.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('parse_capability_path', $source);
        $this->assertStringContainsString("\$parsed['bareroot']", $source);
        $this->assertStringContainsString('redirect_to_index', $source);
    }

    /**
     * The editor bootstrap injects the normalized previewHttp activation block
     * pointing at this plugin's two endpoints, and gates it on the Playground so a
     * preview-capable editor build fails closed there (editor/index.php is an
     * entry-point script outside coverage scope, so this asserts the wiring at the
     * source level, like the preview.php checks above).
     */
    public function test_editor_bootstrap_injects_preview_http_config(): void {
        $source = file_get_contents(__DIR__ . '/../../../editor/index.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString("'previewHttp'", $source);
        $this->assertStringContainsString('/mod/exelearning/editor/preview_session.php', $source);
        $this->assertStringContainsString('/mod/exelearning/preview.php', $source);
        $this->assertStringContainsString("'managementQuery'", $source);
        // Fails closed under the Playground: the block is omitted there.
        $this->assertStringContainsString('MOODLE_PLAYGROUND', $source);
    }

    /**
     * The service-worker neutralization stub returns a faithful registration
     * shape (non-empty scope + a no-op addEventListener), not a bare
     * { scope: "" } that the editor's preview provider aborts on when it calls
     * registration.addEventListener("updatefound", …).
     */
    public function test_editor_bootstrap_sw_stub_is_faithful(): void {
        $source = file_get_contents(__DIR__ . '/../../../editor/index.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('fakeSwRegistration', $source);
        $this->assertStringContainsString('addEventListener: function() {}', $source);
        // The bare stub that aborted the preview provider must be gone.
        $this->assertStringNotContainsString('{ scope: "" }', $source);
    }
}
