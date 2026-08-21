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

// Cross-browser e2e for the interactive-video / video-quiz media bridge (DEC-110-01), run in
// Firefox via playwright-embed.config.cjs. A self-contained harness (no Moodle needed): the
// opaque-origin sandboxed content runs the real eXeLearning child runtime
// (exe_media_bridge.js) and calls window.exeMediaBridge.openMedia() — exactly what the
// interactive-video and quick-questions-video iDevices do in secure mode — and the trusted
// parent runs mod's own exe_media_host.js. The host completes the capability handshake
// (window identity + nonce + transferred MessagePort) and opens the provider player in a
// modal, controlled by RAW postMessage (enablejsapi=1), NOT the YouTube IFrame API.

const { test, expect } = require('@playwright/test');

test('interactive-video bridge: child openMedia() opens a host modal with a raw youtube-nocookie player, no SDK (Firefox)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent-media.html');

    // The host opened its accessible modal in response to the validated 'open' command.
    const modal = page.locator('dialog.exe-media-modal');
    await expect.poll(() => modal.count(), { timeout: 15000 }).toBe(1);

    // The modal holds the real provider player, built by the raw-postMessage adapter from
    // the canonical {provider, videoId} (the child never passed a URL).
    const player = modal.locator('iframe');
    await expect.poll(() => player.count(), { timeout: 15000 }).toBeGreaterThan(0);

    const src = await player.first().getAttribute('src');
    // Canonical, privacy-friendly URL rebuilt by the host from the bare id.
    expect(src).toMatch(/^https:\/\/www\.youtube-nocookie\.com\/embed\/aqz-KE-bpKQ\b/);
    // enablejsapi=1 => the host drives the player by RAW postMessage, not the IFrame API.
    expect(src).toContain('enablejsapi=1');

    // The trusted parent page never loaded the YouTube IFrame API or the Vimeo SDK.
    expect(await page.evaluate(() => typeof window.YT)).toBe('undefined');
    expect(await page.evaluate(() => typeof window.Vimeo)).toBe('undefined');
});
