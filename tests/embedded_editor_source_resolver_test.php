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
use mod_exelearning\local\embedded_editor_source_resolver as resolver;

/**
 * Tests for the bundled-only embedded editor source resolver (DEC-0065).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\embedded_editor_source_resolver
 */
final class embedded_editor_source_resolver_test extends advanced_testcase {
    /**
     * Build a minimal valid static editor layout (index.html + one asset dir).
     *
     * @param string $dir Absolute path to populate.
     * @return void
     */
    private function make_valid_editor(string $dir): void {
        make_writable_directory($dir);
        make_writable_directory($dir . '/app');
        file_put_contents($dir . '/index.html', '<!doctype html><title>editor</title>');
    }

    /**
     * Point the resolver at a bundled directory for this test.
     *
     * The override lives in $CFG, so resetAfterTest() restores the real
     * dist/static path automatically.
     *
     * @param string $dir Absolute path to use as the bundled editor directory.
     * @return void
     */
    private function override_bundled_dir(string $dir): void {
        global $CFG;
        $CFG->mod_exelearning_bundled_editor_dir = $dir;
    }

    /**
     * validate_editor_dir() requires both index.html and an expected asset dir.
     */
    public function test_validate_editor_dir_requires_index_and_asset_dir(): void {
        $base = make_temp_directory('mod_exelearning/resolver-' . uniqid());

        // A non-existent directory is invalid.
        $this->assertFalse(resolver::validate_editor_dir($base . '/nope'));

        // An index.html with no asset dir alongside it is invalid.
        $noassets = $base . '/noassets';
        make_writable_directory($noassets);
        file_put_contents($noassets . '/index.html', 'x');
        $this->assertFalse(resolver::validate_editor_dir($noassets));

        // An asset dir present but no index.html is invalid.
        $noindex = $base . '/noindex';
        make_writable_directory($noindex . '/libs');
        $this->assertFalse(resolver::validate_editor_dir($noindex));

        // An index.html plus one of app/libs/files is valid.
        $valid = $base . '/valid';
        $this->make_valid_editor($valid);
        $this->assertTrue(resolver::validate_editor_dir($valid));

        remove_dir($base);
    }

    /**
     * A valid bundled editor is detected and exposed through every accessor.
     */
    public function test_valid_bundled_editor_is_detected(): void {
        $this->resetAfterTest();

        $dir = make_temp_directory('mod_exelearning/resolver-' . uniqid()) . '/static';
        $this->make_valid_editor($dir);
        $this->override_bundled_dir($dir);

        $this->assertTrue(resolver::is_available());
        $this->assertSame($dir, resolver::get_editor_dir());
        $this->assertSame($dir . '/index.html', resolver::get_index_source());
    }

    /**
     * An absent bundled editor disables embedded editing cleanly (null, not errors).
     */
    public function test_absent_bundled_editor_yields_no_source(): void {
        $this->resetAfterTest();

        $this->override_bundled_dir(
            make_temp_directory('mod_exelearning/resolver-' . uniqid()) . '/missing'
        );

        $this->assertFalse(resolver::is_available());
        $this->assertNull(resolver::get_editor_dir());
        $this->assertNull(resolver::get_index_source());
    }

    /**
     * An invalid bundled editor (index.html without assets) is rejected.
     */
    public function test_invalid_bundled_editor_is_rejected(): void {
        $this->resetAfterTest();

        $dir = make_temp_directory('mod_exelearning/resolver-' . uniqid()) . '/static';
        make_writable_directory($dir);
        file_put_contents($dir . '/index.html', 'x');
        $this->override_bundled_dir($dir);

        $this->assertFalse(resolver::is_available());
        $this->assertNull(resolver::get_editor_dir());
    }

    /**
     * A leftover admin-installed editor in moodledata is never considered (DEC-0065).
     *
     * Sites upgrading from the runtime-installer era may still carry
     * moodledata/mod_exelearning/embedded_editor; it must be ignored even when the
     * bundled source is absent.
     */
    public function test_stale_moodledata_editor_is_ignored(): void {
        global $CFG;
        $this->resetAfterTest();

        // Plant a perfectly valid editor where the removed installer used to put it.
        $staledir = $CFG->dataroot . '/mod_exelearning/embedded_editor';
        $this->make_valid_editor($staledir);

        // No bundled source available.
        $this->override_bundled_dir(
            make_temp_directory('mod_exelearning/resolver-' . uniqid()) . '/missing'
        );

        $this->assertFalse(resolver::is_available());
        $this->assertNull(resolver::get_editor_dir());
        $this->assertNull(resolver::get_index_source());

        remove_dir($staledir);
    }
}
