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

// Regression coverage for the vendored pipwerks wrapper (assets/scorm/SCORM_API_wrapper.js).
//
// The wrapper is normally "out of scope" for these JS suites, but its API lookup is
// grade-critical: it is what eXeLearning's content calls to record SCORM scores, and a
// defect there silently lost EVERY score in the secure (opaque-origin) iframe mode
// (DEC-80-01/DEC-80-03). The vendored get() had been altered to look only in window.parent
// and skip the current window. In secure mode the SCORM API is provided LOCALLY by the
// in-iframe bridge shim (js/scorm_bridge_shim.js) as window.API, and the Moodle parent is
// a cross-origin/opaque frame whose property access throws SecurityError. So the altered
// get() reached straight into the opaque parent, threw, init() never activated the
// connection, and LMSSetValue/LMSCommit became no-ops -> no attempt rows were written.
//
// These tests lock in the corrected behaviour: check the current window first, fall back
// to a same-origin ancestor (legacy mode), and never let an opaque ancestor abort lookup.
//
// The wrapper is a classic browser script (top-level `var pipwerks = {}`, no module
// exports), so it is loaded via new Function() with a fully controllable `window`, letting
// each test model a specific frame topology (local API, opaque parent, same-origin parent).

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const wrapperSrc = fs.readFileSync(
    path.join(here, '../../assets/scorm/SCORM_API_wrapper.js'),
    'utf8'
);

/**
 * Load a fresh pipwerks instance bound to the given fake window. find()/get() read the
 * `window` free variable, which we supply as the factory argument, so the wrapper sees
 * exactly the frame topology the test sets up. A fresh instance per call avoids shared
 * state (pipwerks.SCORM.version, connection.isActive) leaking between tests.
 *
 * @param {Object} win The fake window (must carry a console for trace()).
 * @returns {Object} The wrapper's pipwerks namespace.
 */
function loadPipwerks(win) {
    win.console = win.console || globalThis.console;
    const factory = new Function('window', 'document', wrapperSrc + '\n;return pipwerks;');
    return factory(win, win.document || {});
}

/**
 * A minimal SCORM 1.2 API surface. Records LMSInitialize/LMSSetValue/LMSCommit calls so a
 * test can assert the save path was actually driven. LMSGetLastError returns "0" so
 * pipwerks treats the session as healthy.
 *
 * @param {string} tag An identity marker so tests can assert WHICH API was returned.
 * @returns {Object}
 */
function makeApi(tag) {
    const calls = [];
    return {
        tag,
        calls,
        LMSInitialize: (v) => { calls.push(['LMSInitialize', v]); return 'true'; },
        LMSFinish: (v) => { calls.push(['LMSFinish', v]); return 'true'; },
        LMSGetValue: (k) => { calls.push(['LMSGetValue', k]); return ''; },
        LMSSetValue: (k, v) => { calls.push(['LMSSetValue', k, v]); return 'true'; },
        LMSCommit: (v) => { calls.push(['LMSCommit', v]); return 'true'; },
        LMSGetLastError: () => '0',
        LMSGetErrorString: () => '',
        LMSGetDiagnostic: () => '',
    };
}

/** A frame whose .API / .API_1484_11 access throws, mimicking an opaque cross-origin parent. */
function makeOpaqueFrame() {
    const frame = {};
    const blow = () => { throw new Error('SecurityError: Blocked a frame with origin "null" from accessing a cross-origin frame.'); };
    Object.defineProperty(frame, 'API', { get: blow });
    Object.defineProperty(frame, 'API_1484_11', { get: blow });
    return frame;
}

describe('vendored pipwerks API.get (secure-mode regression)', () => {
    it('returns the LOCAL window.API, not the parent\'s (current window checked first)', () => {
        // Secure mode: the bridge shim put window.API in THIS frame; the parent also has a
        // (different) API. The old build returned the parent's; we must return the local one.
        const localApi = makeApi('local');
        const parent = { API: makeApi('parent') };
        parent.parent = parent;                       // stops the upward walk at the parent
        const win = { API: localApi, parent, document: {} };

        const pipwerks = loadPipwerks(win);

        expect(pipwerks.SCORM.API.get()).toBe(localApi);
    });

    it('does not throw when the parent frame is opaque/cross-origin, and finds the local API', () => {
        // The exact failure mode: reaching into the opaque parent threw SecurityError.
        const localApi = makeApi('local');
        const win = { API: localApi, parent: makeOpaqueFrame(), document: {} };

        const pipwerks = loadPipwerks(win);

        let got;
        expect(() => { got = pipwerks.SCORM.API.get(); }).not.toThrow();
        expect(got).toBe(localApi);
    });

    it('returns null (never throws) when no API is reachable and the parent is opaque', () => {
        const win = { parent: makeOpaqueFrame(), document: {} };   // no local API

        const pipwerks = loadPipwerks(win);

        let got = 'unset';
        expect(() => { got = pipwerks.SCORM.API.get(); }).not.toThrow();
        expect(got == null).toBe(true);
    });

    it('still walks up to a same-origin parent when there is no local API (legacy mode)', () => {
        // Legacy same-origin mode: no local API, the Moodle parent hosts window.API.
        const parentApi = makeApi('parent');
        const parent = { API: parentApi };
        parent.parent = parent;                       // stops the walk
        const win = { parent, document: {} };

        const pipwerks = loadPipwerks(win);

        expect(pipwerks.SCORM.API.get()).toBe(parentApi);
    });
});

describe('vendored pipwerks init (secure bridge end to end)', () => {
    it('activates the connection through a LOCAL API even with an opaque parent', () => {
        const localApi = makeApi('local');
        const win = { API: localApi, parent: makeOpaqueFrame(), document: {} };

        const pipwerks = loadPipwerks(win);
        const ok = pipwerks.SCORM.init();             // init === connection.initialize

        expect(ok).toBe(true);
        expect(pipwerks.SCORM.connection.isActive).toBe(true);
        expect(localApi.calls.some((c) => c[0] === 'LMSInitialize')).toBe(true);
    });

    it('set() reaches the local API once the connection is active (the score actually saves)', () => {
        const localApi = makeApi('local');
        const win = { API: localApi, parent: makeOpaqueFrame(), document: {} };

        const pipwerks = loadPipwerks(win);
        pipwerks.SCORM.init();
        const saved = pipwerks.SCORM.set('cmi.core.score.raw', '100');

        expect(saved).toBe(true);
        expect(localApi.calls).toContainEqual(['LMSSetValue', 'cmi.core.score.raw', '100']);
    });
});
