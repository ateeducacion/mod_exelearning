<?php
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

namespace mod_exelearning\local\scorm;

use advanced_testcase;

/**
 * Unit tests for the SCORM loader injector extracted from lib.php (DEC-71-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\scorm\scorm_injector
 */
final class scorm_injector_test extends advanced_testcase {
    /**
     * Writes an HTML file into a fake content filearea.
     *
     * @param int $contextid
     * @param int $revision
     * @param string $filepath
     * @param string $filename
     * @param string $content
     */
    private function put_html(
        int $contextid,
        int $revision,
        string $filepath,
        string $filename,
        string $content
    ): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'content',
            'itemid'    => $revision,
            'filepath'  => $filepath,
            'filename'  => $filename,
        ], $content);
    }

    /**
     * inject() rewrites the <head> of root and nested HTML pages with the right
     * relative wrapper path, and leaves non-HTML files untouched. Re-running it is
     * idempotent (the marker guards against a second injection).
     */
    public function test_inject_rewrites_html_with_relative_paths_and_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $contextid = (int) \context_module::instance($cm->cmid)->id;
        $revision = 1;

        // Root page, nested page and a non-HTML asset.
        $this->put_html($contextid, $revision, '/', 'index.html', '<html><head><title>x</title></head><body></body></html>');
        $this->put_html($contextid, $revision, '/html/', 'page.html', '<html><head></head><body></body></html>');
        $this->put_html($contextid, $revision, '/css/', 'style.css', 'body{}');

        scorm_injector::inject($contextid, $revision);

        $fs = get_file_storage();
        $marker = '<!-- mod_exelearning:scorm-loader -->';

        // Root page: relative path is libs/...
        $index = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')->get_content();
        $this->assertStringContainsString($marker, $index);
        $this->assertStringContainsString('<script src="libs/SCORM_API_wrapper.js"></script>', $index);
        $this->assertStringContainsString('pipwerks.SCORM.init()', $index);

        // Nested page: relative path climbs one level (../libs/...).
        $page = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/html/', 'page.html')->get_content();
        $this->assertStringContainsString('<script src="../libs/SCORM_API_wrapper.js"></script>', $page);

        // Non-HTML asset is untouched.
        $css = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/css/', 'style.css')->get_content();
        $this->assertSame('body{}', $css);

        // Idempotent: a second pass does not add a second marker.
        scorm_injector::inject($contextid, $revision);
        $reindex = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')->get_content();
        $this->assertSame(1, substr_count($reindex, $marker));
    }

    /**
     * A package that already references the runtime must end up with ONE copy of it,
     * not two.
     *
     * An eXeLearning SCORM 1.2 export carries its own `libs/SCOFunctions.js` and its own
     * script tags, and this plugin accepts such a package: `content.xml` at the root is
     * the only thing it validates. package_manager overwrites the FILES with the
     * plugin's own, so the bytes were never in doubt — but the page still had two script
     * tags pointing at them, so the whole runtime was parsed and executed twice.
     *
     * Measured in a live Moodle before this fix: a SCORM export uploaded as an activity
     * served a page with two `SCORM_API_wrapper.js` tags and two `SCOFunctions.js` tags.
     * The LMS-visible traffic happened to survive it — one LMSInitialize, one commit and
     * one finish at pagehide — but "it happens to be idempotent" is not a contract, and
     * nothing was testing it.
     */
    public function test_a_package_that_already_loads_the_runtime_does_not_get_a_second_copy(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $revision = 44;

        $this->put_html(
            $contextid,
            $revision,
            '/',
            'index.html',
            '<html><head><title>t</title>'
                . '<script src="libs/SCORM_API_wrapper.js"></script>'
                . '<script src="libs/SCOFunctions.js"></script>'
                . '</head><body></body></html>'
        );
        $this->put_html(
            $contextid,
            $revision,
            '/html/',
            'page.html',
            '<html><head><script src="../libs/SCORM_API_wrapper.js"></script>'
                . '<script src="../libs/SCOFunctions.js"></script></head><body></body></html>'
        );

        scorm_injector::inject($contextid, $revision);

        $fs = get_file_storage();
        $index = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')
            ->get_content();
        $page = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/html/', 'page.html')
            ->get_content();

        $this->assertSame(1, substr_count($index, 'SCORM_API_wrapper.js'), 'index.html loads the wrapper once');
        $this->assertSame(1, substr_count($index, 'SCOFunctions.js'), 'index.html loads SCOFunctions once');
        $this->assertSame(1, substr_count($page, 'SCORM_API_wrapper.js'), 'a nested page loads the wrapper once');
        $this->assertSame(1, substr_count($page, 'SCOFunctions.js'), 'a nested page loads SCOFunctions once');

        // And what survives is the plugin's own tag, at the plugin's own path depth.
        $this->assertStringContainsString('<script src="libs/SCORM_API_wrapper.js"></script>', $index);
        $this->assertStringContainsString('<script src="../libs/SCOFunctions.js"></script>', $page);
    }

    /**
     * The injected bootstrap opens the session AND releases the runtime's write gate.
     *
     * The plugin manufactures a SCORM session around content that is not a SCORM package.
     * `pipwerks.SCORM.init()` opens the connection; `loadPage()` runs the entry policy, and
     * the rewritten eXeLearning runtime holds every LMS write until it has. A web export
     * never calls loadPage() itself — that entry point belongs to a SCORM export — so
     * without this the score never leaves the page.
     *
     * Measured on a live Moodle before the call existed: the activity registry held the
     * learner's score, `cmi.core.score.raw` and `cmi.suspend_data` stayed empty, no POST
     * reached track.php, and the gradebook column stayed empty for an activity the learner
     * had completed.
     */
    public function test_the_bootstrap_releases_the_runtimes_write_gate(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $revision = 51;

        $this->put_html($contextid, $revision, '/', 'index.html', '<html><head></head><body></body></html>');

        scorm_injector::inject($contextid, $revision);

        $index = get_file_storage()
            ->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')
            ->get_content();

        // The runtime's supported entry for a host that owns the page.
        $this->assertStringContainsString('ns.session.open({ ownsLifecycle: false })', $index);
        $this->assertStringContainsString("typeof ns.session.open === 'function'", $index);

        // NOT the SCO lifecycle: every page of this export shares one session and one
        // lesson_status, so loadPage() on a page after a terminal status closes the
        // session — measured live, page two then ran with a dead connection and recorded
        // nothing.
        $this->assertStringNotContainsString('window.loadPage()', $index);

        // And not pipwerks alone, except as the fallback for a runtime that predates
        // session.open(): opening pipwerks leaves the runtime's own client idle and every
        // write is refused with 301, silently.
        $this->assertStringContainsString('window.pipwerks.SCORM.init()', $index);
        $this->assertMatchesRegularExpression('~ticks > 40 && window\.pipwerks~', $index);
    }

    /**
     * A SCORM 1.2 export opens its own session as a SCO: `<body onload="loadPage()">`,
     * and exe_export.js calls window.loadPage() as well whenever the body carries the
     * `exe-scorm` class. Both go through `session.open({ ownsLifecycle: true })`, which
     * installs the page lifecycle this serving model must not run (every page shares
     * one session — see test_the_bootstrap_releases_the_runtimes_write_gate). The runtime
     * gives the lifecycle to the FIRST successful open, so with that entry left in place
     * who owns it is a race between the bootstrap's first tick and the page's own load
     * events. The injector removes the entry instead, so the bootstrap is the only
     * opener; everything else on the body tag survives.
     */
    public function test_a_scorm_export_opens_the_session_only_through_the_bootstrap(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $revision = 62;

        $this->put_html(
            $contextid,
            $revision,
            '/',
            'index.html',
            '<html><head><title>t</title>'
                . '<script src="libs/SCORM_API_wrapper.js"></script>'
                . '<script src="libs/SCOFunctions.js"></script>'
                . '</head><body class="exe-export exe-scorm exe-scorm12 exe-font-roboto" onload="loadPage()" lang="en">'
                . '<main></main></body></html>'
        );
        $this->put_html(
            $contextid,
            $revision,
            '/html/',
            'page.html',
            '<html><head></head>'
                . "<body onload='loadPage()' class='exe-export exe-scorm exe-scorm12'><main></main></body></html>"
        );

        scorm_injector::inject($contextid, $revision);

        $fs = get_file_storage();
        $index = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')
            ->get_content();
        $page = $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, '/html/', 'page.html')
            ->get_content();

        foreach (['index.html' => $index, 'html/page.html' => $page] as $name => $html) {
            // The bootstrap is the only session opener left on the page.
            $this->assertSame(
                1,
                substr_count($html, 'ns.session.open({ ownsLifecycle: false })'),
                "$name opens the session through the bootstrap, once"
            );
            $this->assertStringNotContainsString('loadPage()', $html, "$name no longer opens the session as a SCO");
            $this->assertDoesNotMatchRegularExpression('~<body\b[^>]*\bonload\s*=~i', $html, "$name has no body onload");
            $this->assertStringNotContainsString('exe-scorm', $html, "$name carries no exe-scorm / exe-scorm12 class");
            $this->assertSame(1, substr_count($html, '<body'), "$name still has exactly one body tag");
        }

        // Every other attribute and class of the body tag survives, in place.
        $this->assertStringContainsString('<body class="exe-export exe-font-roboto" lang="en">', $index);
        $this->assertStringContainsString("<body class='exe-export'>", $page);
        $this->assertStringContainsString('<main></main>', $index);
    }

    /**
     * A web export — what every .elpx upload is — carries no SCO entry, and the
     * injector must leave its body exactly as it was: the neutralisation is scoped to
     * the SCORM export case, not a rewrite of every page.
     */
    public function test_a_web_export_keeps_its_body_untouched(): void {
        $this->resetAfterTest();
        $contextid = \context_system::instance()->id;
        $revision = 63;

        $before = '<html><head><title>w</title></head>'
            . '<body class="exe-export exe-web-site" lang="es" data-x="1"><main>hi</main></body></html>';
        $this->put_html($contextid, $revision, '/', 'index.html', $before);

        scorm_injector::inject($contextid, $revision);

        $after = get_file_storage()
            ->get_file($contextid, 'mod_exelearning', 'content', $revision, '/', 'index.html')
            ->get_content();

        // The bootstrap went in, and from <body onward the page is byte-for-byte the original.
        $this->assertStringContainsString('ns.session.open({ ownsLifecycle: false })', $after);
        $this->assertSame(
            substr($before, strpos($before, '<body')),
            substr($after, strpos($after, '<body')),
            'a web export body is not rewritten'
        );
    }

    /**
     * Cases for {@see test_neutralise_sco_entry_removes_only_the_sco_entry()}.
     *
     * @return array<string, array{0: string, 1: string}> [input, expected].
     */
    public static function sco_entry_provider(): array {
        return [
            'exporter output' => [
                '<body class="exe-export exe-scorm exe-scorm12" onload="loadPage()" lang="en">',
                '<body class="exe-export" lang="en">',
            ],
            'single quotes, attributes in another order, semicolon, case' => [
                "<BODY onload='loadPage();' Class='exe-scorm12 exe-export EXE-SCORM' lang='en'>",
                "<BODY Class='exe-export' lang='en'>",
            ],
            'only the SCO classes: the attribute goes with them' => [
                '<body class="exe-scorm exe-scorm12" onload="loadPage()">',
                '<body>',
            ],
            'an onload that is not the SCO entry is left alone' => [
                '<body class="exe-export" onload="somethingElse()">',
                '<body class="exe-export" onload="somethingElse()">',
            ],
            'a web export is returned byte-for-byte' => [
                '<body class="exe-export exe-web-site" lang="en">',
                '<body class="exe-export exe-web-site" lang="en">',
            ],
            'no body tag at all' => [
                '<div class="exe-scorm">loadPage()</div>',
                '<div class="exe-scorm">loadPage()</div>',
            ],
        ];
    }

    /**
     * The neutralisation touches the SCO entry and nothing else: `onload="loadPage()"`
     * exactly, and the `exe-scorm` / `exe-scorm12` class tokens.
     *
     * @dataProvider sco_entry_provider
     * @param string $input A page (or a body tag).
     * @param string $expected What must come out.
     */
    public function test_neutralise_sco_entry_removes_only_the_sco_entry(string $input, string $expected): void {
        $wrap = static fn(string $tag): string => '<html><head></head>' . $tag . '<p>loadPage() in text stays</p></body></html>';

        $this->assertSame($wrap($expected), scorm_injector::neutralise_sco_entry($wrap($input)));
    }
}
