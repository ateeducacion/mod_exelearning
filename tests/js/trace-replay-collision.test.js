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
 * Control scenario for the S6 replay: the SAME page-local slot collision, but with
 * the two colliding iDevices scoring identically.
 *
 * This is the mirror image of synthetic-stale-slot, and it exists so a green S6 run
 * cannot be mistaken for a correct tracker. S6 punishes a tracker that treats an
 * unfamiliar slot occupant as new; this one punishes a tracker that treats a familiar
 * slot value as unchanged. A tracker that only ever compares slot N against slot N's
 * previous value must fail one of the two — passing both requires reasoning about the
 * objectid the slot currently resolves to.
 *
 * Both learners answered, both answered correctly, both grades must land.
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

describe('trace replay: identical scores colliding on one slot (S6 mirror)', () => {
    const trace = loadTrace(PLUGIN_ROOT, 'synthetic-collided-slot-same-score.trace.json');
    let perItem;

    beforeAll(() => {
        const result = replayScormTrace(trace, { trackerPath: TRACKER });
        perItem = toPerItem(result.itemscores);
        // eslint-disable-next-line no-console
        console.log('[trace-replay/mirror] tracker  : ' + TRACKER);
        // eslint-disable-next-line no-console
        console.log('[trace-replay/mirror] expected : ' + fmt(trace.expected.perItem));
        // eslint-disable-next-line no-console
        console.log('[trace-replay/mirror] produced : ' + fmt(perItem));
    });

    it('grades both answered iDevices even though their slot values are identical', () => {
        for (const oid of Object.keys(trace.expected.perItem)) {
            const want = trace.expected.perItem[oid];
            expect(
                perItem[oid],
                Object.prototype.hasOwnProperty.call(perItem, oid)
                    ? 'item ' + oid + ': expected ' + want + '%, produced ' + perItem[oid] + '%.'
                    : 'LOST GRADE: ' + oid + ' was answered ' + want + '% but never reached the POST body — '
                        + 'its page-local slot already held that exact score/weight from the previous page, so '
                        + 'the change detector saw nothing new. Produced ' + fmt(perItem)
                        + ', expected ' + fmt(trace.expected.perItem) + '.'
            ).toBe(want);
        }
    });

    it('produces exactly the expected per-item map', () => {
        expect(perItem).toEqual(trace.expected.perItem);
    });
});
