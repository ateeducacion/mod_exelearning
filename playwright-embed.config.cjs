// Playwright config for the cross-browser external-embed e2e (DEC-80-03), separate from
// any Moodle/Behat setup. Serves the plugin root over a static server so the harness
// can load the real js/exe_embed_*.js and an opaque-origin sandboxed content iframe,
// then runs the check in Firefox (proves the promote-to-parent mechanism is not
// Chromium-specific). Run with: npx playwright test -c playwright-embed.config.cjs
const { defineConfig, devices } = require('@playwright/test');

const PORT = 8126;

module.exports = defineConfig({
    testDir: 'tests/e2e',
    testMatch: /.*\.spec\.cjs$/,
    timeout: 30000,
    fullyParallel: false,
    use: { baseURL: 'http://localhost:' + PORT },
    webServer: {
        command: 'python3 -m http.server ' + PORT,
        port: PORT,
        reuseExistingServer: false,
        timeout: 30000,
    },
    projects: [
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    ],
});
