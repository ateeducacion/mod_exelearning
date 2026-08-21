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
use mod_exelearning\local\zip_utils;

/**
 * Tests for the shared ZIP extraction safety helpers.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\zip_utils
 */
final class zip_utils_test extends advanced_testcase {
    /**
     * is_unsafe_zip_entry() rejects traversal, absolute, scheme and backslash
     * entries (the shared zip-slip guard reused by both extraction sites).
     */
    public function test_is_unsafe_zip_entry(): void {
        // Safe relative paths inside the archive.
        $this->assertFalse(zip_utils::is_unsafe_zip_entry('index.html'));
        $this->assertFalse(zip_utils::is_unsafe_zip_entry('app/main.js'));
        $this->assertFalse(zip_utils::is_unsafe_zip_entry('files/icons/a.svg'));

        // Unsafe: empty, parent traversal, absolute, backslash and URL schemes.
        $this->assertTrue(zip_utils::is_unsafe_zip_entry(''));
        $this->assertTrue(zip_utils::is_unsafe_zip_entry('../escape.txt'));
        $this->assertTrue(zip_utils::is_unsafe_zip_entry('a/../../b'));
        $this->assertTrue(zip_utils::is_unsafe_zip_entry('/etc/passwd'));
        $this->assertTrue(zip_utils::is_unsafe_zip_entry('a\\b.txt'));
        $this->assertTrue(zip_utils::is_unsafe_zip_entry('file:///etc/passwd'));
    }

    /**
     * assert_extraction_contained() accepts a normal nested tree of real files
     * and directories without raising.
     */
    public function test_assert_extraction_contained_accepts_normal_tree(): void {
        $dir = make_request_directory();
        check_dir_exists($dir . '/sub/deep', true, true);
        file_put_contents($dir . '/index.html', 'x');
        file_put_contents($dir . '/sub/style.css', 'x');
        file_put_contents($dir . '/sub/deep/icon.svg', 'x');

        // No exception expected; assert it ran and returned cleanly.
        zip_utils::assert_extraction_contained($dir, 'stylesupload_unsafe');
        $this->assertDirectoryExists($dir . '/sub/deep');
    }

    /**
     * assert_extraction_contained() rejects a tree containing a symlink, which
     * could otherwise redirect later writes outside the extraction root.
     */
    public function test_assert_extraction_contained_rejects_symlink(): void {
        if (!function_exists('symlink') || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('symlink() is not available on this platform.');
        }

        $dir = make_request_directory();
        file_put_contents($dir . '/index.html', 'x');
        // A link pointing outside the extraction root must be rejected. The target is
        // deliberately a path that does not exist: assert_extraction_contained() throws
        // on isLink() before it resolves the target, so a dangling link exercises the
        // same branch — and Moodle deletes this request directory on shutdown with
        // remove_dir(), which branches on is_dir() and therefore FOLLOWS symlinks. A
        // link to a real directory would have its target's contents unlinked.
        symlink($dir . '/../not-a-real-target', $dir . '/escape');

        $this->expectException(\moodle_exception::class);
        zip_utils::assert_extraction_contained($dir, 'stylesupload_unsafe');
    }
}
