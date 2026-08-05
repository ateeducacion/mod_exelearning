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
}
