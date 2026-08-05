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

namespace mod_exelearning\local\migration;

use advanced_testcase;
use mod_exelearning\local\migration\source\classification;
use mod_exelearning\local\migration\source\package_probe;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the shared content-based package probe (issue #13 #3, DEC-13-12).
 *
 * The probe is the single source of truth for what is migratable: a package with a
 * root content.xml (a native .elpx, a content.xml zip or an IMS export) or one
 * embedding exactly one .elpx. Legacy .elp (contentv3.xml), source-less SCORM/web
 * exports, several embedded .elpx, and corrupt archives are not migratable.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\package_probe
 */
final class package_probe_test extends advanced_testcase {
    use helper_trait;

    /**
     * The fixture .elpx path (a genuine package with content.xml at its root).
     *
     * @return string
     */
    private function fixture(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
    }

    /**
     * Stores a file in a fresh module context and returns it as a stored_file.
     *
     * @param string $srcpath Path to the file to store.
     * @param string $filename Stored file name.
     * @return \stored_file
     */
    private function stored(string $srcpath, string $filename): \stored_file {
        [, $ctxid] = $this->create_empty_target();
        return $this->store_sibling_package($ctxid, 'mod_exescorm', $srcpath, $filename, 0);
    }

    /**
     * A native .elpx (content.xml at its root) is a direct, migratable package.
     */
    public function test_native_elpx_is_direct(): void {
        $pkg = $this->stored($this->fixture(), 'project.elpx');

        $verdict = package_probe::classify($pkg);

        $this->assertTrue($verdict->is_ok());
        $this->assertNull($verdict->elpxentry);
        $resolved = package_probe::resolve($pkg, $verdict);
        $this->assertNotNull($resolved);
        $this->assertFileExists($resolved);
    }

    /**
     * A non-.elpx zip carrying content.xml at its root (eXeLearning content zip /
     * IMS / web export with source) is direct and migratable.
     */
    public function test_root_content_xml_zip_is_direct(): void {
        $pkg = $this->stored($this->make_content_xml_zip(), 'web-export.zip');

        $verdict = package_probe::classify($pkg);

        $this->assertTrue($verdict->is_ok());
        $this->assertNull($verdict->elpxentry);
        $this->assertFileExists(package_probe::resolve($pkg, $verdict));
    }

    /**
     * A SCORM zip embedding exactly one .elpx is migratable via that single entry.
     */
    public function test_single_embedded_elpx_is_ok(): void {
        $pkg = $this->stored($this->make_scorm_zip(['content/elp.elpx']), 'scorm.zip');

        $verdict = package_probe::classify($pkg);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame('content/elp.elpx', $verdict->elpxentry);
        $this->assertFileExists(package_probe::resolve($pkg, $verdict));
    }

    /**
     * A SCORM zip embedding more than one .elpx is ambiguous (manual migration).
     */
    public function test_multiple_embedded_elpx_is_ambiguous(): void {
        $pkg = $this->stored($this->make_scorm_zip(['content/a.elpx', 'backup/b.elpx']), 'scorm.zip');

        $this->assertSame(
            migration_result::STATUS_AMBIGUOUSSOURCE,
            package_probe::classify($pkg)->status
        );
        $this->assertNull(package_probe::resolve($pkg, package_probe::classify($pkg)));
    }

    /**
     * A source-less SCORM zip (manifest only, no content.xml, no .elpx) is nosource.
     */
    public function test_sourceless_scorm_is_nosource(): void {
        $pkg = $this->stored($this->make_scorm_zip([]), 'scorm.zip');

        $this->assertSame(migration_result::STATUS_NOSOURCE, package_probe::classify($pkg)->status);
        $this->assertNull(package_probe::resolve($pkg, package_probe::classify($pkg)));
    }

    /**
     * A legacy .elp (contentv3.xml only) is out of scope and reported nosource.
     */
    public function test_legacy_elp_is_nosource(): void {
        $pkg = $this->stored($this->make_legacy_elp_zip(), 'legacy.elp');

        $this->assertSame(migration_result::STATUS_NOSOURCE, package_probe::classify($pkg)->status);
    }

    /**
     * A corrupt (unreadable) archive is reported nosource, not an exception.
     */
    public function test_corrupt_archive_is_nosource(): void {
        $broken = make_request_directory() . '/broken.zip';
        file_put_contents($broken, 'this is not a real zip');
        $pkg = $this->stored($broken, 'broken.zip');

        $this->assertSame(migration_result::STATUS_NOSOURCE, package_probe::classify($pkg)->status);
        // The packer logs a developer-only debugging message for the unreadable zip.
        $this->assertDebuggingCalled();
    }

    /**
     * The resolved itemid is threaded back into the classification (exeweb fallback).
     */
    public function test_itemid_is_threaded_into_classification(): void {
        $pkg = $this->stored($this->fixture(), 'project.elpx');

        $this->assertSame(7, package_probe::classify($pkg, 7)->itemid);
    }

    /**
     * resolve() returns null for a blocked verdict without touching the package.
     */
    public function test_resolve_returns_null_for_blocked_verdict(): void {
        $pkg = $this->stored($this->fixture(), 'project.elpx');

        $this->assertNull(package_probe::resolve($pkg, classification::nosource()));
        $this->assertNull(package_probe::resolve($pkg, classification::ambiguoussource()));
    }

    /**
     * resolve() refuses a hostile embedded entry name even if reached directly,
     * as defence in depth behind classify()'s own filtering.
     */
    public function test_resolve_rejects_unsafe_embedded_entry(): void {
        $pkg = $this->stored($this->make_scorm_zip(['content/elp.elpx']), 'scorm.zip');

        $this->assertNull(package_probe::resolve($pkg, classification::ok('../evil.elpx')));
    }
}
