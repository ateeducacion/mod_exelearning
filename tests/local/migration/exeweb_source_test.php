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
use mod_exelearning\local\migration\source\exeweb_source;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the mod_exeweb migration source handler (issue #13 #3, DEC-13-12).
 *
 * mod_exeweb is not installed in CI, so these tests drive the handler against a
 * simulated `package` filearea. The point is the itemid: mod_exeweb stores the
 * package at itemid = {exeweb}.revision, not 0, and the old import_service read 0.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\exeweb_source
 */
final class exeweb_source_test extends advanced_testcase {
    use helper_trait;

    /**
     * The fixture .elpx path used as a stand-in mod_exeweb package.
     *
     * @return string
     */
    private function fixture(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
    }

    /**
     * The package is found at itemid = revision (regression: old code read itemid 0).
     */
    public function test_package_is_found_at_revision_itemid(): void {
        [, $ctxid] = $this->create_empty_target();
        $this->store_sibling_package($ctxid, 'mod_exeweb', $this->fixture(), 'web.elpx', 3);
        $source = $this->make_source_row(['contextid' => $ctxid, 'revision' => 3]);

        $src = new exeweb_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame(3, $verdict->itemid);
        $path = $src->resolve_elpx($source);
        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    /**
     * When the stored itemid does not match the recorded revision (e.g. a restored
     * backup), the handler still resolves the package by scanning the filearea.
     */
    public function test_fallback_scans_area_when_revision_drifts(): void {
        [, $ctxid] = $this->create_empty_target();
        $this->store_sibling_package($ctxid, 'mod_exeweb', $this->fixture(), 'web.elpx', 5);
        $source = $this->make_source_row(['contextid' => $ctxid, 'revision' => 9]);

        $src = new exeweb_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame(5, $verdict->itemid);
        $this->assertFileExists($src->resolve_elpx($source));
    }

    /**
     * An empty source context yields a nosource classification and a null path.
     */
    public function test_empty_area_is_nosource(): void {
        [, $ctxid] = $this->create_empty_target();
        $source = $this->make_source_row(['contextid' => $ctxid, 'revision' => 1]);

        $src = new exeweb_source();
        $this->assertSame(migration_result::STATUS_NOSOURCE, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * A content.xml zip (not named .elpx) stored by mod_exeweb is migratable: the
     * handler classifies by content, not by filename.
     */
    public function test_content_xml_zip_is_ok(): void {
        [, $ctxid] = $this->create_empty_target();
        $this->store_sibling_package($ctxid, 'mod_exeweb', $this->make_content_xml_zip(), 'web.zip', 2);
        $source = $this->make_source_row(['contextid' => $ctxid, 'revision' => 2]);

        $src = new exeweb_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame(2, $verdict->itemid);
        $this->assertFileExists($src->resolve_elpx($source));
    }

    /**
     * A stored package with no recoverable content.xml source (here a legacy .elp
     * carrying contentv3.xml) is now reported nosource instead of being classified
     * migratable and failing at extraction. The old handler returned ok whenever any
     * file existed in the filearea, without inspecting it.
     */
    public function test_package_without_content_xml_is_nosource(): void {
        [, $ctxid] = $this->create_empty_target();
        $this->store_sibling_package($ctxid, 'mod_exeweb', $this->make_legacy_elp_zip(), 'legacy.elp', 1);
        $source = $this->make_source_row(['contextid' => $ctxid, 'revision' => 1]);

        $src = new exeweb_source();
        $this->assertSame(migration_result::STATUS_NOSOURCE, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * list_sources() enumerates site-wide exeweb activities with their revision.
     */
    public function test_list_sources_returns_site_activities(): void {
        $this->resetAfterTest();
        $fake = $this->make_fake_sibling_activity('exeweb', ['name' => 'Web One', 'revision' => 4]);

        $rows = (new exeweb_source())->list_sources();

        $this->assertCount(1, $rows);
        $this->assertSame($fake->cmid, (int) $rows[0]->cmid);
        $this->assertSame('Web One', $rows[0]->name);
        $this->assertSame(4, (int) $rows[0]->revision);
        $this->assertNull($rows[0]->migrationid);
    }

    /**
     * The handler exposes its identity and grade behaviour (exeweb has no grades).
     */
    public function test_identity_and_grade_behaviour(): void {
        $src = new exeweb_source();
        $this->assertSame('exeweb', $src->get_module_name());
        $this->assertSame('mod_exeweb', $src->get_component());
        $this->assertSame(EXELEARNING_GRADEMODEL_PERITEM, $src->get_target_grademodel());
        $this->assertFalse($src->needs_grade_migration());
        // The mod_exeweb plugin is not installed in CI, so the handler is unavailable.
        $this->assertFalse($src->is_available());
    }
}
