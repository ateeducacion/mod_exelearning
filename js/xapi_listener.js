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
 * xAPI statement listener for the eXeLearning player (DEC-0064, single source of truth).
 *
 * For packages that bundle the upstream xAPI emitter (eXeLearning PR #1867), grading
 * flows through xAPI instead of the SCORM bridge: the emitter posts
 * `{type:'exe-xapi-statement', statement}` to the host via postMessage; this listener,
 * running in the parent page (view.php), validates the sender origin, de-duplicates by
 * statement.id and forwards each statement to xapi_track.php.
 *
 * Like js/scorm_tracker.js this is grade-critical client code, so it is a plain inline
 * script (NOT an AMD module): view.php reads this file and injects it inline, and the
 * same file is unit-tested with Vitest (tests/js/xapi_listener.test.js). It is exposed
 * two ways from a single body: window.exeXapiListener for the browser bootstrap, and
 * module.exports for the test runner.
 *
 * Security (RIE-013): the only trusted transport is a postMessage whose event.origin
 * equals the host origin (the iframe is served same-origin via pluginfile.php). A '*'
 * or mismatched origin is dropped. The statement actor is never read here — the server
 * attributes the grade to the authenticated user.
 */
(function () {
    'use strict';

    /** @const {string} The envelope type the eXeLearning emitter uses. */
    var MESSAGE_TYPE = 'exe-xapi-statement';

    /**
     * Whether a postMessage payload is an eXeLearning xAPI statement envelope.
     *
     * @param {*} data The event.data value.
     * @returns {boolean}
     */
    function isStatementMessage(data) {
        return !!data && typeof data === 'object' && data.type === MESSAGE_TYPE
            && !!data.statement && typeof data.statement === 'object';
    }

    /**
     * Whether a message origin is the trusted host origin. Rejects '' and the '*'
     * wildcard (defense in depth even though the emitter strips PII when broadcasting
     * to '*'); only an exact match to the expected host origin is accepted (RIE-013).
     *
     * @param {string} origin  event.origin of the message.
     * @param {string} allowed The expected host origin.
     * @returns {boolean}
     */
    function isTrustedOrigin(origin, allowed) {
        return typeof origin === 'string' && origin !== '' && origin !== '*'
            && typeof allowed === 'string' && allowed !== '' && origin === allowed;
    }

    /**
     * Serialize the xapi_track.php POST body.
     *
     * The sesskey travels here rather than in the endpoint's query string (SEC-04):
     * a URL parameter is recorded verbatim by web-server access logs, reverse proxies
     * and diagnostic tooling, while a POST body is not. xapi_track.php confirms it with
     * an explicit confirm_sesskey() after decoding.
     *
     * @param {number} cmid         Course module id.
     * @param {Object} statement    The xAPI statement to forward.
     * @param {string} registration Attempt-grouping token (the page-load token).
     * @param {string} mode         grading|preview.
     * @param {string} sesskey      Moodle session key, validated server-side.
     * @returns {string} JSON payload.
     */
    function buildPayload(cmid, statement, registration, mode, sesskey) {
        return JSON.stringify({
            id: cmid,
            statement: statement,
            registration: registration,
            mode: mode,
            sesskey: sesskey,
        });
    }

    /**
     * Build the message listener and its supporting state.
     *
     * Dependencies are injectable so the origin/dedup/forward behaviour can be
     * unit-tested without a real window or XHR:
     *   - cmid, trackurl, registration, mode: identity and endpoint.
     *   - allowedOrigin: the trusted host origin (defaults to window.location.origin).
     *   - xhrFactory(): returns an XMLHttpRequest-like object (default: real XHR).
     *
     * @param {Object} config
     * @returns {{handleMessage: Function, send: Function, start: Function}}
     */
    function createListener(config) {
        config = config || {};
        var cmid = config.cmid;
        var trackurl = config.trackurl;
        var registration = config.registration || '';
        var mode = config.mode || 'grading';
        // Sent in the POST body, never appended to trackurl (SEC-04).
        var sesskey = config.sesskey;
        var allowed = config.allowedOrigin
            || ((typeof window !== 'undefined' && window.location) ? window.location.origin : '');
        // Trust gate. Legacy (same-origin) trusts a statement by event.origin === host
        // origin (RIE-013). Secure mode (DEC-0069) serves the package in an opaque origin
        // where event.origin is the string "null", so origin can never authenticate; there
        // the anchor is WINDOW IDENTITY — event.source === the package iframe's
        // contentWindow, exactly like the SCORM bridge relay (js/scorm_bridge_relay.js).
        // Setting iframeid (or, for tests, expectedSource) selects window-identity mode;
        // otherwise the origin check is used.
        var iframeid = config.iframeid || null;
        var injectedsource = config.expectedSource;   // explicit window (tests) or null/undefined
        var usewindowidentity = !!(iframeid || (injectedsource !== undefined && injectedsource !== null));
        var docref = config.document || (typeof document !== 'undefined' ? document : null);
        var xhrFactory = config.xhrFactory || function () { return new XMLHttpRequest(); };
        // Bounded resend so a transient non-2xx / network blip does not silently lose a
        // grade-bearing statement — js/scorm_tracker.js self-heals the same way (a failed
        // commit stays dirty and is re-sent). The server is idempotent by statement.id, so
        // a resend never double-grades. Both knobs are injectable for the unit tests.
        var maxRetries = (typeof config.maxRetries === 'number') ? config.maxRetries : 2;
        var retryDelay = (typeof config.retryDelay === 'number') ? config.retryDelay : 3000;
        var schedule = config.schedule || function (fn, ms) {
            return (typeof setTimeout !== 'undefined') ? setTimeout(fn, ms) : null;
        };
        var seen = {};   // De-dup set keyed by statement.id within this page session.

        // Forward one statement to the server. Async (the grade-bearing 'answered'/package
        // statements arrive during interaction, never at unload). A confirmed 2xx — or a
        // definitive 409 attempt-cap rejection — marks the id as seen; a transient failure
        // clears the claim and schedules a bounded retry so the grade is not lost.
        function send(statement, attempt) {
            attempt = attempt || 0;
            var id = statement && statement.id;
            try {
                var xhr = xhrFactory();
                xhr.open('POST', trackurl, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.onload = function () {
                    var status = xhr.status || 0;
                    // 2xx = stored. 409 = a correct attempt-cap rejection that must NOT be
                    // retried (the grade is not meant to be written). Both are final.
                    if ((status >= 200 && status < 300) || status === 409) {
                        if (id) { seen[id] = true; }
                        return;
                    }
                    retry(statement, attempt);
                };
                xhr.onerror = function () { retry(statement, attempt); };
                xhr.send(buildPayload(cmid, statement, registration, mode, sesskey));
                return true;
            } catch (e) {
                // Never let tracking break the activity; still try to recover the grade.
                retry(statement, attempt);
                return false;
            }
        }

        // Re-queue a failed statement: drop the in-flight de-dup claim and resend with a
        // linear backoff, up to maxRetries. Bounded so a permanently-failing server cannot
        // loop forever.
        function retry(statement, attempt) {
            var id = statement && statement.id;
            if (id) { delete seen[id]; }
            if (attempt >= maxRetries) { return; }
            schedule(function () {
                if (id) { seen[id] = true; }
                send(statement, attempt + 1);
            }, retryDelay * (attempt + 1));
        }

        // Resolve the only window allowed to deliver statements in window-identity mode.
        // Lazy (per message): view.php injects this listener inline BEFORE the iframe
        // element exists, but the element is present by the time the package emits.
        function expectedSource() {
            if (injectedsource !== undefined && injectedsource !== null) { return injectedsource; }
            if (iframeid && docref) {
                var el = docref.getElementById(iframeid);
                return el ? el.contentWindow : null;
            }
            return null;
        }

        // Whether an event may be forwarded: window identity in secure mode (the opaque
        // "null" origin is ignored), or an exact host origin in legacy mode. event.source
        // is set by the browser to the posting window and cannot be forged by page script,
        // so it is a sound anchor when the origin is unusable (DEC-0069).
        function isTrusted(event) {
            if (!event) { return false; }
            if (usewindowidentity) {
                var src = expectedSource();
                return !!src && event.source === src;
            }
            return isTrustedOrigin(event.origin, allowed);
        }

        // Validate, de-dup and forward a single message. Returns true when forwarded.
        function handleMessage(event) {
            if (!isTrusted(event)) { return false; }
            if (!isStatementMessage(event.data)) { return false; }
            var statement = event.data.statement;
            var id = statement.id;
            if (id) {
                if (seen[id]) { return false; }
                seen[id] = true;   // claim in-flight so a duplicate message can't double-POST
            }
            return send(statement, 0);
        }

        function start() {
            if (typeof window !== 'undefined' && window.addEventListener) {
                window.addEventListener('message', handleMessage);
            }
        }

        return { handleMessage: handleMessage, send: send, start: start };
    }

    var exp = {
        isStatementMessage: isStatementMessage,
        isTrustedOrigin: isTrustedOrigin,
        buildPayload: buildPayload,
        createListener: createListener
    };
    // Test runner (Vitest/Node) consumes module.exports; the guard keeps a browser
    // <script> from throwing on the undefined `module`.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser bootstrap (view.php) consumes window.exeXapiListener.
    if (typeof window !== 'undefined') { window.exeXapiListener = exp; }
})();
