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
 * Vitest unit tests for the SCORM 1.2 tracker (js/scorm_tracker.js).
 *
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Side-effect import: the module exposes its API on window.exeScormTracker (and on
// module.exports), so reading the global works whether Vitest treats the file as ESM
// or CJS. globals (describe/it/expect/vi) are enabled in vitest.config.mjs.
import '../../js/scorm_tracker.js';

const { parseSuspend, resolveObjectMap, captureItemScores, buildPayload, createScormApi } =
    window.exeScormTracker;

/**
 * Minimal XMLHttpRequest-like stub. Records calls and lets a test control the
 * resolved status and whether onload fires (async path).
 */
function makeXhr(status, { fireLoad = true } = {}) {
    const calls = [];
    const xhr = {
        status,
        onload: null,
        onerror: null,
        open(method, url, async) { calls.push({ method, url, async }); },
        setRequestHeader() {},
        send(payload) {
            xhr.lastPayload = payload;
            // The synchronous path inspects xhr.status right after send(); the async
            // path waits for onload. Fire onload to emulate a completed async request.
            if (fireLoad && typeof xhr.onload === 'function') { xhr.onload(); }
        },
    };
    xhr.calls = calls;
    return xhr;
}

describe('parseSuspend', () => {
    it('parses score and weighted percentages', () => {
        const r = parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.');
        expect(r[1]).toEqual({ title: 'Quiz', scorepct: 60, weighted: 30 });
    });

    it('accepts a comma decimal separator (es_ES/fr_FR/de_DE)', () => {
        const r = parseSuspend('1. "Quiz"; score: 60,5%; weighted: 30,25%.');
        expect(r[1].scorepct).toBe(60.5);
        expect(r[1].weighted).toBe(30.25);
    });

    it('clamps the score percentage to a 0–100 ceiling', () => {
        const r = parseSuspend('1. "Quiz"; score: 120%; weighted: 20%.');
        expect(r[1].scorepct).toBe(100);
    });

    it('parses several entries separated by ".\\t"', () => {
        const r = parseSuspend('1. "A"; score: 60%; weighted: 30%.\t2. "B"; score: 70%; weighted: 35%.');
        expect(Object.keys(r)).toEqual(['1', '2']);
        expect(r[1].title).toBe('A');
        expect(r[2]).toEqual({ title: 'B', scorepct: 70, weighted: 35 });
    });

    it('skips malformed lines (including negative numbers the format never emits)', () => {
        const r = parseSuspend('1. "Ok"; score: 50%; weighted: 25%.\tgarbage line\t3. "Neg"; score: -5%; weighted: 0%.');
        expect(Object.keys(r)).toEqual(['1']);
    });

    it('returns an empty map for empty/falsy input', () => {
        expect(parseSuspend('')).toEqual({});
        expect(parseSuspend(null)).toEqual({});
        expect(parseSuspend(undefined)).toEqual({});
    });

    it('tolerates a trailing "; <label>: <n>" group after the weight, with and without it', () => {
        // A legacy writer that appends a labelled per-iDevice field (exelearning #2322
        // adds "; Estado: <0|1|2>") must not make the record disappear from the
        // gradebook: the suffix is accepted and ignored, the record parses as before.
        const r = parseSuspend(
            '1. "Quiz"; Puntuación: 60%; Peso: 30%; Estado: 2.\t'
            + '2. "Plain"; Puntuación: 70%; Peso: 35%.\t'
            + '3. "Last"; Puntuación: 80%; Peso: 35%; Estado: 1.'
        );
        expect(r[1]).toEqual({ title: 'Quiz', scorepct: 60, weighted: 30 });
        expect(r[2]).toEqual({ title: 'Plain', scorepct: 70, weighted: 35 });
        expect(r[3]).toEqual({ title: 'Last', scorepct: 80, weighted: 35 });
    });

    it('still rejects a suffix that is not a labelled number', () => {
        expect(parseSuspend('1. "Quiz"; score: 60%; weighted: 30%; garbage.')).toEqual({});
        expect(parseSuspend('1. "Quiz"; score: 60%; weighted: 30% trailing.')).toEqual({});
    });
});

describe('resolveObjectMap', () => {
    it('maps 1-based page index N to each .idevice_node id', () => {
        document.body.innerHTML =
            '<div class="idevice_node" id="ide-aaa"></div><div class="idevice_node" id="ide-bbb"></div>';
        expect(resolveObjectMap(document)).toEqual({ 1: 'ide-aaa', 2: 'ide-bbb' });
    });

    it('skips nodes without an id', () => {
        document.body.innerHTML =
            '<div class="idevice_node"></div><div class="idevice_node" id="ide-bbb"></div>';
        // The id-less first node leaves slot 1 empty; the second keeps its index (2).
        expect(resolveObjectMap(document)).toEqual({ 2: 'ide-bbb' });
    });

    it('returns null when there is no document or no nodes', () => {
        expect(resolveObjectMap(null)).toBeNull();
        document.body.innerHTML = '<p>no idevices here</p>';
        expect(resolveObjectMap(document)).toBeNull();
    });
});

describe('captureItemScores', () => {
    const objmap = { 1: 'ide-aaa', 2: 'ide-bbb' };

    it('routes a newly scored entry by stable objectid', () => {
        const parsed = parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.');
        const { delta, prev } = captureItemScores(parsed, {}, objmap);
        expect(delta).toEqual({ 'ide-aaa': { scorepct: 60, weighted: 30, title: 'Quiz' } });
        // The next-call baseline is keyed by objectid, never by the page-local N.
        expect(prev).toEqual({ 'ide-aaa': { scorepct: 60, weighted: 30, title: 'Quiz' } });
    });

    it('emits only the entry that changed since the previous parse', () => {
        const first = captureItemScores(parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.'), {}, objmap);
        const newParsed = parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.\t2. "Essay"; score: 80%; weighted: 40%.');
        const { delta } = captureItemScores(newParsed, first.prev, objmap);
        expect(Object.keys(delta)).toEqual(['ide-bbb']);
    });

    it('emits a page-2 iDevice landing on a slot whose page-1 occupant scored identically', () => {
        // The legacy suspend_data format reuses the page-local slot N across pages.
        // Page 1: its iDevice at N=1 scores 60/30. Page 2: a DIFFERENT iDevice also
        // sits at N=1 and happens to score the same 60/30. An N-keyed baseline
        // compares the two as equal and silently drops the page-2 answer, whose
        // gradebook column is then never written.
        const page1 = captureItemScores(
            parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.'),
            {},
            { 1: 'ide-aaa' },
        );

        const page2 = captureItemScores(
            parseSuspend('1. "OtherQuiz"; score: 60%; weighted: 30%.'),
            page1.prev,
            { 1: 'ide-bbb' },
        );

        expect(page2.delta).toEqual({ 'ide-bbb': { scorepct: 60, weighted: 30, title: 'OtherQuiz' } });
    });

    it('does not re-emit unchanged scores when the learner returns to a page', () => {
        // The baseline carries entries for pages not currently loaded, so navigating
        // back does not flood the endpoint with already-persisted scores.
        const page1 = captureItemScores(
            parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.'),
            {},
            { 1: 'ide-aaa' },
        );
        const page2 = captureItemScores(
            parseSuspend('1. "Essay"; score: 80%; weighted: 40%.'),
            page1.prev,
            { 1: 'ide-bbb' },
        );

        const backOnPage1 = captureItemScores(
            parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.'),
            page2.prev,
            { 1: 'ide-aaa' },
        );

        expect(backOnPage1.delta).toEqual({});
        // And a NEW score there is still caught against the carried-forward baseline.
        const rescored = captureItemScores(
            parseSuspend('1. "Quiz"; score: 90%; weighted: 30%.'),
            backOnPage1.prev,
            { 1: 'ide-aaa' },
        );
        expect(rescored.delta).toEqual({ 'ide-aaa': { scorepct: 90, weighted: 30, title: 'Quiz' } });
    });

    it('drops stale cross-page entries that do not resolve in the current DOM (multi-page fix)', () => {
        // Entry 2 changed, but the loaded page only knows index 1 -> objectid.
        const newParsed = parseSuspend('2. "OtherPage"; score: 90%; weighted: 45%.');
        const { delta } = captureItemScores(newParsed, {}, { 1: 'ide-aaa' });
        expect(delta).toEqual({});
    });
});

describe('buildPayload', () => {
    it('serializes the track.php POST body', () => {
        const body = buildPayload(42, 'tok', { 'cmi.core.score.raw': '60' }, { 'ide-aaa': { scorepct: 60 } }, 'k1');
        expect(JSON.parse(body)).toEqual({
            id: 42,
            session: 'tok',
            cmi: { 'cmi.core.score.raw': '60' },
            itemscores: { 'ide-aaa': { scorepct: 60 } },
            sesskey: 'k1',
        });
    });

    // SEC-04: the session key used to travel in the track.php query string, where
    // proxies and web-server access logs record it verbatim. It belongs in the body.
    it('carries the sesskey in the body', () => {
        const body = JSON.parse(buildPayload(42, 'tok', {}, {}, 'secretkey'));
        expect(body.sesskey).toBe('secretkey');
    });
});

describe('createScormApi state machine', () => {
    // Capture the autocommit callback so a test can fire it deterministically instead
    // of waiting on a real 500 ms timer.
    let scheduled;
    function baseConfig(overrides = {}) {
        scheduled = null;
        return {
            cmid: 42,
            trackurl: 'https://example.test/track.php',
            session: 'tok',
            bindUnload: false,
            getScoringDocument: () => document,
            setTimeout: (fn) => { scheduled = fn; return 1; },
            clearTimeout: () => { scheduled = null; },
            ...overrides,
        };
    }

    it('exposes the SCORM 1.2 contract', () => {
        const { api } = createScormApi(baseConfig());
        expect(api.LMSInitialize()).toBe('true');
        expect(api.LMSGetValue('cmi.core.score.raw')).toBe('');
        api.LMSSetValue('cmi.core.student_id', 'u7');
        expect(api.LMSGetValue('cmi.core.student_id')).toBe('u7');
        expect(api.LMSGetLastError()).toBe('0');
    });

    // SEC-04: the tracker must send the key it was configured with in the body and
    // must never append it to the endpoint URL, where logs and proxies would keep it.
    it('sends the sesskey in the POST body and leaves the endpoint URL untouched', () => {
        const xhr = makeXhr(200);
        const { api } = createScormApi(baseConfig({ xhrFactory: () => xhr, sesskey: 'secretkey' }));
        api.LMSSetValue('cmi.core.score.raw', '80');
        scheduled();
        expect(JSON.parse(xhr.lastPayload).sesskey).toBe('secretkey');
        expect(xhr.calls[0].url).toBe('https://example.test/track.php');
    });

    it('autocommits on a score key and clears dirty on a 2xx response', () => {
        const xhr = makeXhr(200);
        const { api } = createScormApi(baseConfig({ xhrFactory: () => xhr }));
        api.LMSSetValue('cmi.core.score.raw', '80');
        expect(typeof scheduled).toBe('function');   // autocommit was scheduled
        scheduled();                                   // fire the debounced send(false)
        expect(xhr.calls[0]).toMatchObject({ method: 'POST', async: true });
        // dirty cleared by the 2xx onload -> a follow-up Commit sends nothing new.
        const before = xhr.calls.length;
        expect(api.LMSCommit()).toBe('true');
        expect(xhr.calls.length).toBe(before);
    });

    it('keeps dirty after a failed autocommit so the grade is retried (never silently lost)', () => {
        const failing = makeXhr(500);
        const { api } = createScormApi(baseConfig({ xhrFactory: () => failing }));
        api.LMSSetValue('cmi.core.score.raw', '80');
        scheduled();                                   // async send fails (500), dirty stays set
        expect(failing.calls.length).toBe(1);
        // Still dirty: the next Commit re-sends the buffered score (synchronously)
        // rather than silently dropping it. The server is still failing, so the SCORM
        // contract reports 'false', but the retry is what guards the grade.
        expect(api.LMSCommit()).toBe('false');
        expect(failing.calls.length).toBe(2);
        expect(failing.calls[1]).toMatchObject({ async: false }); // Commit is synchronous
    });

    it('LMSFinish flushes synchronously and reports failure via LMSCommit', () => {
        const ok = makeXhr(200);
        const a = createScormApi(baseConfig({ xhrFactory: () => ok }));
        a.api.LMSSetValue('cmi.core.lesson_status', 'completed');
        expect(a.api.LMSFinish()).toBe('true');
        expect(ok.calls[ok.calls.length - 1]).toMatchObject({ async: false });

        const bad = makeXhr(500);
        const b = createScormApi(baseConfig({ xhrFactory: () => bad }));
        b.api.LMSSetValue('cmi.score.raw', '10');
        expect(b.api.LMSCommit()).toBe('false');
    });

    it('flushes synchronously on beforeunload and destroy() cancels a pending autocommit', () => {
        const xhr = makeXhr(200);
        let timer = null;
        const { api, destroy } = createScormApi({
            cmid: 1,
            trackurl: 'https://example.test/track.php',
            session: 'tok',
            bindUnload: true,
            getScoringDocument: () => document,
            xhrFactory: () => xhr,
            setTimeout: (fn) => { timer = fn; return 7; },
            clearTimeout: () => { timer = null; },
        });
        api.LMSSetValue('cmi.core.score.raw', '50');   // schedules an autocommit
        expect(typeof timer).toBe('function');
        destroy();                                      // cancels the pending timer
        expect(timer).toBeNull();
        // Closing the tab must still flush the buffered (dirty) score synchronously.
        window.dispatchEvent(new window.Event('beforeunload'));
        expect(xhr.calls.some((c) => c.async === false)).toBe(true);
    });

    it('captures per-iDevice scores by objectid into the committed payload', () => {
        document.body.innerHTML = '<div class="idevice_node" id="ide-aaa"></div>';
        const xhr = makeXhr(200);
        const { api } = createScormApi(baseConfig({ xhrFactory: () => xhr }));
        api.LMSSetValue('cmi.suspend_data', '1. "Quiz"; score: 60%; weighted: 30%.');
        expect(typeof scheduled).toBe('function');     // suspend_data write schedules a commit
        api.LMSCommit();
        const body = JSON.parse(xhr.lastPayload);
        expect(body.id).toBe(42);
        expect(body.session).toBe('tok');
        expect(body.itemscores).toEqual({ 'ide-aaa': { scorepct: 60, weighted: 30, title: 'Quiz' } });
    });

});

describe('parseSuspend (versioned exe12 payload, core PR #2209)', () => {
    // Captured verbatim from a package built by the new SCORM 1.2 runtime.
    const REAL = 'exe12/1|ide-a;7;0;4;100;25;0;100';

    it('parses a real captured record, keyed by the objectid it carries', () => {
        expect(parseSuspend(REAL)).toEqual({
            'ide-a': { title: '', scorepct: 100, weighted: 25, objectid: 'ide-a' },
        });
    });

    it('reads the score from field [4], never from answered/total', () => {
        // The real record above says answered=0 of total=4 while scoring 100: an
        // iDevice may report a score without reporting question counters.
        expect(parseSuspend(REAL)['ide-a'].scorepct).toBe(100);
    });

    it('normalises the score into 0-100 with the record own min/max window', () => {
        const r = parseSuspend('exe12/1|q;1;2;4;5;75;0;10');
        expect(r.q.scorepct).toBe(50);
        expect(r.q.weighted).toBe(75);
    });

    it('clamps an out-of-window score and repairs a degenerate min/max range', () => {
        expect(parseSuspend('exe12/1|q;1;0;0;250;1;0;100').q.scorepct).toBe(100);
        expect(parseSuspend('exe12/1|q;1;0;0;-5;1;0;100').q.scorepct).toBe(0);
        // max <= min cannot normalise anything; the producer falls back to a
        // 100-wide window and so do we (score 30 in 0..100).
        expect(parseSuspend('exe12/1|q;1;0;0;30;1;0;0').q.scorepct).toBe(30);
    });

    it('percent-decodes the activity id', () => {
        expect(Object.keys(parseSuspend('exe12/1|ide%20a%7Cb;1;0;0;10;1;0;100'))).toEqual(['ide a|b']);
    });

    it('parses several records separated by "|"', () => {
        const r = parseSuspend('exe12/1|a;1;0;0;10;1;0;100|b;1;0;0;20;2;0;100');
        expect(Object.keys(r)).toEqual(['a', 'b']);
        expect(r.b.scorepct).toBe(20);
    });

    it('skips records that must not reach a gradebook column', () => {
        const r = parseSuspend(
            'exe12/1|ok;1;0;0;40;1;0;100'          // evaluable, scored: kept
            + '|noscore;1;0;0;;1;0;100'            // evaluable but no result yet
            + '|notevaluable;6;0;0;80;1;0;100'     // completionRequired+completed, not evaluable
            + '|3;40;1'                            // unclaimed migrated legacy pool record
            + '|;1;0;0;50;1;0;100'                 // empty id
            + '|short;1;0;0'                       // truncated record
            + '|bad;x;0;0;50;1;0;100'              // unreadable flags
            + '|ide%zz;1;0;0;50;1;0;100'           // malformed percent escape in the id
        );
        expect(Object.keys(r)).toEqual(['ok']);
    });

    it('accepts a header with no records at all', () => {
        expect(parseSuspend('exe12/1')).toEqual({});
    });

    it('returns nothing for a version it does not understand, rather than misparsing', () => {
        // A future revision may reorder or repurpose fields; publishing a wrong grade
        // is worse than publishing none.
        expect(parseSuspend('exe12/2|ide-a;7;0;4;100;25;0;100')).toEqual({});
        expect(parseSuspend('exe12/x|ide-a;7;0;4;100;25;0;100')).toEqual({});
        expect(parseSuspend('exe12/|ide-a;7;0;4;100;25;0;100')).toEqual({});
    });

    it('still parses the legacy format (the header is what selects the parser)', () => {
        expect(parseSuspend('1. "Quiz"; score: 60%; weighted: 30%.')[1].title).toBe('Quiz');
    });
});
