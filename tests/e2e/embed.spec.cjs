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

// Cross-browser e2e for the secure-mode external-embed mechanism (DEC-0061), run in
// Firefox via playwright-embed.config.cjs. It loads the REAL shim + relay against a
// self-contained harness (no Moodle needed): an opaque-origin sandboxed iframe whose
// content has cross-origin video iframes, a RELATIVE local PDF and an arbitrary
// cross-origin iframe. In open mode (the default) the shim promotes every cross-origin /
// PDF iframe to an inline parent overlay; the video players are sandboxed.

const { test, expect } = require('@playwright/test');

test('promotes every cross-origin/PDF iframe to a sandboxed inline player (open mode, Firefox)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent.html');

    const players = page.locator('.exe-embed-overlay iframe');
    await expect.poll(() => players.count(), { timeout: 15000 }).toBe(5);

    const srcs = await players.evaluateAll((els) => els.map((e) => e.src));

    // Open mode: cross-origin https iframes are promoted VERBATIM (no host list, no rebuild).
    expect(srcs.some((s) => /^https:\/\/www\.youtube-nocookie\.com\/embed\/aqz-KE-bpKQ\b/.test(s))).toBe(true);
    expect(srcs.some((s) => /^https:\/\/www\.dailymotion\.com\/embed\/video\/x8abc12$/.test(s))).toBe(true);
    // Vimeo cross-origin embed is promoted too (DEC-0067 e2e coverage).
    expect(srcs.some((s) => /^https:\/\/player\.vimeo\.com\/video\/76979871\b/.test(s))).toBe(true);
    // An arbitrary cross-origin provider is promoted too (the structural invariant).
    expect(srcs.some((s) => /^https:\/\/example\.com\//.test(s))).toBe(true);
    // The relative local PDF is reported absolute and rendered (the relative-URL fix).
    expect(srcs.some((s) => /\/local\.pdf$/.test(s))).toBe(true);

    // The promoted video players are sandboxed (allow-same-origin to render, but no
    // top-navigation so an arbitrary embed cannot redirect the tab).
    const sandboxes = await players.evaluateAll((els) => els.map((e) => e.getAttribute('sandbox') || ''));
    expect(sandboxes.some((s) => s.includes('allow-same-origin') && !s.includes('allow-top-navigation'))).toBe(true);
});

test('leaves the author iframes untouched when no relay answers (no-host inertness)', async ({ page }) => {
    await page.goto('/tests/e2e/embed/parent-nohost.html');
    const content = page.frameLocator('#content');
    // The shim re-announces for ~5.5s before giving up; assert the settled state.
    await page.waitForTimeout(6500);
    // Every author iframe survives, and no orphan placeholder was left behind.
    await expect(content.locator('iframe#yt')).toHaveCount(1);
    await expect(content.locator('iframe#pdf')).toHaveCount(1);
    await expect(content.locator('[data-exe-embed-id]')).toHaveCount(0);
    // And nothing was promoted onto this page either.
    await expect(page.locator('.exe-embed-overlay iframe')).toHaveCount(0);
});
