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

namespace mod_exelearning;

use advanced_testcase;

/**
 * Tests for the package extraction and SCORM-loader injection that run when an
 * exelearning instance is created from a stored ELPX (lib.php).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_extract_stored_package
 * @covers     ::exelearning_inject_scorm_loader
 * @covers     ::exelearning_get_stored_package
 * @covers     ::exelearning_package_has_content_xml
 * @covers     \mod_exelearning\local\package_manager
 * @covers     \mod_exelearning\local\scorm\scorm_injector
 * @covers     \mod_exelearning\local\scorm\idevice_patch
 */
final class lib_extract_test extends advanced_testcase {
    /**
     * Creating an instance from the default ELPX fixture expands the package into
     * the content filearea, ships the SCORM wrapper shim and rewrites the HTML so
     * the wrapper loads at page-load time (exelearning_extract_stored_package() and
     * exelearning_inject_scorm_loader()).
     */
    public function test_create_instance_extracts_package_and_injects_scorm_loader(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $revision = (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]);

        $fs = get_file_storage();

        // The ELPX was extracted into the content filearea at the current revision.
        $index = $fs->get_file($context->id, 'mod_exelearning', 'content', $revision, '/', 'index.html');
        $this->assertInstanceOf(\stored_file::class, $index);

        // The SCORM wrapper shim was shipped under libs/ (eXeLearning's web export
        // omits it; the plugin injects it from assets/scorm/).
        $wrapper = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            $revision,
            '/libs/',
            'SCORM_API_wrapper.js'
        );
        $this->assertInstanceOf(\stored_file::class, $wrapper);

        // The inject_scorm_loader() pass rewrote index.html to load the wrapper.
        $html = $index->get_content();
        $this->assertStringContainsString('<!-- mod_exelearning:scorm-loader -->', $html);
        $this->assertStringContainsString('libs/SCORM_API_wrapper.js', $html);

        // The secure-mode bridge client was shipped under libs/ and injected at the top
        // of <head> (DEC-0060).
        foreach (['scorm_tracker.js', 'exe_scorm_bridge.js'] as $bridgefile) {
            $f = $fs->get_file($context->id, 'mod_exelearning', 'content', $revision, '/libs/', $bridgefile);
            $this->assertInstanceOf(\stored_file::class, $f);
        }
        $this->assertStringContainsString('<!-- mod_exelearning:scorm-bridge -->', $html);
        $this->assertStringContainsString('libs/exe_scorm_bridge.js', $html);
    }

    /**
     * Re-extracting the same revision refreshes the plugin-owned bridge client
     * (scorm_tracker.js / exe_scorm_bridge.js) under libs/ — exercises the $present +
     * refresh delete-and-recreate branch of package_manager::extract_stored() (DEC-0060).
     * Idempotent: it must not error and the files must remain.
     */
    public function test_reextract_refreshes_bridge_client(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $revision = (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]);
        $fs = get_file_storage();

        // Present after the first extract.
        $before = $fs->get_file($context->id, 'mod_exelearning', 'content', $revision, '/libs/', 'exe_scorm_bridge.js');
        $this->assertInstanceOf(\stored_file::class, $before);

        // Re-extract the same revision: the bridge files are already present, so the
        // refresh branch deletes and recreates them. Must stay present and not error.
        exelearning_extract_stored_package($context->id, $revision);

        foreach (['scorm_tracker.js', 'exe_scorm_bridge.js'] as $bridgefile) {
            $f = $fs->get_file($context->id, 'mod_exelearning', 'content', $revision, '/libs/', $bridgefile);
            $this->assertInstanceOf(\stored_file::class, $f);
        }
    }

    /**
     * The in-package client runtime is the CANONICAL external-media bundle vendored from
     * eXeLearning core, not the superseded shim it replaced.
     *
     * Asserted on the extracted BYTES rather than on the source path, because the path is
     * what a refactor changes and the bytes are what a learner runs. The destination
     * filename deliberately stays `exe_embed_shim.js`: packages extracted before the
     * migration carry that name in their own HTML, and renaming it would strand them.
     * That is exactly why the name cannot be the thing this test trusts.
     */
    public function test_extract_ships_the_canonical_external_media_child(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $revision = (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]);

        $file = get_file_storage()->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            $revision,
            '/libs/',
            'exe_embed_shim.js'
        );
        $this->assertInstanceOf(\stored_file::class, $file, 'the client runtime was not shipped');

        $source = $file->get_content();
        // A symbol only the canonical bundle defines.
        $this->assertStringContainsString('exeExternalMediaChild', $source);
        // And it must carry the dual-licence grant into the package (eXe ADR-0018): these
        // bytes are redistributed to every learner who downloads the course.
        $this->assertStringContainsString('AGPL-3.0-or-later OR GPL-3.0-or-later', $source);
    }

    /**
     * The vendored copy is byte-identical to what eXeLearning core published.
     *
     * This plugin holds the BYTES and verifies them, rather than a copy of the logic that
     * could drift (eXe ADR-0021). CI runs the same check with a build hash pinned in the
     * workflow -- out of band, because a hash read from the copy under test cannot vouch
     * for that copy. This test is the fast local half.
     */
    public function test_vendored_external_media_matches_its_manifest(): void {
        $dir = __DIR__ . '/../js/exe_external_media/';
        $manifest = json_decode((string) file_get_contents($dir . 'exe-external-media.manifest.json'), true);

        $this->assertIsArray($manifest['files'] ?? null, 'the manifest has no file list');

        foreach ($manifest['files'] as $half => $record) {
            $this->assertFileExists($dir . $record['path'], "{$half} is missing");
            $this->assertSame(
                $record['sha256'],
                hash('sha256', (string) file_get_contents($dir . $record['path'])),
                "{$half} does not match the digest core published"
            );
        }

        // Editing a file and its digest together is the obvious way around a per-file
        // check, so the build hash covers the digest list itself.
        $keys = array_keys($manifest['files']);
        sort($keys);
        $lines = array_map(static fn($k) => $k . ':' . $manifest['files'][$k]['sha256'], $keys);
        $this->assertSame($manifest['buildHash'], hash('sha256', implode("\n", $lines)));
    }

    /**
     * Control is raw postMessage: no provider SDK may be inside the host bundle.
     */
    public function test_host_bundle_carries_no_provider_sdk(): void {
        $host = (string) file_get_contents(__DIR__ . '/../js/exe_external_media/exe-external-media-host.min.js');

        $this->assertStringNotContainsString('YT.Player', $host);
        $this->assertStringNotContainsString('Vimeo.Player', $host);
        $this->assertStringContainsString('enablejsapi', $host);
    }

    /**
     * exelearning_get_stored_package() returns the stored ELPX regardless of the
     * itemid it was saved under, and exelearning_package_has_content_xml() detects
     * the eXeLearning content manifest inside it.
     */
    public function test_get_stored_package_and_content_xml_detection(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        $package = exelearning_get_stored_package($context->id);
        $this->assertInstanceOf(\stored_file::class, $package);
        $this->assertTrue(exelearning_package_has_content_xml($package));
    }
}
