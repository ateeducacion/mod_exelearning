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

/**
 * Shared fixtures for migration tests (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\tests;

/**
 * Test helpers for the migration suite.
 *
 * The sibling plugins (mod_exeweb / mod_exescorm) are not installed in CI, so these
 * helpers simulate a sibling's `package` filearea (with a parametrizable itemid, which
 * is what exposes the mod_exeweb revision bug) and build SCORM zips and source rows by
 * hand. Together they let the source handlers and the orchestrator be tested without
 * either sibling installed.
 */
trait helper_trait {
    /**
     * Creates an empty (no-package) target exelearning instance.
     *
     * @return array{0:\stdClass,1:int} The instance row and its module context id.
     */
    protected function create_empty_target(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'packagefilepath' => false]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        return [$instance, (int) \context_module::instance($cm->id)->id];
    }

    /**
     * Stores a file in a context as if it were a sibling plugin's package.
     *
     * The $itemid parameter is what makes the mod_exeweb revision storage testable:
     * mod_exeweb stores at itemid = {exeweb}.revision, not 0.
     *
     * @param int $contextid Context to host the stored file.
     * @param string $component Source frankenstyle component (e.g. mod_exeweb).
     * @param string $srcpath Path to the file to store.
     * @param string $filename Stored file name.
     * @param int $itemid Filearea itemid (0 for exescorm, revision for exeweb).
     * @return \stored_file
     */
    protected function store_sibling_package(
        int $contextid,
        string $component,
        string $srcpath,
        string $filename,
        int $itemid = 0
    ): \stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_pathname(
            [
                'contextid' => $contextid,
                'component' => $component,
                'filearea'  => 'package',
                'itemid'    => $itemid,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $srcpath
        );
    }

    /**
     * Builds a SCORM zip embedding the given .elpx entries (plus a stub manifest).
     *
     * @param string[] $elpxentries Zip entry paths to fill with the fixture .elpx
     *                              (e.g. ['content/elp.elpx', 'backup/old.elpx']).
     * @return string Absolute path to the built zip.
     */
    protected function make_scorm_zip(array $elpxentries): string {
        global $CFG;
        $fixture = $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx';
        $stage = make_request_directory();
        file_put_contents($stage . '/imsmanifest.xml', '<manifest></manifest>');
        $files = ['imsmanifest.xml' => $stage . '/imsmanifest.xml'];
        foreach ($elpxentries as $entry) {
            $files[$entry] = $fixture;
        }
        $zip = make_request_directory() . '/scorm.zip';
        get_file_packer('application/zip')->archive_to_pathname($files, $zip);
        return $zip;
    }

    /**
     * Builds a zip carrying a root content.xml (a genuine ODE 2.0 source) plus an
     * index.html, with NO embedded .elpx.
     *
     * This is the shape of an eXeLearning content .zip / IMS export / web export
     * that bundles its source: not named .elpx and not wrapping an .elpx, but still
     * a migratable eXeLearning package because content.xml sits at the root.
     *
     * @return string Absolute path to the built zip.
     */
    protected function make_content_xml_zip(): string {
        $stage = make_request_directory();
        file_put_contents(
            $stage . '/content.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
                . '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0"></ode>'
        );
        file_put_contents($stage . '/index.html', '<!DOCTYPE html><title>web</title><body>web export</body>');
        $zip = make_request_directory() . '/content-xml.zip';
        get_file_packer('application/zip')->archive_to_pathname([
            'content.xml' => $stage . '/content.xml',
            'index.html'  => $stage . '/index.html',
        ], $zip);
        return $zip;
    }

    /**
     * Builds a legacy eXeLearning .elp zip: a contentv3.xml source with neither a
     * content.xml nor an embedded .elpx.
     *
     * Used to assert that legacy .elp projects are out of scope (only content.xml
     * packages migrate) and are reported nosource rather than migrated.
     *
     * @return string Absolute path to the built zip.
     */
    protected function make_legacy_elp_zip(): string {
        $stage = make_request_directory();
        file_put_contents(
            $stage . '/contentv3.xml',
            '<instance class="exe.engine.package.Package"></instance>'
        );
        $zip = make_request_directory() . '/legacy.elp';
        get_file_packer('application/zip')->archive_to_pathname([
            'contentv3.xml' => $stage . '/contentv3.xml',
        ], $zip);
        return $zip;
    }

    /**
     * Creates a fake sibling activity so list_sources() can be tested without the
     * sibling plugin installed: a minimal sibling table, a {modules} registration, a
     * course, the activity row, a real course module and its context.
     *
     * @param string $module 'exeweb' or 'exescorm'.
     * @param array $activityfields Values for the activity row (e.g. name, revision,
     *                              exescormtype, reference).
     * @return \stdClass {courseid, cmid, instanceid, moduleid}.
     */
    protected function make_fake_sibling_activity(string $module, array $activityfields = []): \stdClass {
        global $DB;
        $dbman = $DB->get_manager();

        // Minimal sibling table covering the columns the enumeration query reads.
        $table = new \xmldb_table($module);
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intro', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('exescormtype', XMLDB_TYPE_CHAR, '20', null, null, null, null);
            $table->add_field('reference', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // Register the module so cm.module resolves.
        $moduleid = $DB->get_field('modules', 'id', ['name' => $module]);
        if (!$moduleid) {
            $moduleid = $DB->insert_record('modules', (object) ['name' => $module, 'visible' => 1]);
        }

        $course = $this->getDataGenerator()->create_course();
        $activity = (object) array_merge([
            'course' => $course->id, 'name' => 'Fake activity', 'intro' => '',
            'introformat' => FORMAT_HTML, 'revision' => 1,
        ], $activityfields);
        $instanceid = $DB->insert_record($module, $activity);

        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], '*', MUST_EXIST);
        $cmid = $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => $instanceid,
            'section' => $section->id, 'added' => 1, 'visible' => 1, 'visibleold' => 1,
            'visibleoncoursepage' => 1, 'groupmode' => 0, 'groupingid' => 0, 'completion' => 0,
            'completionview' => 0, 'completionexpected' => 0, 'completionpassgrade' => 0,
            'deletioninprogress' => 0,
        ]);
        \context_module::instance($cmid);

        return (object) [
            'courseid'   => (int) $course->id,
            'cmid'       => (int) $cmid,
            'instanceid' => (int) $instanceid,
            'moduleid'   => (int) $moduleid,
        ];
    }

    /**
     * Builds a source row matching source_interface::list_sources() output.
     *
     * @param array $overrides Values to override on the default row.
     * @return \stdClass
     */
    protected function make_source_row(array $overrides = []): \stdClass {
        return (object) array_merge([
            'cmid'                        => 0,
            'course'                      => 0,
            'coursename'                  => 'Course',
            'sectionnum'                  => 0,
            'instanceid'                  => 0,
            'contextid'                   => 0,
            'name'                        => 'Source activity',
            'intro'                       => '',
            'introformat'                 => FORMAT_HTML,
            'migrationid'                 => null,
            'cmvisible'                   => 1,
            'cmvisibleoncoursepage'       => 1,
            'cmgroupmode'                 => 0,
            'cmgroupingid'                => 0,
            'cmavailability'              => null,
            'cmlang'                      => '',
            'cmcompletion'                => 0,
            'cmcompletionview'            => 0,
            'cmcompletionexpected'        => 0,
            'cmcompletiongradeitemnumber' => null,
            'cmcompletionpassgrade'       => 0,
            'revision'                    => 0,
            'exescormtype'                => 'local',
            'reference'                   => '',
        ], $overrides);
    }
}
