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
 * Package iframe security mode and sandbox policy (DEC-80-01).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\ui;

/**
 * Centralises the package iframe sandbox policy, CSP and headers (DEC-80-01).
 *
 * The arbitrary author HTML/JS of an `.elpx` package is always embedded in view.php in a
 * sandboxed, opaque-origin iframe: the iframe drops allow-same-origin, so the package runs
 * in an opaque origin and cannot read or modify Moodle's DOM, cookies or session. SCORM
 * scoring is relayed to the parent over a validated postMessage bridge
 * (js/scorm_bridge_shim.js in the iframe, js/scorm_bridge_relay.js in the parent), and the
 * parent keeps the sesskey and performs the track.php request. The historical same-origin
 * "legacy" mode is no longer a production setting; it survives only as a dev-only escape hatch
 * (the EXELEARNING_UNSAFE_LEGACY_IFRAME constant) for environments whose service worker cannot
 * serve an opaque subframe (the php-wasm Moodle Playground), and defaults off.
 *
 * Centralised here so the policy is unit-testable without rendering view.php.
 * See research ADR DEC-80-01 (advances the Tier 2 roadmap of DEC-0-16).
 */
final class player_iframe {
    /** @var string Secure mode: opaque-origin iframe + postMessage SCORM bridge (the production mode). */
    public const MODE_SECURE = 'secure';

    /**
     * Legacy mode: same-origin iframe. NOT a production mode — reachable only through the dev-only
     * EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch, for environments whose service worker cannot
     * serve an opaque subframe (the php-wasm Moodle Playground).
     *
     * @var string
     */
    public const MODE_LEGACY = 'legacy';

    /** @var string Open embeds: promote any cross-origin https iframe (DEC-80-03). */
    public const EMBED_OPEN = 'open';

    /** @var string Strict embeds: only the maintained host allowlist (the default). */
    public const EMBED_STRICT = 'strict';

    /** @var string Strict CSP profile (default): no bare https: token-exfiltration channels. */
    public const CSP_STRICT = 'strict';

    /** @var string Compatible CSP profile: allows https: img/media/script for external assets. */
    public const CSP_COMPATIBLE = 'compatible';

    /**
     * Default host whitelist for external video embeds promoted to the parent page.
     *
     * In secure mode the package is opaque, so cross-origin players (YouTube/Vimeo)
     * load blank. Iframes whose src host is on this list are replaced by a placeholder
     * in the package (js/exe_embed_shim.js) and rendered as a real player by the parent
     * (js/exe_embed_relay.js). PDFs are handled separately (by .pdf extension) and need
     * no host entry.
     *
     * @var string[]
     */
    public const DEFAULT_EMBED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'player.vimeo.com',
        'vimeo.com',
        'www.dailymotion.com',
        'dailymotion.com',
        'geo.dailymotion.com',
        'mediateca.educa.madrid.org',
    ];

    /**
     * The package iframe mode. Secure (opaque origin) in production; the same-origin legacy mode is
     * reachable only through the dev-only EXELEARNING_UNSAFE_LEGACY_IFRAME escape hatch, which
     * defaults off and is never exposed as a Moodle setting.
     *
     * @return string self::MODE_SECURE, or self::MODE_LEGACY when the escape hatch is enabled.
     */
    public static function resolve_mode(): string {
        return self::is_unsafe_legacy() ? self::MODE_LEGACY : self::MODE_SECURE;
    }

    /**
     * Whether the dev-only same-origin escape hatch is enabled. Never a Moodle setting: it is the
     * EXELEARNING_UNSAFE_LEGACY_IFRAME PHP constant (defined in config.php) or the env var of the
     * same name, intended only for environments whose service worker cannot serve an opaque
     * subframe (the php-wasm Moodle Playground). Defaults off.
     *
     * @return bool True when the constant or env var is set and truthy.
     */
    public static function is_unsafe_legacy(): bool {
        if (defined('EXELEARNING_UNSAFE_LEGACY_IFRAME')) {
            return (bool) constant('EXELEARNING_UNSAFE_LEGACY_IFRAME');
        }
        $env = getenv('EXELEARNING_UNSAFE_LEGACY_IFRAME');
        return $env !== false && filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the configured mode isolates the package (opaque origin + bridge).
     *
     * @return bool True in secure mode.
     */
    public static function is_secure(): bool {
        return self::resolve_mode() === self::MODE_SECURE;
    }

    /**
     * Sandbox token list for the (always opaque) package iframe.
     *
     * Deliberately OMITS allow-same-origin (forcing an opaque origin so the package cannot
     * reach Moodle's DOM/cookies/session), allow-top-navigation (a package must never change
     * the parent URL), allow-modals (alert/confirm/prompt UX traps) and
     * allow-popups-to-escape-sandbox (an escaped popup would reopen at Moodle's real origin
     * without the sandbox). allow-scripts/allow-popups/allow-forms are kept because
     * eXeLearning v4 iDevices need jQuery + scripts, popups (interactive-video, hidden-image)
     * and forms (quick-questions, form, scrambled-list). See ADR DEC-80-01 / DEC-0-16 / AN-008.
     *
     * @return string Space-separated sandbox token list.
     */
    public static function sandbox_tokens(): string {
        if (!self::is_secure()) {
            // Dev-only escape hatch: same-origin so a service worker that only serves same-origin
            // documents (the php-wasm Playground) can load the package CSS/JS. Never used in production.
            return 'allow-same-origin allow-scripts allow-popups allow-forms';
        }
        return 'allow-scripts allow-popups allow-forms';
    }

    /**
     * Resolve the external-embed policy (DEC-80-03). Default 'strict' restricts promotion to
     * the maintained provider allowlist with canonical URL reconstruction; 'open' is an
     * explicit opt-in that promotes any cross-origin https iframe (the player is sandboxed +
     * cross-origin, so SOP isolates it from Moodle). Any unset or unrecognised value fails
     * safe to 'strict' (toward the more restrictive policy).
     *
     * @return string self::EMBED_OPEN or self::EMBED_STRICT.
     */
    public static function embed_mode(): string {
        $value = get_config('mod_exelearning', 'embedmode');
        return ($value === self::EMBED_OPEN) ? self::EMBED_OPEN : self::EMBED_STRICT;
    }

    /**
     * Resolve the content CSP profile. 'strict' (default) blocks bare https: exfiltration
     * channels (the per-user file token lives in the URL, so open img/media/script would let
     * author JS leak it). 'compatible' re-opens img/media/script to https: for content that
     * loads external author assets (third-party images, a MathJax CDN) — documented weaker.
     * Any unset or unrecognised value fails safe to 'strict'.
     *
     * @return string self::CSP_STRICT or self::CSP_COMPATIBLE.
     */
    public static function csp_profile(): string {
        $value = get_config('mod_exelearning', 'cspprofile');
        return ($value === self::CSP_COMPATIBLE) ? self::CSP_COMPATIBLE : self::CSP_STRICT;
    }

    /**
     * Normalized host whitelist for external video embeds (lowercase, de-duplicated).
     * Only consulted by the relay in 'strict' mode.
     *
     * @return string[]
     */
    public static function embed_whitelist(): array {
        $clean = [];
        foreach (self::DEFAULT_EMBED_HOSTS as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $clean[$host] = true;
            }
        }
        return array_keys($clean);
    }

    /**
     * Permissions-Policy header value for the embedded package (DEC-80-02).
     *
     * Denies hardware/sensor features the package never needs. `fullscreen` is
     * intentionally NOT denied: the iframe grants it via its allow= attribute and
     * iDevices use it. Emitted by exelearning_pluginfile() in secure mode.
     *
     * @return string The Permissions-Policy header value.
     */
    public static function permissions_policy(): string {
        return 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), '
            . 'bluetooth=(), hid=(), magnetometer=(), accelerometer=(), gyroscope=(), '
            . 'midi=(), display-capture=()';
    }

    /**
     * Content-Security-Policy header value for the embedded package (DEC-80-02).
     *
     * Strict (default): object-src/base-uri closed, frame-ancestors 'self', connect-src
     * limited to this site, and NO bare https: in script/img/media-src so the per-user file
     * token in the URL cannot be exfiltrated (e.g. via new Image().src). frame-src is limited
     * to the maintained providers. Compatible re-opens img/media/script (and frame-src) to
     * https: for content with external author images or a MathJax CDN — documented weaker.
     * The CSP-level `sandbox` keeps the document opaque even if the token URL is opened
     * outside the iframe (a new tab), mirroring the iframe sandbox tokens.
     *
     * @param string $siteorigin The scheme://host[:port] origin of this Moodle site.
     * @param string|null $profile self::CSP_STRICT/CSP_COMPATIBLE, or null to use csp_profile().
     * @return string The Content-Security-Policy header value.
     */
    public static function content_security_policy(string $siteorigin, ?string $profile = null): string {
        $profile = ($profile === self::CSP_COMPATIBLE || $profile === self::CSP_STRICT)
            ? $profile
            : self::csp_profile();
        if ($profile === self::CSP_COMPATIBLE) {
            $scriptsrc = "script-src 'self' $siteorigin 'unsafe-inline' 'unsafe-eval' https:; ";
            $imgsrc = "img-src 'self' $siteorigin data: blob: https:; ";
            $mediasrc = "media-src 'self' $siteorigin data: blob: https:; ";
            $framesrc = "frame-src 'self' $siteorigin https:; ";
        } else {
            $providers = 'https://www.youtube-nocookie.com https://player.vimeo.com '
                . 'https://www.dailymotion.com https://mediateca.educa.madrid.org';
            $scriptsrc = "script-src 'self' $siteorigin 'unsafe-inline' 'unsafe-eval'; ";
            $imgsrc = "img-src 'self' $siteorigin data: blob:; ";
            $mediasrc = "media-src 'self' $siteorigin data: blob:; ";
            $framesrc = "frame-src 'self' $siteorigin $providers; ";
        }
        $policy = "default-src 'self' $siteorigin; "
            . $scriptsrc
            . "style-src 'self' $siteorigin 'unsafe-inline'; "
            . $imgsrc
            . $mediasrc
            . "font-src 'self' $siteorigin data:; "
            . "connect-src 'self' $siteorigin; "
            . $framesrc
            . "object-src 'none'; base-uri 'none'; form-action 'self' $siteorigin; "
            . "frame-ancestors 'self'";
        // The CSP-level `sandbox` keeps the document opaque even if its URL is opened outside the
        // iframe. Omit it under the dev-only legacy escape hatch (the php-wasm Playground), which
        // needs same-origin rendering; the iframe sandbox attribute is relaxed in lockstep.
        if (self::is_secure()) {
            $policy .= "; sandbox allow-scripts allow-popups allow-forms";
        }
        return $policy;
    }

    /**
     * Defense-in-depth response headers for a served package file (DEC-80-02).
     *
     * In secure mode EVERY served file gets Referrer-Policy: no-referrer and
     * X-Content-Type-Options: nosniff. The per-user file token lives in the URL path, so
     * even a CSS/JS subresource that pulls a cross-origin image must not leak it via the
     * Referer header; and nosniff forces each file to be interpreted by its declared
     * Content-Type, so a package cannot smuggle executable HTML behind, e.g., a .pdf path
     * (the promoted PDF player is unsandboxed). The document-level Content-Security-Policy
     * and Permissions-Policy are added only for an HTML document (subresources ignore
     * them). The caller (exelearning_pluginfile) is just a header-emitting loop, since the
     * package always renders opaque. Keeping the decision and the values here makes them
     * unit-testable (the pluginfile callback that emits them exits via send_stored_file
     * and cannot be unit-tested directly).
     *
     * @param string $filename The served file name (only *.html(?) get CSP/Permissions-Policy).
     * @param string $wwwroot This Moodle site's $CFG->wwwroot (origin is derived from it).
     * @return array Map of header name => value (empty when no headers apply).
     */
    public static function content_headers(string $filename, string $wwwroot): array {
        // Apply to every served package file (the token rides in the URL for all of them).
        $headers = [
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
        // CSP + Permissions-Policy are document-level and only meaningful on an HTML page.
        if (preg_match('~\.html?$~i', $filename)) {
            $siteorigin = preg_replace('~^(https?://[^/]+).*~i', '$1', $wwwroot);
            $headers['Permissions-Policy'] = self::permissions_policy();
            $headers['Content-Security-Policy'] = self::content_security_policy($siteorigin);
        }
        return $headers;
    }
}
