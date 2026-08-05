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

// Regression guard (DEC-80-03 + DEC-80-01): in secure mode the package now loads BOTH
// the SCORM bridge (scoring) AND the external-embed shim/relay. They must coexist —
// the embed feature must not break SCORM score saving. Side-effect imports in the
// same load order as production (tracker → scorm shim → scorm relay → embed shim →
// embed relay). globals (describe/it/expect) come from vitest.config.mjs.
import '../../js/scorm_tracker.js';
import '../../js/scorm_bridge_shim.js';
import '../../js/scorm_bridge_relay.js';
import '../../js/exe_embed_shim.js';
import '../../js/exe_embed_relay.js';

const scormRelay = window.exeScormBridge;
const embedRelay = window.exeEmbedRelay;

describe('embed feature does not break the SCORM bridge', () => {
    it('keeps every bridge + embed global defined and distinct (no clobbering)', () => {
        expect(window.exeScormTracker).toBeTruthy();
        expect(window.exeScormBridgeShim).toBeTruthy();
        expect(window.exeScormBridge).toBeTruthy();
        expect(window.exeEmbedShim).toBeTruthy();
        expect(window.exeEmbedRelay).toBeTruthy();
        expect(window.exeScormBridge).not.toBe(window.exeEmbedRelay);
        expect(window.exeScormBridgeShim).not.toBe(window.exeEmbedShim);
    });

    it('still accepts a SCORM track message (scoring path) with the embed modules loaded', () => {
        const track = {
            type: 'scorm',
            action: 'track',
            cmi: { 'cmi.core.score.raw': '80' },
            exelearningBridge: 'N1',
        };
        expect(scormRelay.isTrackMessage(track)).toBe(true);
        expect(scormRelay.acceptTrack(track, 'N1')).toBe(true);
        // Wrong nonce is still rejected (the embed code did not weaken validation).
        expect(scormRelay.acceptTrack(track, 'OTHER')).toBe(false);
    });

    it('the two bridges ignore each other’s message types', () => {
        // The SCORM relay never treats an embed message as a score.
        expect(scormRelay.isTrackMessage({ type: 'exe-embed', action: 'sync', embeds: [] })).toBe(false);
        // The embed relay still validates a real embed URL (its own channel works).
        // Open mode (default): any cross-origin https iframe is accepted verbatim.
        const ok = embedRelay.validate('https://www.youtube.com/embed/abc123', 'http://x/content/1/index.html');
        expect(ok).toEqual({ url: 'https://www.youtube.com/embed/abc123', kind: 'video' });
    });

    it('the embed relay does not act on a SCORM track message (no overlay created)', () => {
        const r = embedRelay.createRelay({ whitelist: ['www.youtube.com'] });
        const before = document.querySelectorAll('.exe-embed-overlay').length;
        r.onMessage({ source: {}, data: { type: 'scorm', action: 'track', cmi: {}, exelearningBridge: 'N1' } });
        expect(document.querySelectorAll('.exe-embed-overlay').length).toBe(before);
    });
});
