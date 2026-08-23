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
 * Characterization test for the multi-page suspend_data slot collision (scenario S6).
 *
 * The trace is hand-authored (tests/fixtures/traces/synthetic-stale-slot.trace.json)
 * and its `expected` block is the oracle: it was computed from what the learner
 * actually did, never from what the code produces. The test therefore states what
 * SHOULD happen; when the tracker under test disagrees, the failure message names the
 * item that was mis-graded, so a red run is a finding rather than a mystery.
 *
 * The scenario: page 0 holds objA1/objA2, page 1 holds objB1/objB2. eXeLearning's
 * producer rewrites the WHOLE lmsData map on every score, so when page 1 reports
 * objB1's 30% the payload still carries page 0's "A2 40%" in slot 2 — the slot that
 * page 1's DOM resolves to objB2. objB2 was never answered and must stay ungraded.
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { replayScormTrace, loadTrace, toPerItem } from './trace-replay.helper.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = path.resolve(HERE, '..', '..');
const TRACKER = path.join(PLUGIN_ROOT, 'js', 'scorm_tracker.js');

/** Render a per-item map as a stable, readable one-liner for failure messages. */
function fmt(perItem) {
    const keys = Object.keys(perItem).sort();
    if (!keys.length) { return '{}'; }
    return '{ ' + keys.map((k) => k + ': ' + perItem[k]).join(', ') + ' }';
}

describe('trace replay: multi-page suspend_data slot collision (S6)', () => {
    const trace = loadTrace(PLUGIN_ROOT, 'synthetic-stale-slot.trace.json');
    let result;
    let perItem;

    beforeAll(() => {
        result = replayScormTrace(trace, { trackerPath: TRACKER });
        perItem = toPerItem(result.itemscores);
        // Characterization: always report what this tracker actually produced, so the
        // number is on the record even when the run is green.
        // eslint-disable-next-line no-console
        console.log('[trace-replay] tracker   : ' + TRACKER);
        // eslint-disable-next-line no-console
        console.log('[trace-replay] expected  : ' + fmt(trace.expected.perItem)
            + ' (ungraded: ' + trace.expected.ungraded.join(', ') + ')');
        // eslint-disable-next-line no-console
        console.log('[trace-replay] produced  : ' + fmt(perItem));
        // eslint-disable-next-line no-console
        console.log('[trace-replay] posts     : ' + result.posts.length);
    });

    it('replays the whole trace and POSTs at least once', () => {
        expect(result.calls.map((c) => c.method)).toEqual(
            trace.scorm.slice().sort((a, b) => a.seq - b.seq).map((c) => c.method)
        );
        expect(result.posts.length).toBeGreaterThan(0);
        expect(result.posts[0].method).toBe('POST');
    });

    it('grades every item the learner actually answered', () => {
        for (const oid of Object.keys(trace.expected.perItem)) {
            const want = trace.expected.perItem[oid];
            expect(
                perItem[oid],
                'item ' + oid + ': expected ' + want + '%, produced '
                    + (Object.prototype.hasOwnProperty.call(perItem, oid) ? perItem[oid] + '%' : 'NO SCORE AT ALL')
                    + '. Full produced map ' + fmt(perItem) + ', expected ' + fmt(trace.expected.perItem) + '.'
            ).toBe(want);
        }
    });

    it('never grades an iDevice the learner never answered (stale slot must not leak)', () => {
        for (const oid of trace.expected.ungraded) {
            const leaked = Object.prototype.hasOwnProperty.call(perItem, oid);
            expect(
                leaked,
                leaked
                    ? 'CONTAMINATION: ' + oid + ' was never answered but was graded '
                        + perItem[oid] + '% — it inherited the stale suspend_data entry sitting in its '
                        + 'page-local slot. Produced ' + fmt(perItem) + ', expected ' + fmt(trace.expected.perItem)
                        + ' with ' + oid + ' absent.'
                    : ''
            ).toBe(false);
        }
    });

    it('produces exactly the expected per-item map (no extra, no missing)', () => {
        expect(perItem).toEqual(trace.expected.perItem);
    });
});
