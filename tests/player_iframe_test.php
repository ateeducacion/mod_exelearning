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

namespace mod_exelearning;

use advanced_testcase;
use mod_exelearning\local\ui\player_iframe;

/**
 * Tests for the package iframe security mode + sandbox policy (DEC-80-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\ui\player_iframe
 */
final class player_iframe_test extends advanced_testcase {
    /**
     * The default (no config set) must be the secure, isolated mode.
     */
    public function test_default_mode_is_secure(): void {
        $this->resetAfterTest();
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
        $this->assertTrue(player_iframe::is_secure());
    }

    /**
     * An unset/invalid config must fail safe to secure, never weakening isolation.
     */
    public function test_invalid_mode_falls_back_to_secure(): void {
        $this->resetAfterTest();
        set_config('iframemode', 'not-a-real-mode', 'mod_exelearning');
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
        $this->assertTrue(player_iframe::is_secure());
    }

    /**
     * Legacy mode was removed: a leftover iframemode=legacy config is ignored and the
     * package still renders secure (no silent downgrade to same-origin).
     */
    public function test_legacy_config_is_ignored(): void {
        $this->resetAfterTest();
        set_config('iframemode', 'legacy', 'mod_exelearning');
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
        $this->assertTrue(player_iframe::is_secure());
    }

    /**
     * The dev-only escape hatch defaults off: with no constant or env var set, the mode is secure.
     */
    public function test_unsafe_legacy_defaults_off(): void {
        $this->assertFalse(player_iframe::is_unsafe_legacy());
        $this->assertSame(player_iframe::MODE_SECURE, player_iframe::resolve_mode());
    }

    /**
     * The dev-only EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch (here through its env var) switches
     * to the same-origin legacy iframe and drops the CSP sandbox directive, so a service worker that
     * only serves same-origin documents (the php-wasm Playground) can load the package CSS/JS. This
     * path is never reachable from a Moodle setting.
     */
    public function test_unsafe_legacy_env_enables_same_origin(): void {
        putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME=1');
        try {
            $this->assertTrue(player_iframe::is_unsafe_legacy());
            $this->assertSame(player_iframe::MODE_LEGACY, player_iframe::resolve_mode());
            $this->assertFalse(player_iframe::is_secure());
            $this->assertStringContainsString('allow-same-origin', player_iframe::sandbox_tokens());
            $csp = player_iframe::content_security_policy('https://moodle.example.net');
            $this->assertStringNotContainsString('sandbox', $csp);
        } finally {
            putenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
        }
    }

    /**
     * The package always runs in an opaque origin: the sandbox tokens MUST drop
     * allow-same-origin and allow-popups-to-escape-sandbox, keep the scripts/popups/forms
     * the iDevices need, and never grant top navigation or modals.
     */
    public function test_secure_sandbox_tokens(): void {
        $tokens = player_iframe::sandbox_tokens();
        $list = explode(' ', $tokens);

        $this->assertContains('allow-scripts', $list);
        $this->assertContains('allow-popups', $list);
        $this->assertContains('allow-forms', $list);

        $this->assertNotContains('allow-same-origin', $list);
        $this->assertNotContains('allow-popups-to-escape-sandbox', $list);
        $this->assertNotContains('allow-top-navigation', $list);
        $this->assertNotContains('allow-top-navigation-by-user-activation', $list);
        $this->assertNotContains('allow-modals', $list);
    }

    /**
     * Permissions-Policy denies sensors/hardware but never fullscreen (the iframe
     * grants it and iDevices use it).
     */
    public function test_permissions_policy(): void {
        $pp = player_iframe::permissions_policy();
        $this->assertStringContainsString('camera=()', $pp);
        $this->assertStringContainsString('microphone=()', $pp);
        $this->assertStringContainsString('geolocation=()', $pp);
        $this->assertStringNotContainsString('fullscreen', $pp);
    }

    /**
     * The CSP hardens object/base/framing and pins connect-src to this site (so the
     * file token cannot be fetch-exfiltrated), while keeping the inline/eval scripts
     * eXeLearning needs. The passed site origin must appear in the source lists.
     */
    public function test_content_security_policy(): void {
        $origin = 'https://moodle.example.net';
        $csp = player_iframe::content_security_policy($origin);

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        // A sandbox directive keeps the document opaque even when opened outside the
        // iframe (e.g. the token URL opened in a new tab), so author JS cannot run as
        // Moodle's origin. Tokens mirror the secure iframe sandbox.
        $this->assertStringContainsString('sandbox allow-scripts allow-popups allow-forms', $csp);
        $this->assertStringContainsString("connect-src 'self' $origin;", $csp);
        // Inline + eval'd scripts are required by the eXeLearning engine.
        $this->assertStringContainsString("'unsafe-inline'", $csp);
        $this->assertStringContainsString("'unsafe-eval'", $csp);
        // Strict (default): NO bare `https:` in ANY source list, so the per-user file token
        // in the URL cannot be exfiltrated via img/script/media; only explicit origins like
        // https://host (followed by //) are allowed. frame-src is limited to the providers.
        $this->assertDoesNotMatchRegularExpression('~\bhttps:(?!//)~', $csp);
        $this->assertStringContainsString('https://www.youtube-nocookie.com', $csp);
        $this->assertStringContainsString('https://player.vimeo.com', $csp);
    }

    /**
     * content_headers() emits Referrer-Policy + nosniff on every secure-mode file and adds
     * the document-level CSP + Permissions-Policy for an HTML document, deriving the CSP
     * origin from $CFG->wwwroot (path stripped). The package always renders secure.
     */
    public function test_content_headers(): void {
        $this->resetAfterTest();

        // Secure (default) + HTML document: all four headers, origin stripped from wwwroot.
        $headers = player_iframe::content_headers('index.html', 'https://moodle.example.net/sub');
        $this->assertArrayHasKey('Content-Security-Policy', $headers);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertStringContainsString("'self' https://moodle.example.net;", $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('/sub', $headers['Content-Security-Policy']);

        // Secure + non-HTML subresource: the per-file token-protection headers still apply
        // (the token rides in the URL of CSS/JS too), but not the document-level CSP.
        $sub = player_iframe::content_headers('libs/base.css', 'https://moodle.example.net');
        $this->assertSame('no-referrer', $sub['Referrer-Policy']);
        $this->assertSame('nosniff', $sub['X-Content-Type-Options']);
        $this->assertArrayNotHasKey('Content-Security-Policy', $sub);
        $this->assertArrayNotHasKey('Permissions-Policy', $sub);

        // Legacy mode was removed: a leftover iframemode=legacy config still emits the secure
        // headers (no silent downgrade).
        set_config('iframemode', 'legacy', 'mod_exelearning');
        $legacy = player_iframe::content_headers('index.html', 'https://moodle.example.net');
        $this->assertArrayHasKey('Content-Security-Policy', $legacy);
        $this->assertSame('no-referrer', $legacy['Referrer-Policy']);
    }

    /**
     * The compatible CSP profile re-opens img/media to https: (documented weaker), while the
     * package iframe still renders opaque-origin (the CSP sandbox directive is unchanged).
     */
    public function test_csp_compatible_profile_allows_external_https(): void {
        $origin = 'https://moodle.example.net';
        $csp = player_iframe::content_security_policy($origin, player_iframe::CSP_COMPATIBLE);
        $this->assertMatchesRegularExpression('~img-src[^;]*\bhttps:(?!//)~', $csp);
        $this->assertMatchesRegularExpression('~media-src[^;]*\bhttps:(?!//)~', $csp);
        $this->assertStringContainsString('sandbox allow-scripts allow-popups allow-forms', $csp);
    }

    /**
     * csp_profile() defaults to strict; an unset or unrecognised value fails safe to strict.
     */
    public function test_csp_profile_defaults_strict(): void {
        $this->resetAfterTest();
        $this->assertSame(player_iframe::CSP_STRICT, player_iframe::csp_profile());
        set_config('cspprofile', 'bogus', 'mod_exelearning');
        $this->assertSame(player_iframe::CSP_STRICT, player_iframe::csp_profile());
        set_config('cspprofile', player_iframe::CSP_COMPATIBLE, 'mod_exelearning');
        $this->assertSame(player_iframe::CSP_COMPATIBLE, player_iframe::csp_profile());
    }

    /**
     * The external-embed policy defaults to strict; an unset or unrecognised value fails safe
     * to strict, and 'open' must be explicitly configured.
     */
    public function test_embed_mode_defaults_strict(): void {
        $this->resetAfterTest();
        $this->assertSame(player_iframe::EMBED_STRICT, player_iframe::embed_mode());
        set_config('embedmode', 'bogus', 'mod_exelearning');
        $this->assertSame(player_iframe::EMBED_STRICT, player_iframe::embed_mode());
        set_config('embedmode', player_iframe::EMBED_OPEN, 'mod_exelearning');
        $this->assertSame(player_iframe::EMBED_OPEN, player_iframe::embed_mode());
    }
}
