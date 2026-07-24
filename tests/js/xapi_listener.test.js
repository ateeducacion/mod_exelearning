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

// Side-effect import: the module exposes its API on window.exeXapiListener (and on
// module.exports). globals (describe/it/expect) are enabled in vitest.config.mts.
import '../../js/xapi_listener.js';

const { isStatementMessage, isTrustedOrigin, buildPayload, createListener } =
    window.exeXapiListener;

const HOST = 'https://moodle.example';

/**
 * Minimal XMLHttpRequest-like stub recording every open()/send() call.
 *
 * @returns {{open: Function, setRequestHeader: Function, send: Function, calls: Array}}
 */
function makeXhr() {
    const calls = [];
    return {
        calls,
        open(method, url, async) { calls.push({ method, url, async }); },
        setRequestHeader() {},
        send(body) { calls.push({ body }); },
    };
}

/**
 * Build a message event with the trusted host origin unless overridden.
 *
 * @param {Object} statement The statement to wrap, or a raw data override.
 * @param {Object} [opts] {origin, type, raw}
 * @returns {{origin: string, data: *}}
 */
function messageEvent(statement, opts = {}) {
    const origin = opts.origin !== undefined ? opts.origin : HOST;
    if (opts.raw !== undefined) { return { origin, data: opts.raw }; }
    return { origin, data: { type: opts.type || 'exe-xapi-statement', statement } };
}

function answered(id) {
    return { id, verb: { id: 'http://adlnet.gov/expapi/verbs/answered' }, result: { score: { scaled: 0.7 } } };
}

describe('xapi_listener pure helpers', () => {
    it('accepts only the exe-xapi-statement envelope', () => {
        expect(isStatementMessage({ type: 'exe-xapi-statement', statement: {} })).toBe(true);
        expect(isStatementMessage({ type: 'other', statement: {} })).toBe(false);
        expect(isStatementMessage({ type: 'exe-xapi-statement' })).toBe(false);
        expect(isStatementMessage(null)).toBe(false);
        expect(isStatementMessage('exe-xapi-statement')).toBe(false);
    });

    it('trusts only an exact host origin, never "*" or empty', () => {
        expect(isTrustedOrigin(HOST, HOST)).toBe(true);
        expect(isTrustedOrigin('https://evil.example', HOST)).toBe(false);
        expect(isTrustedOrigin('*', HOST)).toBe(false);
        expect(isTrustedOrigin('', HOST)).toBe(false);
        expect(isTrustedOrigin(HOST, '')).toBe(false);
    });

    it('builds the POST body with id/statement/registration/mode', () => {
        const body = JSON.parse(buildPayload(42, answered('a'), 'tok', 'grading', 'k1'));
        expect(body.id).toBe(42);
        expect(body.statement.id).toBe('a');
        expect(body.registration).toBe('tok');
        expect(body.mode).toBe('grading');
    });

    // SEC-04: the session key used to travel in the xapi_track.php query string, where
    // proxies and web-server access logs record it verbatim. It belongs in the body.
    it('carries the sesskey in the body', () => {
        const body = JSON.parse(buildPayload(42, answered('a'), 'tok', 'grading', 'secretkey'));
        expect(body.sesskey).toBe('secretkey');
    });
});

describe('xapi_listener createListener().handleMessage', () => {
    let xhr;
    let listener;

    beforeEach(() => {
        xhr = makeXhr();
        listener = createListener({
            cmid: 42,
            trackurl: '/mod/exelearning/xapi_track.php?id=42&mode=grading',
            registration: 'tok',
            mode: 'grading',
            allowedOrigin: HOST,
            xhrFactory: () => xhr,
        });
    });

    it('forwards a valid same-origin statement to xapi_track.php', () => {
        expect(listener.handleMessage(messageEvent(answered('s1')))).toBe(true);
        const open = xhr.calls.find((c) => c.method);
        const send = xhr.calls.find((c) => c.body);
        expect(open.method).toBe('POST');
        expect(open.url).toContain('xapi_track.php');
        expect(JSON.parse(send.body).statement.id).toBe('s1');
    });

    // SEC-04: the key travels in the body, and the listener must not append it to the
    // endpoint URL, where logs and proxies would keep it.
    it('sends the sesskey in the POST body and leaves the endpoint URL untouched', () => {
        const keyed = makeXhr();
        const withkey = createListener({
            cmid: 42,
            trackurl: '/mod/exelearning/xapi_track.php?id=42&mode=grading',
            registration: 'tok',
            mode: 'grading',
            sesskey: 'secretkey',
            allowedOrigin: HOST,
            xhrFactory: () => keyed,
        });
        withkey.handleMessage(messageEvent(answered('s1')));
        const open = keyed.calls.find((c) => c.method);
        const send = keyed.calls.find((c) => c.body);
        expect(JSON.parse(send.body).sesskey).toBe('secretkey');
        expect(open.url).not.toContain('sesskey');
    });

    it('drops a statement from a mismatched origin', () => {
        expect(listener.handleMessage(messageEvent(answered('s1'), { origin: 'https://evil.example' }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('drops a statement broadcast to "*" origin', () => {
        expect(listener.handleMessage(messageEvent(answered('s1'), { origin: '*' }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('ignores a non-statement message', () => {
        expect(listener.handleMessage(messageEvent(null, { raw: { type: 'something-else' } }))).toBe(false);
        expect(xhr.calls.length).toBe(0);
    });

    it('de-duplicates by statement.id within the page', () => {
        expect(listener.handleMessage(messageEvent(answered('dup')))).toBe(true);
        expect(listener.handleMessage(messageEvent(answered('dup')))).toBe(false);
        // Exactly one open + one send for the first delivery.
        expect(xhr.calls.filter((c) => c.method).length).toBe(1);
    });
});

/**
 * XHR factory whose instances expose resolve(status)/fail() so a test can drive the
 * async onload/onerror callbacks deterministically.
 *
 * @returns {Function & {xhrs: Array}}
 */
function makeControllableXhrFactory() {
    const xhrs = [];
    const factory = function () {
        const xhr = {
            status: 0,
            open(method, url) { this.method = method; this.url = url; },
            setRequestHeader() {},
            send(body) { this.body = body; },
            onload: null,
            onerror: null,
            resolve(status) { this.status = status; if (this.onload) { this.onload(); } },
            fail() { if (this.onerror) { this.onerror(); } },
        };
        xhrs.push(xhr);
        return xhr;
    };
    factory.xhrs = xhrs;
    return factory;
}

describe('xapi_listener createListener() bounded resend (DEC-0064)', () => {
    let factory;
    let timers;
    let listener;

    beforeEach(() => {
        factory = makeControllableXhrFactory();
        timers = [];                       // captured backoff callbacks, fired manually
        listener = createListener({
            cmid: 42,
            trackurl: '/mod/exelearning/xapi_track.php?id=42&mode=grading',
            registration: 'tok',
            allowedOrigin: HOST,
            xhrFactory: factory,
            maxRetries: 2,
            retryDelay: 1,
            schedule: (fn) => { timers.push(fn); },
        });
    });

    it('retries a grade statement on a transient non-2xx until the server confirms', () => {
        expect(listener.handleMessage(messageEvent(answered('R1')))).toBe(true);
        expect(factory.xhrs.length).toBe(1);

        factory.xhrs[0].resolve(500);       // first attempt fails transiently
        expect(timers.length).toBe(1);      // a retry was scheduled
        timers.shift()();                   // fire the backoff -> second attempt
        expect(factory.xhrs.length).toBe(2);

        factory.xhrs[1].resolve(200);       // server confirms
        expect(timers.length).toBe(0);      // no further retry queued
    });

    it('retries on a network/transport error (onerror)', () => {
        listener.handleMessage(messageEvent(answered('R2')));
        factory.xhrs[0].fail();             // transport error, no HTTP status
        expect(timers.length).toBe(1);
    });

    it('does NOT retry a 409 attempt-cap rejection (the grade must not be written)', () => {
        listener.handleMessage(messageEvent(answered('R3')));
        factory.xhrs[0].resolve(409);
        expect(timers.length).toBe(0);
        expect(factory.xhrs.length).toBe(1);
    });

    it('gives up after maxRetries so a dead server cannot loop forever', () => {
        listener.handleMessage(messageEvent(answered('R4')));  // attempt 0
        factory.xhrs[0].resolve(500);
        timers.shift()();                                      // attempt 1
        factory.xhrs[1].resolve(500);
        // attempt 1 >= maxRetries (1 from a 0-based count? no: maxRetries=2) -> one more
        timers.shift()();                                      // attempt 2
        factory.xhrs[2].resolve(500);
        // attempt 2 >= maxRetries(2): stop.
        expect(timers.length).toBe(0);
        expect(factory.xhrs.length).toBe(3);                  // initial + 2 retries
    });

    it('does not throw and reports false when the XHR cannot be created', () => {
        const throwing = createListener({
            cmid: 42,
            trackurl: '/x',
            allowedOrigin: HOST,
            xhrFactory: () => { throw new Error('boom'); },
            maxRetries: 0,
            schedule: () => {},
        });
        expect(throwing.handleMessage(messageEvent(answered('R5')))).toBe(false);
    });
});
