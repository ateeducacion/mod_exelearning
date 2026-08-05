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

// Side-effect imports: each module exposes its API on a window.* global (and on
// module.exports). scorm_tracker must load before the shim (the shim calls
// window.exeScormTracker). globals (describe/it/expect/vi) come from vitest.config.mjs.
import '../../js/scorm_tracker.js';
import '../../js/scorm_bridge_shim.js';
import '../../js/scorm_bridge_relay.js';

const shim = window.exeScormBridgeShim;
const relay = window.exeScormBridge;

/**
 * Build a fake iframe window for the shim: captures messages posted to the parent
 * and the registered 'message' listener so a test can drive the handshake. Uses the
 * real (happy-dom) document so the shared tracker's objectid resolution works.
 */
function makeFakeWin(overrides = {}) {
    const postedToParent = [];
    const listeners = {};
    const win = {
        exeScormTracker: window.exeScormTracker,
        parent: { postMessage: (msg) => postedToParent.push(msg) },
        origin: 'null',
        document,
        addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
        postedToParent,
        listeners,
        ...overrides,
    };
    return win;
}

/** Deliver a message to the shim's registered onMessage listener. */
function deliver(win, event) {
    (win.listeners.message || []).forEach((fn) => fn(event));
}

describe('shim createMemoryStorage', () => {
    it('behaves like a string-coercing in-memory Storage', () => {
        const s = shim.createMemoryStorage();
        expect(s.getItem('missing')).toBeNull();
        s.setItem('a', 1);
        expect(s.getItem('a')).toBe('1');     // coerced to string
        expect(s.length).toBe(1);
        expect(s.key(0)).toBe('a');
        s.removeItem('a');
        expect(s.getItem('a')).toBeNull();
        expect(s.length).toBe(0);
        s.setItem('b', 'x');
        s.clear();
        expect(s.length).toBe(0);
    });
});

describe('shim isSandboxedOpaque', () => {
    it('is true when the origin serializes to "null"', () => {
        expect(shim.isSandboxedOpaque({ origin: 'null' })).toBe(true);
    });

    it('is true when web storage access throws a SecurityError (opaque origin)', () => {
        const win = {
            origin: 'https://moodle.test',
            get localStorage() { throw new DOMException('blocked', 'SecurityError'); },
        };
        expect(shim.isSandboxedOpaque(win)).toBe(true);
    });

    it('is FALSE when storage throws a non-SecurityError in a real same-origin iframe (legacy, not opaque)', () => {
        // A QuotaExceededError (or a disabled-storage policy) must NOT be mistaken for an
        // opaque origin, or the shim would wrongly activate in legacy mode and lose grades.
        const quota = { origin: 'https://moodle.test', get localStorage() { throw new DOMException('full', 'QuotaExceededError'); } };
        expect(shim.isSandboxedOpaque(quota)).toBe(false);
        const generic = { origin: 'https://moodle.test', get localStorage() { throw new Error('nope'); } };
        expect(shim.isSandboxedOpaque(generic)).toBe(false);
    });

    it('is false for a same-origin window with working storage', () => {
        const win = { origin: 'https://moodle.test', localStorage: shim.createMemoryStorage() };
        expect(shim.isSandboxedOpaque(win)).toBe(false);
    });
});

describe('shim installStoragePolyfill', () => {
    it('replaces storage with in-memory implementations', () => {
        const win = {};
        shim.installStoragePolyfill(win);
        win.localStorage.setItem('k', 'v');
        expect(win.localStorage.getItem('k')).toBe('v');
        win.sessionStorage.setItem('s', '1');
        expect(win.sessionStorage.getItem('s')).toBe('1');
    });
});

describe('shim isParentMessage', () => {
    it('accepts config/ack and rejects everything else', () => {
        expect(shim.isParentMessage({ type: 'scorm', action: 'config' })).toBe(true);
        expect(shim.isParentMessage({ type: 'scorm', action: 'ack' })).toBe(true);
        expect(shim.isParentMessage({ type: 'scorm', action: 'track' })).toBe(false);
        expect(shim.isParentMessage({ type: 'other', action: 'config' })).toBe(false);
        expect(shim.isParentMessage(null)).toBe(false);
    });
});

describe('shim activate (handshake + transport)', () => {
    it('announces ready, then defines window.API', () => {
        const win = makeFakeWin();
        const handles = shim.activate(win);
        expect(handles).not.toBeNull();
        expect(win.API).toBe(handles.api);
        expect(win.postedToParent[0]).toEqual({ exelearningBridge: null, type: 'scorm', action: 'ready' });
    });

    it('queues scores until config arrives, then flushes them stamped with the nonce', () => {
        const win = makeFakeWin();
        shim.activate(win);
        // Score before the handshake completes: must be queued, not posted yet.
        win.API.LMSSetValue('cmi.core.score.raw', '80');
        win.API.LMSCommit();
        expect(win.postedToParent.filter((m) => m.action === 'track')).toHaveLength(0);
        // Parent replies with the nonce -> queued track message is flushed.
        deliver(win, { source: win.parent, data: { type: 'scorm', action: 'config', nonce: 'N1' } });
        const tracks = win.postedToParent.filter((m) => m.action === 'track');
        expect(tracks).toHaveLength(1);
        expect(tracks[0].exelearningBridge).toBe('N1');
        expect(tracks[0].cmi['cmi.core.score.raw']).toBe('80');
    });

    it('posts scores immediately once ready', () => {
        const win = makeFakeWin();
        shim.activate(win);
        deliver(win, { source: win.parent, data: { type: 'scorm', action: 'config', nonce: 'N2' } });
        win.API.LMSSetValue('cmi.core.lesson_status', 'completed');
        win.API.LMSCommit();
        const tracks = win.postedToParent.filter((m) => m.action === 'track');
        expect(tracks).toHaveLength(1);
        expect(tracks[0].exelearningBridge).toBe('N2');
        expect(tracks[0].cmi['cmi.core.lesson_status']).toBe('completed');
    });

    it('ignores messages whose source is not the parent window', () => {
        const win = makeFakeWin();
        shim.activate(win);
        win.API.LMSSetValue('cmi.core.score.raw', '50');
        win.API.LMSCommit();
        // A config from a foreign window must not unblock the queue.
        deliver(win, { source: { other: true }, data: { type: 'scorm', action: 'config', nonce: 'EVIL' } });
        expect(win.postedToParent.filter((m) => m.action === 'track')).toHaveLength(0);
    });
});

describe('shim boot', () => {
    it('activates in an opaque sandbox', () => {
        const win = makeFakeWin({ origin: 'null' });
        const handles = shim.boot(win);
        expect(handles).not.toBeNull();
        expect(win.API).toBeDefined();
        // Storage was polyfilled.
        win.localStorage.setItem('k', 'v');
        expect(win.localStorage.getItem('k')).toBe('v');
    });

    it('stays dormant (no window.API) outside an opaque sandbox', () => {
        const win = makeFakeWin({ origin: 'https://moodle.test', localStorage: shim.createMemoryStorage(), API: undefined });
        const handles = shim.boot(win);
        expect(handles).toBeNull();
        expect(win.API).toBeUndefined();
    });
});

describe('relay pure validators', () => {
    it('isTrackMessage requires the scorm/track shape with a cmi object', () => {
        expect(relay.isTrackMessage({ type: 'scorm', action: 'track', cmi: {} })).toBe(true);
        expect(relay.isTrackMessage({ type: 'scorm', action: 'track' })).toBe(false);
        expect(relay.isTrackMessage({ type: 'scorm', action: 'ready' })).toBe(false);
        expect(relay.isTrackMessage(null)).toBe(false);
    });

    it('isReadyMessage matches only the readiness announcement', () => {
        expect(relay.isReadyMessage({ type: 'scorm', action: 'ready' })).toBe(true);
        expect(relay.isReadyMessage({ type: 'scorm', action: 'track', cmi: {} })).toBe(false);
    });

    it('acceptTrack requires both a valid shape and the matching nonce', () => {
        const msg = { type: 'scorm', action: 'track', cmi: {}, exelearningBridge: 'N' };
        expect(relay.acceptTrack(msg, 'N')).toBe(true);
        expect(relay.acceptTrack(msg, 'OTHER')).toBe(false);
        expect(relay.acceptTrack({ type: 'scorm', action: 'track', cmi: {}, exelearningBridge: undefined }, 'N')).toBe(false);
    });

    it('acceptTrack never authenticates against a falsy expected nonce (no undefined===undefined)', () => {
        // A relay constructed without a nonce must reject even a perfectly-shaped message
        // that omits the field, rather than collapsing the nonce factor.
        expect(relay.acceptTrack({ type: 'scorm', action: 'track', cmi: {} }, undefined)).toBe(false);
        expect(relay.acceptTrack({ type: 'scorm', action: 'track', cmi: {}, exelearningBridge: undefined }, undefined)).toBe(false);
        expect(relay.acceptTrack({ type: 'scorm', action: 'track', cmi: {}, exelearningBridge: '' }, '')).toBe(false);
    });
});

describe('relay createRelay (message handling)', () => {
    function setup() {
        const cw = { postMessage: vi.fn() };
        const iframe = { contentWindow: cw };
        const doc = { getElementById: (id) => (id === 'exelearningobject' ? iframe : null) };
        const fetchCalls = [];
        const fetchImpl = (url, opts) => { fetchCalls.push({ url, opts }); return { catch: () => {} }; };
        const beaconCalls = [];
        const sendBeacon = (url, blob) => { beaconCalls.push({ url, blob }); return true; };
        const r = relay.createRelay(
            {
                iframeid: 'exelearningobject', cmid: 42, trackurl: '/track.php?id=42',
                session: 'tok', sesskey: 'SK', nonce: 'N',
            },
            { document: doc, window: { addEventListener: () => {} }, fetch: fetchImpl, sendBeacon }
        );
        return { r, cw, fetchCalls, beaconCalls };
    }

    it('replies to ready (from the iframe) with the config + nonce', () => {
        const { r, cw } = setup();
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'ready' } });
        expect(cw.postMessage).toHaveBeenCalledTimes(1);
        expect(cw.postMessage.mock.calls[0][0]).toMatchObject({ type: 'scorm', action: 'config', nonce: 'N' });
    });

    it('forwards a valid track message to track.php with the trusted identity', () => {
        const { r, cw, fetchCalls } = setup();
        r.onMessage({
            source: cw,
            data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: { 'cmi.core.score.raw': '80' }, itemscores: { 'ide-a': { scorepct: 80 } } },
        });
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0].url).toBe('/track.php?id=42');
        const body = JSON.parse(fetchCalls[0].opts.body);
        expect(body).toEqual({ id: 42, session: 'tok', sesskey: 'SK', cmi: { 'cmi.core.score.raw': '80' }, itemscores: { 'ide-a': { scorepct: 80 } } });
    });

    it('carries the sesskey in the POST body, never in the endpoint URL (SEC-04)', () => {
        // track.php authenticates with require_body_sesskey(), so a relay that leaves
        // the key out of the body is rejected on every write. The identity fields come
        // from this trusted parent; only cmi/itemscores cross the bridge.
        const { r, cw, fetchCalls } = setup();
        r.onMessage({
            source: cw,
            data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: {} },
        });
        expect(JSON.parse(fetchCalls[0].opts.body).sesskey).toBe('SK');
        expect(fetchCalls[0].url).not.toContain('sesskey');
    });

    it('ignores a track message from a window other than the iframe', () => {
        const { r, fetchCalls } = setup();
        r.onMessage({ source: { foreign: true }, data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: {} } });
        expect(fetchCalls).toHaveLength(0);
    });

    it('ignores a message when the iframe has no contentWindow (no null===null match)', () => {
        const fetchCalls = [];
        const iframe = { contentWindow: null };   // present element, not navigable.
        const doc = { getElementById: (id) => (id === 'exelearningobject' ? iframe : null) };
        const r = relay.createRelay(
            { iframeid: 'exelearningobject', cmid: 42, trackurl: '/track.php?id=42', session: 'tok', nonce: 'N' },
            { document: doc, window: { addEventListener: () => {} }, fetch: (url, opts) => { fetchCalls.push({ url, opts }); return { catch: () => {} }; } }
        );
        // event.source === null would equal a null contentWindow without the guard.
        r.onMessage({ source: null, data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: {} } });
        expect(fetchCalls).toHaveLength(0);
    });

    it('ignores a track message with the wrong nonce or a bad shape', () => {
        const { r, cw, fetchCalls } = setup();
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'track', exelearningBridge: 'WRONG', cmi: {} } });
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'track', exelearningBridge: 'N' } }); // no cmi
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'evil', exelearningBridge: 'N', cmi: {} } });
        expect(fetchCalls).toHaveLength(0);
    });

    it('flushBeacon sends the last forwarded payload on unload', () => {
        const { r, cw, beaconCalls } = setup();
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: { 'cmi.core.score.raw': '90' }, itemscores: {} } });
        r.flushBeacon();
        expect(beaconCalls).toHaveLength(1);
        expect(beaconCalls[0].url).toBe('/track.php?id=42');
    });

    it('xAPI-primary (disableTracking): a valid track message is accepted but POSTs nothing, while ready still handshakes', () => {
        const cw = { postMessage: vi.fn() };
        const iframe = { contentWindow: cw };
        const doc = { getElementById: (id) => (id === 'exelearningobject' ? iframe : null) };
        const fetchCalls = [];
        const beaconCalls = [];
        const r = relay.createRelay(
            { iframeid: 'exelearningobject', cmid: 42, trackurl: '/track.php?id=42', session: 'tok', nonce: 'N', disableTracking: true },
            {
                document: doc,
                window: { addEventListener: () => {} },
                fetch: (url, opts) => { fetchCalls.push({ url, opts }); return { catch: () => {} }; },
                sendBeacon: (url, blob) => { beaconCalls.push({ url, blob }); return true; },
            }
        );
        // A perfectly valid, authenticated SCORM score is dropped: the package is graded via xAPI.
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'track', exelearningBridge: 'N', cmi: { 'cmi.core.score.raw': '100' }, itemscores: {} } });
        expect(fetchCalls).toHaveLength(0);
        // The handshake still runs, so window.API exists and the iDevices can emit xAPI.
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'ready' } });
        expect(cw.postMessage).toHaveBeenCalledTimes(1);
        // And the unload beacon has nothing to flush (no score was ever buffered).
        r.flushBeacon();
        expect(beaconCalls).toHaveLength(0);
    });
});

describe('relay watchdog (no silent legacy fallback)', () => {
    function setupWatchdog() {
        const cw = { postMessage: () => {} };
        const iframe = { contentWindow: cw, style: { display: '' } };
        const blocked = { style: { display: 'none' } };
        const doc = { getElementById: (id) => (id === 'exelearningobject' ? iframe : (id === 'blk' ? blocked : null)) };
        let timerFn = null;
        const win = {
            addEventListener: () => {},
            setTimeout: (fn) => { timerFn = fn; return 1; },
            clearTimeout: () => { timerFn = null; },
        };
        const r = relay.createRelay(
            { iframeid: 'exelearningobject', nonce: 'N', blockedid: 'blk', watchdogms: 5 },
            { document: doc, window: win, fetch: () => ({ catch: () => {} }) }
        );
        return { r, cw, iframe, blocked, fire: () => timerFn && timerFn() };
    }

    it('reveals the blocked notice and hides the iframe when ready never arrives', () => {
        const { r, iframe, blocked, fire } = setupWatchdog();
        r.startWatchdog();
        fire();
        expect(blocked.style.display).toBe('');
        expect(iframe.style.display).toBe('none');
    });

    it('is cleared when the iframe signals ready (secure mode rendered)', () => {
        const { r, cw, blocked, fire } = setupWatchdog();
        r.startWatchdog();
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'ready' } });
        fire(); // no-op: watchdog was cleared.
        expect(blocked.style.display).toBe('none');
    });

    // Fast path: an iframe element fires 'load' even when its navigation ended in an
    // error page (e.g. an opaque token URL that 404s on a service-worker host that does
    // not control opaque subframes). The relay then only grants a short grace before the
    // notice, so it never sits behind the long flat watchdog (DEC-80-02 / Playground).
    function setupLoadWatchdog() {
        let loadHandler = null;
        const timers = [];
        const cw = { postMessage: () => {} };
        const iframe = {
            contentWindow: cw,
            style: { display: '' },
            addEventListener: (type, fn) => { if (type === 'load') { loadHandler = fn; } },
        };
        const blocked = { style: { display: 'none' } };
        const doc = { getElementById: (id) => (id === 'exelearningobject' ? iframe : (id === 'blk' ? blocked : null)) };
        const win = {
            addEventListener: () => {},
            setTimeout: (fn) => { timers.push(fn); return timers.length; },
            clearTimeout: () => {},
        };
        const r = relay.createRelay(
            { iframeid: 'exelearningobject', nonce: 'N', blockedid: 'blk', watchdogms: 100000, gracems: 5 },
            { document: doc, window: win, fetch: () => ({ catch: () => {} }) }
        );
        return { r, cw, iframe, blocked, timers, load: () => loadHandler && loadHandler() };
    }

    it('reveals the notice on the short grace timer after the iframe loads, not the long watchdog', () => {
        const { r, iframe, blocked, timers, load } = setupLoadWatchdog();
        r.startWatchdog();
        expect(timers).toHaveLength(1);   // only the flat watchdog so far.
        load();                            // iframe finished loading (e.g. the 404 page).
        expect(timers).toHaveLength(2);   // a short grace timer was armed.
        timers[1]();                       // fire ONLY the grace timer (not the long one).
        expect(blocked.style.display).toBe('');
        expect(iframe.style.display).toBe('none');
    });

    it('does not arm the grace timer when the iframe is already ready before it loads', () => {
        const { r, cw, blocked, timers, load } = setupLoadWatchdog();
        r.startWatchdog();
        r.onMessage({ source: cw, data: { type: 'scorm', action: 'ready' } });
        load();                            // ready already seen -> no grace timer.
        expect(timers).toHaveLength(1);
        timers.forEach((fn) => fn());      // even firing everything leaves it hidden.
        expect(blocked.style.display).toBe('none');
    });
});

describe('shim extra branches (coverage)', () => {
    it('treats a window whose origin read throws as opaque', () => {
        const win = { get origin() { throw new Error('blocked'); } };
        expect(shim.isSandboxedOpaque(win)).toBe(true);
    });

    it('installStoragePolyfill never throws even when storage cannot be redefined', () => {
        const win = Object.preventExtensions({});
        expect(() => shim.installStoragePolyfill(win)).not.toThrow();
    });

    it('resolves per-iDevice scores from its own document on suspend_data', () => {
        document.body.innerHTML = '<div class="idevice_node" id="ide-x"></div>';
        const win = makeFakeWin();
        shim.activate(win);
        deliver(win, { source: win.parent, data: { type: 'scorm', action: 'config', nonce: 'N' } });
        win.API.LMSSetValue('cmi.suspend_data', '1. "Q"; Score: 60%; Weight: 30%');
        win.API.LMSCommit();
        const tracks = win.postedToParent.filter((m) => m.action === 'track');
        expect(tracks.length).toBeGreaterThan(0);
        expect(tracks[tracks.length - 1].itemscores).toEqual({ 'ide-x': { scorepct: 60, weighted: 30, title: 'Q' } });
    });
});

describe('relay init wiring (coverage)', () => {
    it('init registers message + pagehide listeners (and starts the watchdog)', () => {
        const events = {};
        const win = { addEventListener: (t, fn) => { events[t] = fn; }, setTimeout: () => 1, clearTimeout: () => {} };
        const doc = { getElementById: () => null };
        const r = relay.createRelay(
            { iframeid: 'x', nonce: 'N', blockedid: 'blk' },
            { document: doc, window: win, fetch: () => ({ catch: () => {} }) }
        );
        r.init();
        expect(typeof events.message).toBe('function');
        expect(typeof events.pagehide).toBe('function');
    });

    it('bootstrap init() creates a relay and starts it', () => {
        const r = relay.init({ iframeid: 'x', nonce: 'N' });
        expect(typeof r.onMessage).toBe('function');
        expect(typeof r.postTrack).toBe('function');
    });
});
