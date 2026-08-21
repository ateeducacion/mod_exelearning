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
 * Replay of a package that writes the VERSIONED `exe12/` cmi.suspend_data payload
 * (eXeLearning core PR #2209), the format that replaces the page-local legacy lines.
 *
 * Its records name their own activity id, so the whole page-local slot problem the
 * other two replay specs are about simply does not arise: page 1 rewriting the entire
 * map cannot contaminate a page-2 iDevice, and a page-2 iDevice that happens to score
 * exactly like a page-1 one cannot be mistaken for it. The fixture also carries the
 * three record shapes the parser must refuse — a non-evaluable one, one with no score
 * yet, and an unclaimed migrated legacy pool record — so a green run means they were
 * dropped, not silently graded.
 *
 * The `expected` block is the oracle: hand-computed from the payload's own fields,
 * never from what the parser returns.
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

describe('trace replay: versioned exe12/1 suspend_data (core PR #2209)', () => {
    const trace = loadTrace(PLUGIN_ROOT, 'exe12-versioned-two-pages.trace.json');
    let result;
    let perItem;

    beforeAll(() => {
        result = replayScormTrace(trace, { trackerPath: TRACKER });
        perItem = toPerItem(result.itemscores);
        // eslint-disable-next-line no-console
        console.log('[trace-replay/exe12] expected : ' + fmt(trace.expected.perItem));
        // eslint-disable-next-line no-console
        console.log('[trace-replay/exe12] produced : ' + fmt(perItem));
    });

    it('replays the whole trace and POSTs at least once', () => {
        expect(result.posts.length).toBeGreaterThan(0);
        expect(result.posts[0].method).toBe('POST');
    });

    it('grades each iDevice from the score field its own record carries', () => {
        for (const oid of Object.keys(trace.expected.perItem)) {
            const want = trace.expected.perItem[oid];
            expect(
                perItem[oid],
                'item ' + oid + ': expected ' + want + '%, produced '
                    + (Object.prototype.hasOwnProperty.call(perItem, oid) ? perItem[oid] + '%' : 'NO SCORE AT ALL')
                    + '. Produced ' + fmt(perItem) + ', expected ' + fmt(trace.expected.perItem) + '.'
            ).toBe(want);
        }
    });

    it('keeps the per-iDevice weight the record carries (needed for the overall)', () => {
        for (const oid of Object.keys(trace.expected.weights)) {
            expect(result.itemscores[oid].weighted).toBe(trace.expected.weights[oid]);
        }
    });

    it('never grades a record that is not evaluable or has no score yet', () => {
        for (const oid of trace.expected.ungraded) {
            expect(
                Object.prototype.hasOwnProperty.call(perItem, oid),
                'CONTAMINATION: ' + oid + ' has no score of its own but was graded. Produced ' + fmt(perItem) + '.'
            ).toBe(false);
        }
    });

    it('produces exactly the expected per-item map (no extra, no missing)', () => {
        expect(perItem).toEqual(trace.expected.perItem);
    });
});
