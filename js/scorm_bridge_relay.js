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
 * Parent-side SCORM bridge relay for the secure (opaque-origin) package mode.
 *
 * Injected inline by view.php (secure mode only) so its message listener is in place
 * before the package iframe loads. It is the trusted half of the bridge: the iframe
 * runs in an opaque origin and CANNOT reach this page, so the only thing it can do is
 * postMessage buffered SCORM scores here. This relay validates every message and, for
 * accepted ones, performs the authenticated track.php request (keeping the sesskey on
 * this trusted side) plus a sendBeacon flush on pagehide.
 *
 * Validation (defence in depth — track.php re-validates and clamps server-side):
 *   - event.source === iframe.contentWindow (window identity, the primary anchor:
 *     no other window can forge it, and an opaque origin has no useful event.origin);
 *   - type === 'scorm' and action in the closed list {ready, track};
 *   - a per-view nonce on 'track' messages;
 *   - the payload shape (cmi is an object).
 * Unknown or invalid messages are ignored silently.
 *
 * Exposed two ways from a single body: window.exeScormBridge (browser bootstrap) and
 * module.exports (Vitest). See research ADR DEC-80-01.
 */
(function () {
    'use strict';

    /**
     * Whether a payload is a child -> parent score message (shape only).
     *
     * @param {*} data The event.data value.
     * @returns {boolean} True for {type:'scorm', action:'track', cmi:{...}}.
     */
    function isTrackMessage(data) {
        return !!data && data.type === 'scorm' && data.action === 'track'
            && !!data.cmi && typeof data.cmi === 'object';
    }

    /**
     * Whether a payload is the child's readiness announcement.
     *
     * @param {*} data The event.data value.
     * @returns {boolean} True for {type:'scorm', action:'ready'}.
     */
    function isReadyMessage(data) {
        return !!data && data.type === 'scorm' && data.action === 'ready';
    }

    /**
     * Whether a 'track' message should be accepted: correct shape AND matching nonce.
     * Pure, so it can be unit-tested without a DOM. The caller is still responsible
     * for the window-identity check (which cannot be expressed on data alone).
     *
     * @param {*} data The event.data value.
     * @param {string} expectednonce The per-view nonce handed to the iframe.
     * @returns {boolean} True when the message is a valid, authenticated track message.
     */
    function acceptTrack(data, expectednonce) {
        // The !!expectednonce guard means an empty/undefined expected nonce can never
        // authenticate: otherwise a forged message that simply omits the field would
        // satisfy undefined === undefined and collapse the nonce factor.
        return isTrackMessage(data) && !!expectednonce && data.exelearningBridge === expectednonce;
    }

    /**
     * Create a relay bound to a config + (injectable) environment.
     *
     * @param {Object} config {iframeid, cmid, trackurl, session, sesskey, nonce,
     *     blockedid, disableTracking}.
     * @param {Object} [deps] {document, window, fetch, sendBeacon} for testing.
     * @returns {Object} {init, onMessage, flushBeacon, postTrack, acceptTrack}.
     */
    function createRelay(config, deps) {
        config = config || {};
        deps = deps || {};
        var doc = deps.document || (typeof document !== 'undefined' ? document : null);
        var win = deps.window || (typeof window !== 'undefined' ? window : null);
        var fetchImpl = deps.fetch || (win && win.fetch ? win.fetch.bind(win) : null);
        var beacon = deps.sendBeacon
            || (win && win.navigator && win.navigator.sendBeacon
                ? win.navigator.sendBeacon.bind(win.navigator) : null);

        var iframeid = config.iframeid;
        var trackurl = config.trackurl;
        var cmid = config.cmid;
        var session = config.session;
        var sesskey = config.sesskey;
        var nonce = config.nonce;
        var blockedid = config.blockedid;
        // xAPI-primary (DEC-80-05): keep the bridge fully live (handshake, window.API,
        // watchdog) but forward NO SCORM score, because the package is graded
        // via xAPI. The decision lives here, on the trusted parent, not in the baked-in
        // shim — so it holds even for a package whose shim predates this flag.
        var disabletracking = config.disableTracking === true;
        var watchdogms = config.watchdogms || 8000;
        var gracems = config.gracems || 2500;
        var latest = null;
        var watchdog = null;
        var sawready = false;

        function iframe() { return doc ? doc.getElementById(iframeid) : null; }

        // Watchdog: if the in-iframe shim never announces 'ready' (e.g. an opaque-origin
        // iframe the environment cannot serve, like a PHP-WASM service-worker host that
        // does not control opaque subframes, so the token URL falls through to a 404),
        // reveal the "blocked by security configuration" notice instead of silently
        // degrading to the weaker same-origin mode (DEC-80-02).
        function showBlocked() {
            var b = (doc && blockedid) ? doc.getElementById(blockedid) : null;
            if (b) { b.style.display = ''; }
            var fr = iframe();
            if (fr) { fr.style.display = 'none'; }
        }
        // Decide between two timing signals so the notice never sits behind a long blank
        // wait. The iframe element fires 'load' even when its navigation ended in an error
        // page (e.g. the 404 above), so once it has loaded we only grant a short grace for
        // the shim to handshake; if 'load' never fires we still fall back to watchdogms.
        function armBlockedTimer(ms) {
            if (!win || !win.setTimeout) { return null; }
            return win.setTimeout(function () { if (!sawready) { showBlocked(); } }, ms);
        }
        function startWatchdog() {
            if (!win || !win.setTimeout || !blockedid) { return; }
            watchdog = armBlockedTimer(watchdogms);
            // Faster, load-driven path. The iframe may not be parsed yet when this relay
            // runs inline (it is injected before the iframe element), so attach now if it
            // exists, otherwise once the DOM is ready.
            var onload = function () { if (!sawready) { armBlockedTimer(gracems); } };
            var attach = function () {
                var fr = iframe();
                if (fr && fr.addEventListener) { fr.addEventListener('load', onload, false); return true; }
                return false;
            };
            if (!attach() && doc && doc.addEventListener) {
                doc.addEventListener('DOMContentLoaded', attach, false);
            }
        }
        function clearWatchdog() {
            sawready = true;
            if (watchdog && win && win.clearTimeout) { win.clearTimeout(watchdog); watchdog = null; }
        }

        function buildBody(cmi, itemscores) {
            // Mirror the legacy track.php payload, but identity (cmid, sesskey, mode)
            // lives on this trusted parent — only the CMI buffer and per-iDevice scores
            // come from the iframe. The sesskey travels in the body, never in the query
            // string (SEC-04): track.php validates it with require_body_sesskey().
            return JSON.stringify({
                id: cmid,
                session: session,
                sesskey: sesskey,
                cmi: cmi,
                itemscores: itemscores || {}
            });
        }

        function postTrack(cmi, itemscores) {
            var body = buildBody(cmi, itemscores);
            latest = body;
            if (!fetchImpl) { return; }
            try {
                fetchImpl(trackurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true
                }).catch(function () { /* parent retries on the next commit / pagehide beacon. */ });
            } catch (e) { /* ignore */ }
        }

        function flushBeacon() {
            if (!latest || !beacon) { return; }
            try { beacon(trackurl, new Blob([latest], { type: 'application/json' })); } catch (e) { /* ignore */ }
        }

        function onMessage(e) {
            var fr = iframe();
            // Window identity (primary anchor). The explicit contentWindow check rejects
            // the degenerate null === null match if the frame is present but not navigable.
            if (!fr || !fr.contentWindow || e.source !== fr.contentWindow) { return; }
            var data = e.data;
            if (isReadyMessage(data)) {
                clearWatchdog();   // The iframe is alive; secure mode rendered.
                try {
                    fr.contentWindow.postMessage({
                        type: 'scorm',
                        action: 'config',
                        nonce: nonce
                    }, '*');
                } catch (e2) { /* ignore */ }
                return;
            }
            if (!acceptTrack(data, nonce)) { return; }              // type + action + nonce + shape.
            // xAPI-primary: validate the message but suppress the SCORM POST. The shim
            // cannot reach track.php itself (opaque origin, no sesskey), so this is the
            // single authoritative drop point — no double grading with the xAPI channel.
            if (disabletracking) { return; }
            postTrack(data.cmi, data.itemscores);
        }

        function init() {
            if (win && win.addEventListener) {
                win.addEventListener('message', onMessage, false);
                win.addEventListener('pagehide', flushBeacon, false);
            }
            startWatchdog();
        }

        return {
            init: init,
            onMessage: onMessage,
            flushBeacon: flushBeacon,
            postTrack: postTrack,
            acceptTrack: acceptTrack,
            startWatchdog: startWatchdog,
            showBlocked: showBlocked
        };
    }

    /**
     * Bootstrap: create a relay from config and start listening.
     *
     * @param {Object} config See createRelay.
     * @returns {Object} The relay instance.
     */
    function init(config) {
        var relay = createRelay(config);
        relay.init();
        return relay;
    }

    var exp = {
        isTrackMessage: isTrackMessage,
        isReadyMessage: isReadyMessage,
        acceptTrack: acceptTrack,
        createRelay: createRelay,
        init: init
    };
    // Test runner (Vitest/Node) consumes module.exports.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser bootstrap (view.php) consumes window.exeScormBridge.
    if (typeof window !== 'undefined') { window.exeScormBridge = exp; }
})();
