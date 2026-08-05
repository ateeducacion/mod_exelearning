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

namespace mod_exelearning\local;

use advanced_testcase;

/**
 * Unit tests for the package manager extracted from lib.php (DEC-71-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\package_manager
 */
final class package_manager_test extends advanced_testcase {
    /**
     * Stores a built ZIP in the 'package' filearea at a chosen itemid.
     *
     * @param int $contextid
     * @param string[] $entries Map of zip entry name => string content.
     * @param int $itemid
     * @param string $filename
     * @return \stored_file
     */
    private function store_zip(int $contextid, array $entries, int $itemid, string $filename): \stored_file {
        $stage = make_request_directory();
        $files = [];
        foreach ($entries as $name => $content) {
            $abs = $stage . '/' . $name;
            check_dir_exists(dirname($abs));
            file_put_contents($abs, $content);
            $files[$name] = $abs;
        }
        $zip = make_request_directory() . '/' . $filename;
        get_file_packer('application/zip')->archive_to_pathname($files, $zip);

        return get_file_storage()->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $zip);
    }

    /**
     * validate_content_xml() accepts a ZIP that carries content.xml at its root and
     * rejects one that does not (DEC-16-01).
     */
    public function test_validate_content_xml(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $contextid = (int) \context_module::instance($cm->cmid)->id;

        $valid = $this->store_zip($contextid, ['content.xml' => '<ode/>'], 0, 'valid.elpx');
        $this->assertTrue(package_manager::validate_content_xml($valid));

        $invalid = $this->store_zip($contextid, ['index.html' => '<html></html>'], 5, 'invalid.zip');
        $this->assertFalse(package_manager::validate_content_xml($invalid));
    }

    /**
     * get_stored_package() finds the stored archive regardless of its itemid and
     * returns the most recent one (highest itemid) when several exist — the form
     * stores at 0, the editor at the revision, the Playground at 1.
     */
    public function test_get_stored_package_scans_all_itemids(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $contextid = (int) \context_module::instance($cm->cmid)->id;

        // Nothing stored yet.
        $this->assertNull(package_manager::get_stored_package($contextid));

        // Stored at a non-zero itemid (editor/Playground style): still located.
        $this->store_zip($contextid, ['content.xml' => '<ode/>'], 7, 'editor.elpx');
        $found = package_manager::get_stored_package($contextid);
        $this->assertInstanceOf(\stored_file::class, $found);
        $this->assertSame('editor.elpx', $found->get_filename());

        // With several itemids the most recent (highest itemid) wins.
        $this->store_zip($contextid, ['content.xml' => '<ode/>'], 0, 'form.elpx');
        $latest = package_manager::get_stored_package($contextid);
        $this->assertSame(7, (int) $latest->get_itemid());
    }
}
