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
 * In-iframe SCORM bridge shim for the secure (opaque-origin) package mode.
 *
 * This script is baked into every extracted package (copied to libs/ and injected
 * at the top of <head> by \mod_exelearning\local\scorm\scorm_injector) and runs
 * INSIDE the package iframe. It self-activates only when the iframe is a sandboxed,
 * opaque-origin document (secure mode, view.php drops allow-same-origin); in the
 * legacy same-origin mode it stays dormant so eXeLearning's pipwerks walks up to
 * the window.API hosted by the Moodle parent, exactly as before.
 *
 * When active it:
 *   1. Installs an in-memory localStorage/sessionStorage polyfill, because an
 *      opaque-origin document throws SecurityError on real web storage and several
 *      shipped engine scripts (libs/exe_atools, exe_export.js, the checklist iDevice,
 *      edicuatex) touch it. The polyfill keeps them working for the session.
 *   2. Defines a local window.API (the SCORM 1.2 surface from js/scorm_tracker.js)
 *      whose buffered scores are posted to the Moodle parent over postMessage instead
 *      of being XHR'd here. The parent (js/scorm_bridge_relay.js) holds the sesskey
 *      and performs the authenticated track.php request; this iframe never sees it.
 *   3. Performs a handshake: it announces 'ready' and the parent replies with a nonce
 *      that authenticates subsequent score messages. Teacher-mode visibility is NOT
 *      handled here: the package hides teacher content by default and the host appends
 *      ?exe-teacher=1 to the iframe src to reveal the selector (read from the package's
 *      own location.search, which works even under the opaque origin).
 *
 * Exposed two ways from a single body: window.exeScormBridgeShim (browser, with an
 * auto-boot that is a no-op outside an opaque sandbox) and module.exports (Vitest).
 * See research ADR DEC-80-01.
 */
(function () {
    'use strict';

    /**
     * Build an in-memory Storage-like object (getItem/setItem/removeItem/clear/key
     * + length), used to shadow the native web storage that throws in an opaque
     * origin. Values are coerced to strings, matching the Storage contract.
     *
     * @returns {Object} A minimal in-memory Storage implementation.
     */
    function createMemoryStorage() {
        var store = {};
        var api = {
            getItem: function (k) {
                k = String(k);
                return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null;
            },
            setItem: function (k, v) { store[String(k)] = String(v); },
            removeItem: function (k) { delete store[String(k)]; },
            clear: function () { store = {}; },
            key: function (i) {
                var keys = Object.keys(store);
                return (i >= 0 && i < keys.length) ? keys[i] : null;
            }
        };
        Object.defineProperty(api, 'length', { get: function () { return Object.keys(store).length; } });
        return api;
    }

    /**
     * Detect whether the current window is a sandboxed, opaque-origin document
     * (secure mode). Opaque origins serialize to the string "null"; as a secondary
     * probe, web storage access throws a SecurityError (ONLY that — a QuotaExceededError
     * or a disabled-storage policy in a real same-origin iframe must not count). Either
     * signal means "activate".
     *
     * @param {Window} win The window to test (default: the global window).
     * @returns {boolean} True when running in an opaque sandbox.
     */
    function isSandboxedOpaque(win) {
        win = win || (typeof window !== 'undefined' ? window : null);
        if (!win) { return false; }
        try {
            if (win.origin === 'null' || (win.location && win.location.origin === 'null')) { return true; }
        } catch (e) { return true; }
        try {
            var probe = '__exeprobe__';
            win.localStorage.setItem(probe, '1');
            win.localStorage.removeItem(probe);
        } catch (e2) {
            // Only an opaque origin denies web storage with a SecurityError. A
            // QuotaExceededError (storage full) or a browser/policy that blocks first-party
            // storage in a REAL same-origin iframe also throws here; treating those as
            // "opaque" would activate the shim in legacy mode (where no parent relay listens),
            // so buffered scores would post into a postMessage void and be silently lost.
            return !!(e2 && e2.name === 'SecurityError');
        }
        return false;
    }

    /**
     * Replace win.localStorage/win.sessionStorage with in-memory polyfills so package
     * scripts that touch web storage do not throw in an opaque origin. Best effort:
     * if the property cannot be redefined, content storage access may still throw, but
     * grading (which never relies on web storage) is unaffected.
     *
     * @param {Window} win The window to patch.
     */
    function installStoragePolyfill(win) {
        var names = ['localStorage', 'sessionStorage'];
        for (var i = 0; i < names.length; i++) {
            var name = names[i];
            var mem = createMemoryStorage();
            try {
                Object.defineProperty(win, name, {
                    configurable: true,
                    get: (function (m) { return function () { return m; }; })(mem)
                });
            } catch (e) {
                try { win[name] = mem; } catch (e2) { /* give up; see docblock. */ }
            }
        }
    }

    /**
     * Whether a postMessage payload is a recognised parent -> child control message.
     *
     * @param {*} data The event.data value.
     * @returns {boolean} True for a {type:'scorm', action:'config'|'ack'} message.
     */
    function isParentMessage(data) {
        return !!data && data.type === 'scorm' && (data.action === 'config' || data.action === 'ack');
    }

    /**
     * Wire the local window.API to the Moodle parent over postMessage. Requires
     * win.exeScormTracker (js/scorm_tracker.js) to be loaded first.
     *
     * @param {Window} win The (opaque-origin) iframe window.
     * @returns {Object|null} Handles for testing, or null if the tracker is missing.
     */
    function activate(win) {
        var tracker = win.exeScormTracker;
        if (!tracker) { return null; }

        var parentwin = win.parent;
        var nonce = null;
        var ready = false;
        var queue = [];

        function postToParent(msg) {
            try {
                if (parentwin && parentwin.postMessage) { parentwin.postMessage(msg, '*'); }
            } catch (e) { /* ignore */ }
        }

        // Bridge transport handed to the shared tracker: forward buffered scores to
        // the parent. Identity (cmid, sesskey) lives in the parent; only the CMI
        // buffer + per-iDevice scores cross the bridge, and track.php re-validates
        // and clamps them server-side. Fire-and-forget; queued until the handshake
        // delivers the nonce.
        function transport(data) {
            var msg = {
                exelearningBridge: nonce,
                type: 'scorm',
                action: 'track',
                cmi: data.cmi,
                itemscores: data.itemscores
            };
            if (ready) { postToParent(msg); } else { queue.push(msg); }
            return true;
        }

        var instance = tracker.createScormApi({
            transport: transport,
            // Resolve per-iDevice objectids from THIS document (same frame), not the
            // parent's iframe element.
            getScoringDocument: function () { return win.document; },
            // No beforeunload flush here: postMessage is async and may not reach the
            // parent during unload. The parent's pagehide sendBeacon (relay) is the
            // reliable unload safety net instead.
            bindUnload: false
        });
        win.API = instance.api;

        function onMessage(e) {
            if (e.source !== parentwin) { return; }   // Only trust the hosting Moodle frame.
            var data = e.data;
            if (!isParentMessage(data)) { return; }
            if (data.action === 'config') {
                nonce = data.nonce;
                ready = true;
                while (queue.length) {
                    var m = queue.shift();
                    m.exelearningBridge = nonce;
                    postToParent(m);
                }
            }
        }

        if (win.addEventListener) { win.addEventListener('message', onMessage, false); }
        // Announce readiness; the parent replies with the nonce.
        postToParent({ exelearningBridge: null, type: 'scorm', action: 'ready' });

        return {
            api: instance.api,
            transport: transport,
            onMessage: onMessage
        };
    }

    /**
     * Boot the shim: activate only inside an opaque sandbox. No-op otherwise (legacy
     * same-origin mode, or any non-sandboxed context such as the test runner).
     *
     * @param {Window} win The window to boot in (default: the global window).
     * @returns {Object|null} The activate() handles when activated, else null.
     */
    function boot(win) {
        win = win || (typeof window !== 'undefined' ? window : null);
        if (!win || !isSandboxedOpaque(win)) { return null; }
        installStoragePolyfill(win);
        return activate(win);
    }

    var exp = {
        createMemoryStorage: createMemoryStorage,
        isSandboxedOpaque: isSandboxedOpaque,
        installStoragePolyfill: installStoragePolyfill,
        isParentMessage: isParentMessage,
        activate: activate,
        boot: boot
    };
    // Test runner (Vitest/Node) consumes module.exports.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser: expose for inspection and auto-boot (no-op outside an opaque sandbox).
    if (typeof window !== 'undefined') {
        window.exeScormBridgeShim = exp;
        boot(window);
    }
})();
