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
use mod_exelearning\local\migration\source\exescorm_source;
use mod_exelearning\tests\helper_trait;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Unit tests for the mod_exescorm migration source handler (issue #13 #3, DEC-13-12).
 *
 * mod_exescorm is not installed in CI; the handler is driven against a simulated
 * `package` filearea (itemid 0). Classification distinguishes a direct .elpx package
 * (embedded editor flow), a SCORM zip embedding exactly one / zero / many .elpx, an
 * externally hosted source, and a corrupt zip.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\source\exescorm_source
 */
final class exescorm_source_test extends advanced_testcase {
    use helper_trait;

    /**
     * The fixture .elpx path.
     *
     * @return string
     */
    private function fixture(): string {
        global $CFG;
        return $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
    }

    /**
     * A package that is itself an .elpx (embedded editor flow) is migratable.
     */
    public function test_direct_elpx_package_is_ok(): void {
        [, $ctxid] = $this->create_empty_target();
        $this->store_sibling_package($ctxid, 'mod_exescorm', $this->fixture(), 'project.elpx', 0);
        $source = $this->make_source_row([
            'contextid' => $ctxid, 'exescormtype' => 'embedded', 'reference' => 'project.elpx',
        ]);

        $src = new exescorm_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertNull($verdict->elpxentry);
        $this->assertFileExists($src->resolve_elpx($source));
    }

    /**
     * A SCORM zip embedding exactly one .elpx is migratable and only that entry is extracted.
     */
    public function test_zip_with_single_embedded_elpx_is_ok(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_scorm_zip(['content/elp.elpx']);
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'scorm.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertSame('content/elp.elpx', $verdict->elpxentry);
        $this->assertFileExists($src->resolve_elpx($source));
    }

    /**
     * A SCORM zip with no embedded .elpx is not migratable.
     */
    public function test_zip_without_elpx_is_nosource(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_scorm_zip([]);
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'scorm.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $this->assertSame(migration_result::STATUS_NOSOURCE, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * A non-.elpx package carrying content.xml at its root (an eXeLearning content
     * .zip / IMS export) is now migratable as a direct package. The old handler only
     * matched a .elpx filename or an embedded .elpx, so this previously reported
     * nosource; content-based detection recovers it.
     */
    public function test_content_xml_zip_without_elpx_is_ok(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_content_xml_zip();
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'content-export.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $verdict = $src->classify($source);

        $this->assertTrue($verdict->is_ok());
        $this->assertNull($verdict->elpxentry);
        $this->assertFileExists($src->resolve_elpx($source));
    }

    /**
     * A legacy .elp (contentv3.xml, no content.xml) is out of scope: nosource.
     */
    public function test_legacy_elp_is_nosource(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_legacy_elp_zip();
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'legacy.elp', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $this->assertSame(migration_result::STATUS_NOSOURCE, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * A SCORM zip embedding more than one .elpx is ambiguous (manual migration).
     */
    public function test_zip_with_multiple_elpx_is_ambiguous(): void {
        [, $ctxid] = $this->create_empty_target();
        $zip = $this->make_scorm_zip(['content/a.elpx', 'backup/b.elpx']);
        $this->store_sibling_package($ctxid, 'mod_exescorm', $zip, 'scorm.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $this->assertSame(migration_result::STATUS_AMBIGUOUSSOURCE, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * Externally hosted and synchronized types are unsupported before touching any file.
     */
    public function test_external_and_synced_types_are_unsupported(): void {
        $src = new exescorm_source();
        foreach (['external', 'aiccurl', 'localsync'] as $type) {
            // No stored package at all: classification must not depend on file access.
            $source = $this->make_source_row(['contextid' => 0, 'exescormtype' => $type]);
            $this->assertSame(
                migration_result::STATUS_UNSUPPORTED,
                $src->classify($source)->status,
                "Type {$type} must be unsupported"
            );
            $this->assertNull($src->resolve_elpx($source));
        }
    }

    /**
     * A synchronized source is unsupported even when a local package snapshot exists:
     * migrating it would break the sync relationship with its external URL (DEC-13-12).
     */
    public function test_localsync_is_unsupported_even_with_local_package(): void {
        [, $ctxid] = $this->create_empty_target();
        // Store a perfectly valid local package: classification must still refuse it.
        $this->store_sibling_package($ctxid, 'mod_exescorm', $this->fixture(), 'synced.elpx', 0);
        $source = $this->make_source_row([
            'contextid' => $ctxid, 'exescormtype' => 'localsync', 'reference' => 'https://example.org/pkg.zip',
        ]);

        $src = new exescorm_source();
        $this->assertSame(migration_result::STATUS_UNSUPPORTED, $src->classify($source)->status);
        $this->assertNull($src->resolve_elpx($source));
    }

    /**
     * A corrupt (unreadable) zip is reported as nosource, not an exception.
     */
    public function test_corrupt_zip_is_nosource(): void {
        [, $ctxid] = $this->create_empty_target();
        $broken = make_request_directory() . '/broken.zip';
        file_put_contents($broken, 'this is not a real zip');
        $this->store_sibling_package($ctxid, 'mod_exescorm', $broken, 'broken.zip', 0);
        $source = $this->make_source_row(['contextid' => $ctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $this->assertSame(migration_result::STATUS_NOSOURCE, $src->classify($source)->status);
        // The packer logs a developer-only debugging message for the unreadable zip.
        $this->assertDebuggingCalled();
    }

    /**
     * list_sources() enumerates site-wide exescorm activities with type and reference.
     */
    public function test_list_sources_returns_site_activities(): void {
        $this->resetAfterTest();
        $fake = $this->make_fake_sibling_activity('exescorm', [
            'name' => 'Scorm One', 'exescormtype' => 'local', 'reference' => 'pkg.zip',
        ]);

        $rows = (new exescorm_source())->list_sources();

        $this->assertCount(1, $rows);
        $this->assertSame($fake->cmid, (int) $rows[0]->cmid);
        $this->assertSame('Scorm One', $rows[0]->name);
        $this->assertSame('local', $rows[0]->exescormtype);
        $this->assertSame('pkg.zip', $rows[0]->reference);
    }

    /**
     * The handler exposes its identity and grade behaviour (exescorm migrates overall).
     */
    public function test_identity_and_grade_behaviour(): void {
        $src = new exescorm_source();
        $this->assertSame('exescorm', $src->get_module_name());
        $this->assertSame('mod_exescorm', $src->get_component());
        $this->assertSame(EXELEARNING_GRADEMODEL_OVERALL, $src->get_target_grademodel());
        $this->assertTrue($src->needs_grade_migration());
        $this->assertFalse($src->is_available());
    }
}
