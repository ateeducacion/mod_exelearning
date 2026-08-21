import { defineConfig } from 'vitest/config';

// Vitest config for the plugin's own JavaScript unit tests. Scope: the grade-critical
// SCORM tracker (js/scorm_tracker.js), the secure-mode SCORM bridge (js/scorm_bridge_*)
// and the external-embed shim/relay (js/exe_embed_*). UI glue (amd/src/*) and the
// vendored pipwerks wrappers (assets/scorm/*) are out of scope. The embedded editor
// (exelearning/) ships its own Vitest suite and is not retested here.
export default defineConfig({
    test: {
        globals: true,
        // happy-dom gives the tracker a window/document for resolveObjectMap and the
        // beforeunload wiring, matching the embedded editor's own setup.
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcovonly', 'cobertura'],
            reportsDirectory: './coverage',
            include: ['js/**'],
        },
    },
});
