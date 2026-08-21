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
 * SCORM 1.2 tracker for the eXeLearning player (single source of truth).
 *
 * This module is the grade-critical client side of the activity. view.php reads
 * this file and injects it inline (NOT as an AMD module) so window.API exists
 * synchronously before the package iframe's pipwerks findAPI() runs — an async
 * AMD load would race the SCO and break grading. The same file is unit-tested
 * with Vitest (tests/js/scorm_tracker.test.js); the parsing logic mirrors the
 * PHP parser \mod_exelearning\local\track::parse_suspend_data so both stay aligned.
 *
 * It is exposed two ways from a single body: window.exeScormTracker for the
 * browser bootstrap, and module.exports for the test runner.
 */
(function () {
    'use strict';

    /**
     * Parse cmi.suspend_data exactly like \mod_exelearning\local\track::parse_suspend_data.
     *
     * Format (eXeLearning v4), entries separated by ".\t":
     *   {N}. "{title}"; {scoreLabel}: {S}%; {weightLabel}: {W}%
     * The score/weight numbers accept a comma decimal separator (es_ES/fr_FR/de_DE
     * "60,5%"); it is normalised to a dot before parseFloat. The score percentage is
     * clamped to 0–100. Malformed lines are skipped.
     *
     * @param {string} s Raw cmi.suspend_data value.
     * @returns {Object} Map of page-local index N (int) to {title, scorepct, weighted}.
     */
    function parseSuspend(s) {
        var out = {};
        if (!s) { return out; }
        var re = /^(\d+)\.\s"([^"]*)";\s[^:]+:\s([\d.,]+)%;\s[^:]+:\s([\d.,]+)%\.?$/;
        var parts = String(s).split(/\.\t/);
        for (var i = 0; i < parts.length; i++) {
            var line = parts[i].replace(/^\s+|\s+$/g, '');
            if (!line) { continue; }
            var m = line.match(re);
            if (m) {
                out[parseInt(m[1], 10)] = {
                    title: m[2],
                    scorepct: Math.max(0, Math.min(100, parseFloat(m[3].replace(',', '.')))),
                    weighted: parseFloat(m[4].replace(',', '.'))
                };
            }
        }
        return out;
    }

    /**
     * Map page-local index N (1-based) to the iDevice objectid, read live from the
     * currently loaded scoring document. Reproduces eXeLearning's own
     * $('.idevice_node').index(el)+1 ordering, so N resolves to the right objectid
     * (the .idevice_node element id, equal to <odeIdeviceId> and to our
     * exelearning_grade_item.objectid). This is the multi-page collision fix
     * (DEC-5-01 / RIE-007).
     *
     * @param {Document|null} doc The iframe's content document (null if unavailable).
     * @returns {Object|null} Map of N (int) to objectid, or null when nothing resolves.
     */
    function resolveObjectMap(doc) {
        try {
            if (!doc) { return null; }
            var nodes = doc.querySelectorAll('.idevice_node');
            if (!nodes || !nodes.length) { return null; }
            var map = {};
            for (var i = 0; i < nodes.length; i++) {
                if (nodes[i].id) { map[i + 1] = nodes[i].id; }
            }
            return map;
        } catch (e) { return null; }
    }

    /**
     * From a fresh suspend_data parse, keep only the entries that changed (the iDevice
     * that just scored, always on the currently loaded page) and stamp them by stable
     * objectid. Stale cross-page entries left in the collided suspend_data are skipped
     * because they do not resolve against the current page's DOM — that is what fixes
     * the multi-page collision. Pure: callers own the prev/itemScores state.
     *
     * The change baseline is keyed by OBJECTID, not by the page-local slot N: the
     * legacy suspend_data format reuses N across pages, so a page-2 iDevice landing on
     * a slot whose page-1 occupant had the same score and weight would compare equal
     * against an N-keyed baseline and be dropped without ever reaching its gradebook
     * column. Two different iDevices are never "unchanged" relative to each other.
     *
     * @param {Object} newParsed  Result of parseSuspend on the new suspend_data (keyed by N).
     * @param {Object} prevParsed Previous baseline (keyed by objectid).
     * @param {Object|null} domMap N -> objectid map (resolveObjectMap result).
     * @returns {{delta: Object, prev: Object}} delta = objectid -> {scorepct, weighted, title}
     *          for the changed-and-resolvable entries; prev = the next baseline, keyed
     *          by objectid, carrying forward entries for pages not currently loaded so
     *          returning to a page does not re-emit its unchanged scores.
     */
    function captureItemScores(newParsed, prevParsed, domMap) {
        var delta = {};
        var next = {};
        prevParsed = prevParsed || {};
        for (var seen in prevParsed) {
            if (prevParsed.hasOwnProperty(seen)) { next[seen] = prevParsed[seen]; }
        }
        for (var n in newParsed) {
            if (!newParsed.hasOwnProperty(n)) { continue; }
            if (!domMap || !domMap[n]) { continue; }
            var oid = domMap[n];
            var before = prevParsed[oid], cur = newParsed[n];
            var entry = { scorepct: cur.scorepct, weighted: cur.weighted, title: cur.title };
            next[oid] = entry;
            if (!before || before.scorepct !== cur.scorepct || before.weighted !== cur.weighted) {
                delta[oid] = entry;
            }
        }
        return { delta: delta, prev: next };
    }

    /**
     * Serialize the track.php POST body.
     *
     * The sesskey travels here rather than in the endpoint's query string (SEC-04):
     * a URL parameter is recorded verbatim by web-server access logs, reverse proxies
     * and diagnostic tooling, while a POST body is not. track.php confirms it with an
     * explicit confirm_sesskey() after decoding.
     *
     * @param {number} cmid       Course module id.
     * @param {string} session    Per-page attempt token.
     * @param {Object} cmi        Buffered CMI key/value pairs.
     * @param {Object} itemscores objectid -> {scorepct, weighted, title}.
     * @param {string} sesskey    Moodle session key, validated server-side.
     * @returns {string} JSON payload.
     */
    function buildPayload(cmid, session, cmi, itemscores, sesskey) {
        return JSON.stringify({
            id: cmid,
            session: session,
            cmi: cmi,
            itemscores: itemscores,
            sesskey: sesskey,
        });
    }

    /**
     * Build the SCORM 1.2 window.API object and its supporting state machine.
     *
     * Dependencies are injectable so the buffering/autocommit/dirty-retry behaviour
     * can be unit-tested without a real DOM, XHR or timers:
     *   - cmid, trackurl, session: identity and endpoint.
     *   - getScoringDocument(): returns the iframe content document (default: reads
     *     #exelearningobject) for objectid resolution.
     *   - transport(data, sync): optional sink for the buffered scores. When provided
     *     it REPLACES the direct XHR (secure mode: js/scorm_bridge_shim.js posts the
     *     data to the Moodle parent, which owns the authenticated request). It gets
     *     {cmi, itemscores} and a sync flag, and returns false to signal failure
     *     (keeps the buffer dirty for retry). When absent, the XHR path below runs
     *     (legacy same-origin mode and the unit tests).
     *   - xhrFactory(): returns an XMLHttpRequest-like object (default: real XHR).
     *   - setTimeout / clearTimeout: timer functions (default: globals).
     *   - bindUnload: wire a beforeunload synchronous flush (default: true in a browser).
     *
     * @param {Object} config
     * @returns {{api: Object, destroy: Function}} api is window.API; destroy clears the timer.
     */
    function createScormApi(config) {
        config = config || {};
        var cmid = config.cmid;
        var trackurl = config.trackurl;
        var session = config.session;
        // Sent in the POST body, never appended to trackurl (SEC-04).
        var sesskey = config.sesskey;
        var setTimeoutFn = config.setTimeout || (typeof setTimeout !== 'undefined' ? setTimeout : null);
        var clearTimeoutFn = config.clearTimeout || (typeof clearTimeout !== 'undefined' ? clearTimeout : null);
        var xhrFactory = config.xhrFactory || function () { return new XMLHttpRequest(); };
        var transport = config.transport || null;
        var getScoringDocument = config.getScoringDocument || function () {
            var fr = (typeof document !== 'undefined') && document.getElementById('exelearningobject');
            return fr && fr.contentDocument;
        };
        var bindUnload = config.bindUnload !== false;

        var errCode = '0', cmi = {}, dirty = false, autoTimer = null;
        var prevSuspend = {};   // Change baseline, keyed by stable objectid across pages.
        var itemScores = {};    // objectid => { scorepct, weighted, title }.

        function send(sync) {
            if (!dirty) { return true; }
            // Bridge transport (secure mode): hand the buffered CMI + per-iDevice
            // scores to the injected sink instead of doing the XHR here. The sink
            // (js/scorm_bridge_shim.js) posts them to the Moodle parent, which owns
            // the authenticated track.php request, retry and the pagehide beacon.
            // The parent carries its own sesskey, so none travels with the message.
            // Fire-and-forget: clear dirty once the message leaves; a thrown/false
            // result keeps it dirty so the next autocommit re-sends it.
            if (transport) {
                try {
                    var accepted = transport({ cmi: cmi, itemscores: itemScores }, sync === true);
                    if (accepted !== false) { dirty = false; return true; }
                    return false;
                } catch (te) { errCode = '101'; return false; }
            }
            // Direct transport: the sesskey travels in the POST body, not the URL.
            var payload = buildPayload(cmid, session, cmi, itemScores, sesskey);
            try {
                var xhr = xhrFactory();
                // Synchronous in LMSFinish (student closes the tab); async otherwise.
                xhr.open('POST', trackurl, sync !== true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                if (sync === true) {
                    xhr.send(payload);
                    // Synchronous: the status is known now. Keep dirty on failure so
                    // the buffered score is retried instead of being silently lost.
                    if (xhr.status >= 200 && xhr.status < 300) { dirty = false; return true; }
                    return false;
                }
                // Async: clear dirty ONLY after the server confirms a 2xx response,
                // and only if no newer value was buffered meanwhile. On failure dirty
                // stays set so the next autocommit / beforeunload re-sends it (a failed
                // autocommit must never silently drop a grade write to the gradebook).
                // Snapshot the buffer here (this path only) — captured synchronously
                // before xhr.send() below, so the onload comparison value is unchanged.
                var snapshot = JSON.stringify(cmi);
                xhr.onload = function () {
                    if (xhr.status >= 200 && xhr.status < 300
                            && JSON.stringify(cmi) === snapshot) {
                        dirty = false;
                    }
                };
                xhr.onerror = function () { errCode = '101'; };
                xhr.send(payload);
                return true;
            } catch (e) { errCode = '101'; return false; }
        }

        // Autocommit: after 500 ms with no new SetValue, persist.
        function schedule() {
            if (autoTimer && clearTimeoutFn) { clearTimeoutFn(autoTimer); }
            if (setTimeoutFn) { autoTimer = setTimeoutFn(function () { send(false); }, 500); }
        }

        // On each suspend_data write, capture the just-scored iDevice by objectid while
        // the scoring page is still loaded in the iframe (DEC-5-01).
        function handleSuspend(value) {
            var newParsed = parseSuspend(value);
            var domMap = resolveObjectMap(getScoringDocument());
            var result = captureItemScores(newParsed, prevSuspend, domMap);
            for (var oid in result.delta) {
                if (result.delta.hasOwnProperty(oid)) { itemScores[oid] = result.delta[oid]; }
            }
            prevSuspend = result.prev;
        }

        var api = {
            LMSInitialize:   function () { return 'true'; },
            LMSFinish:       function () { send(true); return 'true'; },
            LMSCommit:       function () { return send(true) ? 'true' : 'false'; },
            LMSGetValue:     function (k) { return cmi[k] || ''; },
            LMSSetValue:     function (k, v) {
                cmi[k] = String(v); dirty = true;
                // Resolve per-iDevice scores to stable objectids while the scoring
                // page is still loaded in the iframe (DEC-5-01).
                if (k === 'cmi.suspend_data') {
                    handleSuspend(cmi[k]);
                    schedule();
                }
                // Autocommit on critical keys so the grade reaches the gradebook
                // even if eXeLearning does not call Commit explicitly.
                if (k === 'cmi.core.score.raw' || k === 'cmi.core.lesson_status'
                        || k === 'cmi.score.raw' || k === 'cmi.completion_status'
                        || k === 'cmi.success_status') {
                    schedule();
                }
                return 'true';
            },
            LMSGetLastError: function () { return errCode; },
            LMSGetErrorString:  function () { return ''; },
            LMSGetDiagnostic:   function () { return ''; },
        };

        function destroy() {
            if (autoTimer && clearTimeoutFn) { clearTimeoutFn(autoTimer); }
        }

        // Persist when the tab is closed (synchronous).
        if (bindUnload && typeof window !== 'undefined' && window.addEventListener) {
            window.addEventListener('beforeunload', function () {
                if (autoTimer && clearTimeoutFn) { clearTimeoutFn(autoTimer); }
                send(true);
            });
        }

        return { api: api, destroy: destroy };
    }

    var exp = {
        parseSuspend: parseSuspend,
        resolveObjectMap: resolveObjectMap,
        captureItemScores: captureItemScores,
        buildPayload: buildPayload,
        createScormApi: createScormApi
    };
    // Test runner (Vitest/Node) consumes module.exports; the guard keeps a browser
    // <script> from throwing on the undefined `module`.
    if (typeof module !== 'undefined' && module.exports) { module.exports = exp; }
    // Browser bootstrap (view.php) consumes window.exeScormTracker.
    if (typeof window !== 'undefined') { window.exeScormTracker = exp; }
})();
