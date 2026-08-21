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
     * Header of the versioned cmi.suspend_data payload written by eXeLearning's
     * SCORM 1.2 runtime (public/app/common/scorm/scorm12/exe-scorm12-activities.js).
     */
    var EXE12_PREFIX = 'exe12/';
    /** Highest payload version this parser understands. */
    var EXE12_MAX_VERSION = 1;
    var EXE12_RECORD_SEPARATOR = '|';
    var EXE12_FIELD_SEPARATOR = ';';
    /** Bit 1 of a record's flag field: the activity counts towards the score. */
    var EXE12_FLAG_EVALUABLE = 1;

    /**
     * Coerce a payload field to a finite number.
     *
     * An empty string is treated as absent (the producer writes "" for "no score
     * yet"), so callers can distinguish it from a real 0 by passing a null fallback.
     *
     * @param {*} value Raw field.
     * @param {number|null} fallback Value to use when not numeric.
     * @returns {number|null} Finite number or the fallback.
     */
    function toNumber(value, fallback) {
        if (value === '' || value === null || value === undefined) { return fallback; }
        var parsed = Number(value);
        return isFinite(parsed) ? parsed : fallback;
    }

    /**
     * Decode one record of the versioned `exe12/` payload.
     *
     * Layout (see encodeRecord() in exe-scorm12-activities.js), fields separated by ';':
     *   [0] encodeURIComponent(activity id) — the .idevice_node id, i.e. our objectid
     *   [1] flags bitmask (1 evaluable, 2 completionRequired, 4 completed)
     *   [2] answered  [3] total  [4] score  [5] weight  [6] minimumScore  [7] maximumScore
     *
     * The score is field [4] and is NEVER derived from answered/total: a real record
     * reads `ide-a;7;0;4;100;25;0;100` — answered 0 of 4, score 100 — because an
     * iDevice may report a score without reporting question counters.
     *
     * Skipped, each on purpose:
     *  - three-field records: those are migrated-but-unclaimed legacy entries
     *    (`position;score;weight`) riding along in the payload. They carry a page
     *    position instead of a stable id, and "unclaimed" means no live iDevice on
     *    this page owns them, so attributing them to whatever occupies that slot is
     *    exactly the contamination captureItemScores() exists to prevent;
     *  - records with no evaluable flag: the producer excludes them from
     *    cmi.core.score.raw, so they must not reach a gradebook column either;
     *  - records whose score field is empty: the activity has not produced a result
     *    yet, which is not the same as scoring 0.
     *
     * @param {string} text Encoded record.
     * @returns {Object|null} {title, scorepct, weighted, objectid} or null when unusable.
     */
    function decodeExe12Record(text) {
        var fields = String(text).split(EXE12_FIELD_SEPARATOR);
        if (fields.length < 6) { return null; }
        var id;
        try { id = decodeURIComponent(fields[0]); } catch (e) { return null; }
        if (!id) { return null; }
        var flags = toNumber(fields[1], null);
        if (flags === null) { return null; }
        /* eslint-disable no-bitwise */
        if ((flags & EXE12_FLAG_EVALUABLE) === 0) { return null; }
        /* eslint-enable no-bitwise */
        var score = toNumber(fields[4], null);
        if (score === null) { return null; }
        var weight = toNumber(fields[5], 1);
        var min = toNumber(fields[6], 0);
        var max = toNumber(fields[7], 100);
        // A degenerate range cannot normalise a score; fall back to a 100-wide window,
        // exactly like the producer's normalize().
        if (!(max > min)) { max = min + 100; }
        return {
            // The versioned format drops titles (they are the largest field in a
            // 4096-character element and aggregation never needed them).
            title: '',
            scorepct: Math.max(0, Math.min(100, ((score - min) / (max - min)) * 100)),
            weighted: (weight !== null && weight > 0) ? weight : 1,
            objectid: id
        };
    }

    /**
     * Parse the versioned `exe12/{version}` cmi.suspend_data payload.
     *
     * Version handling is deliberately strict: an unreadable version tag, or one
     * newer than EXE12_MAX_VERSION, yields an EMPTY map instead of a best-effort
     * parse. A future revision may reorder or repurpose fields, and a silently
     * misparsed field would publish a wrong grade — worse than publishing none, which
     * merely leaves the item ungraded and visibly missing.
     *
     * @param {string} payload Raw suspend_data value, already known to start with the header.
     * @returns {Object} Map of objectid to {title, scorepct, weighted, objectid}.
     */
    function parseExe12(payload) {
        var out = {};
        var body = payload.slice(EXE12_PREFIX.length);
        var sep = body.indexOf(EXE12_RECORD_SEPARATOR);
        var version = toNumber(sep === -1 ? body : body.slice(0, sep), null);
        if (version === null || version < 1 || version > EXE12_MAX_VERSION) { return out; }
        var records = sep === -1 ? [] : body.slice(sep + 1).split(EXE12_RECORD_SEPARATOR);
        for (var i = 0; i < records.length; i++) {
            var entry = decodeExe12Record(records[i]);
            if (entry) { out[entry.objectid] = entry; }
        }
        return out;
    }

    /**
     * Parse the legacy (unversioned) cmi.suspend_data, entries separated by ".\t":
     *   {N}. "{title}"; {scoreLabel}: {S}%; {weightLabel}: {W}%
     * The score/weight numbers accept a comma decimal separator (es_ES/fr_FR/de_DE
     * "60,5%"); it is normalised to a dot before parseFloat. The score percentage is
     * clamped to 0–100. Malformed lines are skipped.
     *
     * N is the PAGE-LOCAL DOM index of the iDevice, so it collides across pages and
     * the entry cannot name its own owner — hence no `objectid` on these entries.
     *
     * @param {string} s Raw cmi.suspend_data value.
     * @returns {Object} Map of page-local index N (int) to {title, scorepct, weighted}.
     */
    function parseLegacySuspend(s) {
        var out = {};
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
     * Parse cmi.suspend_data, mirroring \mod_exelearning\local\track::parse_suspend_data.
     *
     * Two producer formats reach this shim and the header selects the parser; nothing
     * downstream re-sniffs the payload:
     *
     *  - the VERSIONED payload `exe12/1|{record}|{record}…` written by eXeLearning's
     *    SCORM 1.2 runtime (core PR #2209 onwards). Every record names its own
     *    activity id, so entries come back keyed by that stable objectid;
     *  - the LEGACY unversioned lines written by every earlier release, keyed by the
     *    page-local DOM index N, which the producer reuses on every page (DEC-5-01).
     *
     * One representation serves both: every value carries title, scorepct and
     * weighted, plus an `objectid` key on — and only on — an entry that knows its own
     * identity. Callers branch on that key, never on the raw string.
     *
     * @param {string} s Raw cmi.suspend_data value.
     * @returns {Object} Map of objectid (versioned) or page-local N (legacy) to
     *          {title, scorepct, weighted[, objectid]}.
     */
    function parseSuspend(s) {
        if (!s) { return {}; }
        var text = String(s);
        return text.indexOf(EXE12_PREFIX) === 0 ? parseExe12(text) : parseLegacySuspend(text);
    }

    /**
     * Map page-local index N (1-based) to the iDevice objectid, read live from the
     * currently loaded scoring document. Reproduces eXeLearning's own
     * $('.idevice_node').index(el)+1 ordering, so N resolves to the right objectid
     * (the .idevice_node element id, equal to <odeIdeviceId> and to our
     * exelearning_grade_item.objectid). This is the multi-page collision fix
     * (DEC-5-01 / RIE-007).
     *
     * Only the legacy format needs this: a versioned `exe12/` record carries its
     * objectid already.
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
     * True when a legacy entry is a stale copy of a score already banked for a
     * DIFFERENT iDevice that is not on the currently loaded page.
     *
     * The producer rewrites its WHOLE lmsData map on every score, so a suspend_data
     * write made on page 2 still carries the entries page 1 left in the slots page 2
     * now occupies. Those carried-over entries are byte-identical to what we already
     * banked for their real owner — same title, same score, same weight — and their
     * owner is, by construction, not on the loaded page. That triple is the signature
     * this looks for.
     *
     * @param {Object} entry    The legacy entry under consideration.
     * @param {string} oid      The objectid the current page's DOM maps its slot to.
     * @param {Object} baseline Everything banked so far, keyed by objectid.
     * @param {Object} onPage   Set of objectids the loaded page owns.
     * @returns {boolean} True when the entry belongs to an off-page iDevice.
     */
    function isCarriedOver(entry, oid, baseline, onPage) {
        for (var bid in baseline) {
            if (!baseline.hasOwnProperty(bid) || bid === oid || onPage[bid]) { continue; }
            var banked = baseline[bid];
            if (banked.title === entry.title
                    && banked.scorepct === entry.scorepct
                    && banked.weighted === entry.weighted) {
                return true;
            }
        }
        return false;
    }

    /**
     * From a fresh suspend_data parse, keep only the entries that changed (the iDevice
     * that just scored) and stamp them by stable objectid. Pure: callers own the
     * prev/itemScores state.
     *
     * VERSIONED (`exe12/`) entries carry their own objectid: they are matched against
     * the objectid-keyed baseline and nothing below applies — no DOM resolution, no
     * heuristics, no residual limits. Everything that follows exists solely because
     * the legacy format cannot name its owner.
     *
     * LEGACY entries name only a page-local slot N, which the producer reuses on every
     * page, and every write repeats the whole map — so the payload mixes the score
     * that just happened with stale entries from pages visited earlier. Two rules that
     * look reasonable each break one half of that:
     *
     *  - keying the change baseline by slot N drops a real grade: a page-2 iDevice
     *    whose slot already held a page-1 entry with the SAME score and weight
     *    compares equal and is never graded (trace synthetic-collided-slot-same-score);
     *  - keying it by objectid alone invents one: the stale page-1 entry resolves
     *    against page 2's DOM map, looks brand-new for that objectid and contaminates
     *    an iDevice the learner never touched (trace synthetic-stale-slot).
     *
     * The rule implemented here keeps the objectid-keyed baseline (so no grade is
     * lost) and adds an attribution test before trusting a slot resolution (so no
     * grade is invented). A legacy entry is attributed to the objectid its slot
     * resolves to unless an identical entry — same title, same score, same weight —
     * is already banked for a different objectid that the loaded page does NOT own,
     * in which case it is a carried-over copy and is ignored entirely: neither graded
     * nor banked under the wrong owner.
     *
     * Two consequences worth stating:
     *  - a slot with no live occupant (beyond the loaded page's node count, or an
     *    id-less node) never resolves at all, so it can never be attributed;
     *  - a single-page package is untouched by the attribution test, because every
     *    banked objectid is on the loaded page and the test can only ever fire
     *    against an off-page one.
     *
     * Residual limits, honestly:
     *  1. two iDevices on different pages that share a title AND score identically
     *     AND weigh the same are indistinguishable in this format; the later one is
     *     read as a carry-over and stays ungraded until its score changes. A real
     *     recorded trace shows repeated titles are common (four iDevices all called
     *     "Activity A"), so the score+weight half of the triple is load-bearing.
     *  2. a stale entry whose real owner was never banked — its page was scored
     *     before this LMS session began (a resumed attempt) — has nothing to match
     *     against and is still attributed to the current slot occupant.
     *  3. both disappear under `exe12/`, which is why the fix belongs in the legacy
     *     branch only and why nothing here should be generalised to the new format.
     *
     * @param {Object} newParsed  Result of parseSuspend (keyed by objectid or by N).
     * @param {Object} prevParsed Previous baseline (keyed by objectid).
     * @param {Object|null} domMap N -> objectid map (resolveObjectMap result); unused
     *          for versioned entries.
     * @returns {{delta: Object, prev: Object}} delta = objectid -> {scorepct, weighted, title}
     *          for the changed-and-attributable entries; prev = the next baseline, keyed
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
        // The objectids the loaded page owns; an entry can only be a carry-over from
        // a page that is NOT loaded.
        var onPage = {};
        if (domMap) {
            for (var slot in domMap) {
                if (domMap.hasOwnProperty(slot)) { onPage[domMap[slot]] = true; }
            }
        }
        for (var n in newParsed) {
            if (!newParsed.hasOwnProperty(n)) { continue; }
            // A VERSIONED (`exe12/`) entry names its own activity, so it needs no DOM
            // resolution and cannot collide: it is already keyed by the stable objectid.
            // Everything below exists only because the legacy format cannot name its
            // owner and reuses the page-local slot N across pages.
            var cur = newParsed[n];
            var oid = cur.objectid;
            if (!oid) {
                // Legacy: resolve the page-local slot, then check attribution.
                if (!domMap || !domMap[n]) { continue; }
                oid = domMap[n];
                if (isCarriedOver(cur, oid, prevParsed, onPage)) { continue; }
            }
            var before = prevParsed[oid];
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
        var getScoringDocument = config.getScoringDocument || function () {
            var fr = (typeof document !== 'undefined') && document.getElementById('exelearningobject');
            return fr && fr.contentDocument;
        };
        var bindUnload = config.bindUnload !== false;

        var errCode = '0', cmi = {}, dirty = false, autoTimer = null;
        // Everything banked so far from cmi.suspend_data, keyed by objectid (both
        // payload formats converge on it; see captureItemScores).
        var prevSuspend = {};
        var itemScores = {};    // objectid => { scorepct, weighted, title }.

        function send(sync) {
            if (!dirty) { return true; }
            var snapshot = JSON.stringify(cmi);
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
