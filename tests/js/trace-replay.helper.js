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
 * Grading-trace replay harness (Tier 2, SCORM lane).
 *
 * Feeds a recorded trace (see TRACE-CONTRACT.md, traceVersion 1) through the REAL
 * js/scorm_tracker.js and reports the itemscores the tracker would have POSTed to
 * track.php. Nothing here reimplements grading logic: the tracker file is loaded
 * verbatim and driven through its public window.API surface, so the result is a
 * measurement of the code under test, not of this harness.
 *
 * The tracker path is a parameter so the same trace can be replayed against
 * different revisions of the plugin (e.g. two competing pull requests) and the
 * outputs compared directly.
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * Load js/scorm_tracker.js from an arbitrary path into an isolated sandbox.
 *
 * The tracker is an IIFE that publishes itself on both module.exports and
 * window.exeScormTracker. We evaluate it with `window`/`document` shadowed by inert
 * stubs so loading it neither touches the ambient happy-dom globals nor wires a real
 * beforeunload listener; the exports come back through the injected module object.
 *
 * @param {string} trackerPath Absolute path to a scorm_tracker.js.
 * @returns {Object} { parseSuspend, resolveObjectMap, captureItemScores, buildPayload, createScormApi }
 */
export function loadTracker(trackerPath) {
    const src = fs.readFileSync(trackerPath, 'utf8');
    const sandboxModule = { exports: {} };
    // No addEventListener on the stub window: the tracker's bindUnload branch is
    // guarded by it, so the sandbox can never register a listener on the real window.
    const sandboxWindow = {};
    const sandboxDocument = { getElementById: () => null };
    // eslint-disable-next-line no-new-func
    const evaluate = new Function('module', 'window', 'document', src);
    evaluate(sandboxModule, sandboxWindow, sandboxDocument);
    const exp = sandboxModule.exports && sandboxModule.exports.createScormApi
        ? sandboxModule.exports
        : sandboxWindow.exeScormTracker;
    if (!exp || typeof exp.createScormApi !== 'function') {
        throw new Error('Not a scorm_tracker.js (no createScormApi export): ' + trackerPath);
    }
    return exp;
}

/**
 * Build the fake "scoring document" for one page of the trace.
 *
 * resolveObjectMap() only ever calls doc.querySelectorAll('.idevice_node'), so a
 * detached container element is a faithful stand-in for the iframe's content
 * document — and using a detached node per page keeps pages from leaking into each
 * other through the shared happy-dom body.
 *
 * @param {Array<string>} ideviceNodes Ordered .idevice_node ids; index i => slot i+1.
 * @returns {Element} Container whose querySelectorAll returns those nodes in order.
 */
export function buildPageDocument(ideviceNodes) {
    const container = document.createElement('div');
    for (const id of ideviceNodes || []) {
        const node = document.createElement('div');
        node.className = 'idevice_node';
        if (id) { node.id = id; }
        container.appendChild(node);
    }
    return container;
}

/**
 * Replay a recorded trace through the real tracker.
 *
 * Mechanics:
 *  - one fake scoring document per trace page, swapped in as `call.page` changes,
 *    which is what makes page navigation observable to resolveObjectMap();
 *  - calls driven in `seq` order through api.LMSInitialize/LMSSetValue/LMSGetValue/
 *    LMSCommit/LMSFinish;
 *  - timers stubbed: the tracker's 500 ms autocommit debounce is fired after each
 *    call, standing in for the seconds-long gap between real learner interactions;
 *  - every POST captured through the xhrFactory stub (all responses 200).
 *
 * @param {Object} trace   Parsed <scenario>.trace.json (traceVersion 1).
 * @param {Object} options
 * @param {string} options.trackerPath Absolute path to the scorm_tracker.js under test.
 * @param {number} [options.status]    HTTP status the stubbed track.php returns (default 200).
 * @returns {{itemscores: Object, posts: Array, calls: Array}}
 *          itemscores = every POSTed itemscores map merged in order (the final
 *          accumulated state the gradebook would have seen); posts = each POST body
 *          in order; calls = the replayed SCORM calls with their return values.
 */
export function replayScormTrace(trace, options) {
    const opts = options || {};
    if (!opts.trackerPath) { throw new Error('replayScormTrace requires options.trackerPath'); }
    const status = typeof opts.status === 'number' ? opts.status : 200;
    const { createScormApi } = loadTracker(opts.trackerPath);

    // One document per page index, built once from the trace's page table.
    const pageDocs = {};
    for (const page of trace.pages || []) {
        pageDocs[page.index] = buildPageDocument(page.ideviceNodes);
    }

    const posts = [];
    function xhrFactory() {
        const xhr = {
            status,
            onload: null,
            onerror: null,
            open(method, url, async) { xhr._req = { method, url, async }; },
            setRequestHeader() {},
            send(payload) {
                posts.push(Object.assign({}, xhr._req, { body: JSON.parse(payload) }));
                if (typeof xhr.onload === 'function') { xhr.onload(); }
            },
        };
        return xhr;
    }

    let currentDoc = null;
    let scheduled = null;
    const { api, destroy } = createScormApi({
        cmid: 1,
        trackurl: 'https://example.test/track.php',
        session: 'replay-session',
        sesskey: 'replay-sesskey',
        bindUnload: false,
        getScoringDocument: () => currentDoc,
        xhrFactory,
        setTimeout: (fn) => { scheduled = fn; return 1; },
        clearTimeout: () => { scheduled = null; },
    });

    // Fire the pending autocommit, if any. Real learners pause far longer than the
    // 500 ms debounce between answers, so the timer having elapsed is the normal case.
    function flushTimer() {
        if (typeof scheduled === 'function') {
            const fn = scheduled;
            scheduled = null;
            fn();
        }
    }

    const calls = [];
    const ordered = (trace.scorm || []).slice().sort((a, b) => a.seq - b.seq);
    for (const call of ordered) {
        if (Object.prototype.hasOwnProperty.call(pageDocs, call.page)) {
            currentDoc = pageDocs[call.page];
        }
        const fn = api[call.method];
        if (typeof fn !== 'function') {
            throw new Error('Trace seq ' + call.seq + ': tracker has no method ' + call.method);
        }
        const returned = fn.apply(api, call.args || []);
        calls.push({ seq: call.seq, page: call.page, method: call.method, returned });
        flushTimer();
    }
    flushTimer();
    destroy();

    // Merge every POSTed itemscores map in order: later POSTs carry the tracker's
    // accumulated state, so the merge is the final picture track.php would hold.
    const itemscores = {};
    for (const post of posts) {
        Object.assign(itemscores, (post.body && post.body.itemscores) || {});
    }

    return { itemscores, posts, calls };
}

/**
 * Read a trace fixture from tests/fixtures/traces/.
 *
 * @param {string} baseDir Plugin root (import.meta.dirname/../.. from tests/js).
 * @param {string} name    File name, e.g. 'synthetic-stale-slot.trace.json'.
 * @returns {Object} Parsed trace.
 */
export function loadTrace(baseDir, name) {
    return JSON.parse(fs.readFileSync(path.join(baseDir, 'tests', 'fixtures', 'traces', name), 'utf8'));
}

/**
 * Reduce a replay result to objectid -> scorepct, for comparison against
 * `trace.expected.perItem`.
 *
 * @param {Object} itemscores objectid -> {scorepct, weighted, title}.
 * @returns {Object} objectid -> scorepct.
 */
export function toPerItem(itemscores) {
    const out = {};
    for (const oid of Object.keys(itemscores)) {
        out[oid] = itemscores[oid].scorepct;
    }
    return out;
}
