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
    /** @var string Base ELPX used when a test needs to seed extra files into a package. */
    private const PACKAGE_WITH_RUNTIME_SOURCE = 'research/fixtures/elpx/actividad-evaluable.elpx';

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
    }

    /**
     * A package that brings its own SCORM runtime gets the plugin's anyway.
     *
     * This activity grades with the runtime the plugin ships and no other. A
     * package uploaded as a SCORM export carries one of unknown vintage that
     * would otherwise decide the marks the plugin then has to read back, so
     * extraction replaces it rather than deferring to it.
     */
    public function test_extraction_replaces_a_runtime_the_package_brought_with_it(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Build an ELPX that already contains both runtime files, with contents
        // no eXeLearning release would ever produce.
        $sentinel = '/* package copy, must never run */';
        $packagepath = make_request_directory() . '/carries-its-own-runtime.elpx';
        $source = $CFG->dirroot . '/mod/exelearning/' . self::PACKAGE_WITH_RUNTIME_SOURCE;
        copy($source, $packagepath);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($packagepath) === true, 'could not reopen the fixture to seed it');
        $zip->addFromString('libs/SCORM_API_wrapper.js', $sentinel);
        $zip->addFromString('libs/SCOFunctions.js', $sentinel);
        $zip->close();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_exelearning')
            ->create_instance(['course' => $course->id, 'packagefilepath' => $packagepath]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $revision = (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]);

        $fs = get_file_storage();
        foreach (['SCORM_API_wrapper.js', 'SCOFunctions.js'] as $name) {
            $stored = $fs->get_file($context->id, 'mod_exelearning', 'content', $revision, '/libs/', $name);
            $this->assertInstanceOf(\stored_file::class, $stored, "$name was not installed");
            $this->assertNotSame($sentinel, $stored->get_content(), "the package's own $name survived extraction");
            $this->assertSame(
                file_get_contents($CFG->dirroot . '/mod/exelearning/assets/scorm/' . $name),
                $stored->get_content(),
                "$name served to learners is not the copy the plugin ships"
            );
        }
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
