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
use grade_item;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for mod_exelearning lib.php (instance lifecycle + grade items).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_add_instance
 * @covers     ::exelearning_update_instance
 * @covers     ::exelearning_delete_instance
 * @covers     ::exelearning_sync_grade_items
 * @covers     \mod_exelearning\grades\grade_sync
 * @covers     \mod_exelearning\grades\grade_item_manager
 * @covers     \mod_exelearning\grades\completion_validator
 * @covers     \mod_exelearning\local\package_manager
 * @covers     \mod_exelearning\local\scorm\idevice_patch
 * @covers     \mod_exelearning\local\urls
 */
final class lib_test extends advanced_testcase {
    /**
     * Helper: create a course + exelearning instance with the given overrides.
     *
     * @param array $record extra fields for the generator
     * @return \stdClass the exelearning instance row
     */
    protected function create_activity(array $record = []): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');

        return $generator->create_instance(array_merge(['course' => $course->id], $record));
    }

    /**
     * Adding an instance from the evaluable fixture detects 2 gradable iDevices.
     */
    public function test_add_instance_detects_gradeitems(): void {
        global $DB;

        $instance = $this->create_activity();

        // Two rows in exelearning_grade_item (trueorfalse + guess), none deleted.
        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);

        $types = array_values(array_map(fn($r) => $r->idevicetype, $rows));
        sort($types);
        $this->assertSame(['guess', 'trueorfalse'], $types);

        // The mapped itemnumbers are 1 and 2.
        $itemnumbers = array_values(array_map(fn($r) => (int) $r->itemnumber, $rows));
        sort($itemnumbers);
        $this->assertSame([1, 2], $itemnumbers);

        // Default model is PERITEM: there is no overall (itemnumber=0) column
        // (DEC-25-01); only the per-iDevice columns 1 and 2 are present.
        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertFalse($overall);

        foreach ([1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertInstanceOf(
                grade_item::class,
                $gi,
                "grade_item itemnumber={$itemnumber} should exist in the default PERITEM model"
            );
        }
    }

    /**
     * Create-from-scratch (issue #13 #1, DEC-13-03): an instance can be added with
     * no uploaded package. It is created cleanly with no stored package, no
     * content and no grade items, ready to be authored in the embedded editor.
     */
    public function test_create_instance_without_package(): void {
        global $DB;

        $instance = $this->create_activity(['packagefilepath' => false]);

        $this->assertNotEmpty($instance->id);
        $this->assertSame(1, (int) $instance->revision);

        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        // No package was stored and nothing was detected.
        $this->assertNull(exelearning_get_stored_package($context->id));
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        // The package URL degrades to null so the editor opens a blank project.
        $this->assertNull(exelearning_get_package_url($instance, $context));
    }

    /**
     * A multi-page package registers one grade item per gradable iDevice, keyed by
     * the iDevice's stable objectid, even when those iDevices live on different
     * pages and share the same page-local DOM index (the RIE-007 / DEC-5-01 case).
     */
    public function test_multipage_detects_distinct_objectids_per_page(): void {
        global $DB;

        $instance = $this->create_activity(
            ['packagefilepath' => 'research/fixtures/elpx/multipage-gradable.elpx']
        );

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber ASC'
        );
        $this->assertCount(2, $rows);

        // Both gradable iDevices sit at page-local DOM index 2 on their respective
        // pages, yet they map to distinct objectids, distinct pages and stable,
        // sequential itemnumbers (1 and 2).
        $byitem = [];
        foreach ($rows as $r) {
            $byitem[(int) $r->itemnumber] = $r;
        }
        $this->assertSame([1, 2], array_keys($byitem));
        $this->assertSame('idevice-tf-0001', $byitem[1]->objectid);
        $this->assertSame('trueorfalse', $byitem[1]->idevicetype);
        $this->assertSame('idevice-guess-0002', $byitem[2]->objectid);
        $this->assertSame('guess', $byitem[2]->idevicetype);
        $this->assertNotEquals(
            $byitem[1]->pageid,
            $byitem[2]->pageid,
            'the two gradable iDevices must be attributed to different pages'
        );
    }

    /**
     * grademodel OVERALL: only itemnumber=0 is an active gradebook column.
     */
    public function test_grademodel_overall(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);

        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertInstanceOf(grade_item::class, $overall);

        foreach ([1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertFalse(
                $gi,
                "grade_item itemnumber={$itemnumber} must not exist in OVERALL model"
            );
        }
    }

    /**
     * grademodel PERITEM: no overall column (DEC-25-01), per-iDevice columns present.
     */
    public function test_grademodel_peritem(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertFalse($overall);

        foreach ([1, 2] as $itemnumber) {
            $gi = grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $instance->id,
                'itemnumber'   => $itemnumber,
                'courseid'     => $instance->course,
            ]);
            $this->assertInstanceOf(
                grade_item::class,
                $gi,
                "grade_item itemnumber={$itemnumber} should exist in PERITEM model"
            );
        }
    }

    /**
     * Deleting an instance wipes all of its rows across the four tables.
     *
     * Includes the retained xAPI-era audit log (DEC-122-01): nothing writes to it
     * any more, but the rows an existing site already holds are learner-linked, and
     * the privacy API reaches them by joining through {exelearning}. Leaving them
     * behind when the instance goes would strand personal data out of reach.
     */
    public function test_delete_instance(): void {
        global $DB;

        $instance = $this->create_activity();

        // Seed an attempt to prove it gets cleaned too.
        $DB->insert_record('exelearning_attempt', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'attempt'       => 1,
            'itemnumber'    => 1,
            'rawscore'      => 50,
            'maxscore'      => 100,
            'scaledscore'   => 0.5,
            'status'        => 'completed',
            'sessiontoken'  => 'tok',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        // Seed a legacy tracking event to prove the retained table is cleaned too.
        $DB->insert_record('exelearning_tracking_events', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'statementid'   => 'b7f1c0de-0000-4000-8000-000000000001',
            'verb'          => 'answered',
            'objectid'      => 'idev-1',
            'registration'  => 'reg-1',
            'scaled'        => 0.5,
            'timecreated'   => time(),
        ]);

        $this->assertTrue(exelearning_delete_instance($instance->id));

        $this->assertFalse($DB->record_exists('exelearning', ['id' => $instance->id]));
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id]
        ));
        $this->assertSame(0, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $instance->id]
        ));
        $this->assertSame(0, $DB->count_records(
            'exelearning_tracking_events',
            ['exelearningid' => $instance->id]
        ));
    }

    /**
     * Self-heal: clearing grade items and re-syncing re-detects the two iDevices.
     */
    public function test_selfheal_extract_and_sync(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;

        // Wipe the mapping rows as if the activity lost its detection.
        $DB->delete_records('exelearning_grade_item', ['exelearningid' => $instance->id]);
        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id]
        ));

        // Re-run detection.
        exelearning_sync_grade_items($instance->id, $contextid);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);
    }

    /**
     * Saving the settings form after an embedded-editor save must NOT destroy the
     * stored .elpx (B1, DEC-34-01). The editor stores the package at itemid=revision
     * (deleting itemid 0); a subsequent settings submit carries a non-empty but
     * file-less filemanager draft, which previously wiped every package itemid and
     * left the activity unrecoverable. The guard keeps the stored package and
     * re-extracts the content for the current revision.
     *
     * @covers ::exelearning_save_and_extract_package
     */
    public function test_settings_save_with_empty_draft_keeps_stored_package(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        // Simulate editor/save.php: copy the package to itemid=revision+1 and drop
        // the original (itemid 0), then bump the instance revision.
        $original = exelearning_get_stored_package($context->id);
        $this->assertNotNull($original);
        $editoritemid = (int) $instance->revision + 1;
        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'mod_exelearning',
            'filearea'  => 'package',
            'itemid'    => $editoritemid,
            'filepath'  => '/',
            'filename'  => $original->get_filename(),
        ], $original);
        $original->delete();
        $DB->set_field('exelearning', 'revision', $editoritemid, ['id' => $instance->id]);

        // Teacher opens "Edit settings" and saves without touching the package: the
        // submitted draft is allocated but empty.
        $emptydraft = file_get_unused_draft_itemid();
        $data = (object) [
            'coursemodule' => $cm->id,
            'package'      => $emptydraft,
            'revision'     => $editoritemid,
        ];
        exelearning_save_and_extract_package($data);

        // The editor-saved package survives the settings save.
        $surviving = exelearning_get_stored_package($context->id);
        $this->assertNotNull(
            $surviving,
            'Stored package was destroyed by an empty-draft settings save'
        );
        // The content for the current revision is (re-)extracted and servable.
        $mainfile = $fs->get_file(
            $context->id,
            'mod_exelearning',
            'content',
            $editoritemid,
            '/',
            'index.html'
        );
        $this->assertNotFalse(
            $mainfile,
            'Content was not extracted for the current revision'
        );
    }

    /**
     * Replacing the package with a corrupt upload via the settings form must NOT advance
     * the stored revision nor destroy the previously extracted content (issue 73). The
     * update extracts and validates the new revision BEFORE committing the pointer, so a
     * corrupt replacement throws and the activity keeps serving its last good content.
     *
     * @covers ::exelearning_update_instance
     */
    public function test_update_instance_corrupt_replacement_keeps_revision_and_content(): void {
        global $DB, $USER;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        // Baseline: revision 1, extracted and servable.
        $this->assertSame(1, (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]));
        $this->assertNotFalse($fs->get_file($context->id, 'mod_exelearning', 'content', 1, '/', 'index.html'));

        // A filemanager draft carrying a corrupt (non-archive) "package".
        $usercontext = \context_user::instance($USER->id);
        $draftid = file_get_unused_draft_itemid();
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'broken.elpx',
        ], 'this is not a real zip archive');

        $data = (object) [
            'instance'     => $instance->id,
            'coursemodule' => $cm->id,
            'package'      => $draftid,
        ];
        try {
            exelearning_update_instance($data);
            $this->fail('A corrupt replacement must throw');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        $this->assertDebuggingCalled();

        // Stored pointer unchanged; previous content still servable.
        $this->assertSame(1, (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]));
        $this->assertNotFalse($fs->get_file($context->id, 'mod_exelearning', 'content', 1, '/', 'index.html'));
    }

    /**
     * A valid replacement via the settings form advances the stored revision, serves the
     * new content and prunes the superseded content + package revisions (issue 73).
     *
     * @covers ::exelearning_update_instance
     */
    public function test_update_instance_valid_replacement_advances_and_prunes(): void {
        global $CFG, $DB, $USER;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        // A filemanager draft holding a valid replacement (the real fixture).
        $usercontext = \context_user::instance($USER->id);
        $draftid = file_get_unused_draft_itemid();
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => 'replacement.elpx',
        ], $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx');

        $data = (object) [
            'instance'     => $instance->id,
            'coursemodule' => $cm->id,
            'package'      => $draftid,
        ];
        exelearning_update_instance($data);

        // Pointer advanced; new content servable; previous revision pruned.
        $this->assertSame(2, (int) $DB->get_field('exelearning', 'revision', ['id' => $instance->id]));
        $this->assertNotFalse($fs->get_file($context->id, 'mod_exelearning', 'content', 2, '/', 'index.html'));
        $this->assertFalse($fs->get_file($context->id, 'mod_exelearning', 'content', 1, '/', 'index.html'));

        // Only the new package revision remains.
        $itemids = [];
        foreach ($fs->get_area_files($context->id, 'mod_exelearning', 'package', false, 'itemid', false) as $file) {
            $itemids[(int) $file->get_itemid()] = true;
        }
        $this->assertCount(1, $itemids);
    }

    /**
     * A grade item name is built from the activity name (up to char 255) plus the
     * author-controlled page title from content.xml plus the iDevice type, so it
     * can exceed the char(255) column. It must be clamped, not thrown as a
     * dml_write_exception that aborts add/update and white-screens the view.php
     * self-heal for students (B5, DEC-34-01).
     *
     * @covers ::exelearning_grade_item_name
     */
    public function test_long_grade_item_name_is_clamped(): void {
        global $DB;

        // A maximal (char 255) activity name guarantees the combined grade item
        // name overflows once the page title and iDevice type are appended.
        $instance = $this->create_activity(['name' => str_repeat('A', 255)]);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(
                255,
                \core_text::strlen($row->name),
                'grade item name must be clamped to the char(255) column width'
            );
        }
    }

    /**
     * The completion-by-grade validation stopgap (B7, DEC-34-01) clears core's
     * badcompletiongradeitemnumber error only for a real gradebook column and only
     * when "require passing grade" is off, never masking the legitimate
     * pass-grade-required check. Tested as a pure helper so the coverage does not
     * depend on constructing the whole moodleform_mod (which couples to core
     * availability/tags/completion form fields).
     *
     * @covers ::exelearning_relax_completion_grade_errors
     */
    public function test_relax_completion_grade_errors(): void {
        // PERITEM activity registers per-iDevice items 1 and 2, no overall.
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $coreerror = ['completionpassgrade' => 'badcompletiongradeitemnumber'];

        // Registered per-iDevice item in PERITEM, require-pass off → error cleared.
        $out = exelearning_relax_completion_grade_errors(
            $coreerror,
            ['completiongradeitemnumber' => '1', 'completionpassgrade' => 0,
                'grademodel' => EXELEARNING_GRADEMODEL_PERITEM],
            $instance->id
        );
        $this->assertArrayNotHasKey('completionpassgrade', $out);

        // Unregistered itemnumber → error kept (not masked).
        $out = exelearning_relax_completion_grade_errors(
            $coreerror,
            ['completiongradeitemnumber' => '99', 'completionpassgrade' => 0,
                'grademodel' => EXELEARNING_GRADEMODEL_PERITEM],
            $instance->id
        );
        $this->assertArrayHasKey('completionpassgrade', $out);

        // Require-passing-grade on → never masked (deferred proper fix).
        $out = exelearning_relax_completion_grade_errors(
            $coreerror,
            ['completiongradeitemnumber' => '1', 'completionpassgrade' => 1,
                'grademodel' => EXELEARNING_GRADEMODEL_PERITEM],
            $instance->id
        );
        $this->assertArrayHasKey('completionpassgrade', $out);

        // A per-iDevice item is not a live column in OVERALL mode → error kept.
        $out = exelearning_relax_completion_grade_errors(
            $coreerror,
            ['completiongradeitemnumber' => '1', 'completionpassgrade' => 0,
                'grademodel' => EXELEARNING_GRADEMODEL_OVERALL],
            $instance->id
        );
        $this->assertArrayHasKey('completionpassgrade', $out);
    }

    /**
     * The serve-time guard patch (issue #13 / DEC-13-11) removes the
     * `body.exe-scorm` condition from the form/scrambled-list SAVE guard so they
     * save on `isScorm > 0` like every other gradable iDevice, leaves the
     * init-time guard (the `ldata.isScorm` variant) and unrelated files untouched,
     * and is idempotent.
     *
     * @covers ::exelearning_patch_idevice_save_guards
     */
    public function test_patch_idevice_save_guards(): void {
        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;
        $revision = (int) $instance->revision;
        $fs = get_file_storage();

        $write = function (string $path, string $name, string $content)
 use ($fs, $contextid, $revision): void {
            $fs->create_file_from_string([
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => $revision,
                'filepath'  => $path,
                'filename'  => $name,
            ], $content);
        };
        $read = fn(string $path, string $name): string =>
            $fs->get_file($contextid, 'mod_exelearning', 'content', $revision, $path, $name)
            ->get_content();

        $formsave = "if (\$('body').hasClass('exe-scorm') && data.isScorm > 0) {";
        $forminit = "if (\$('body').hasClass('exe-scorm') && ldata.isScorm > 0) {";
        $scrsave  = "if (document.body.classList.contains('exe-scorm') && data.isScorm > 0) {";
        $write('/idevices/form/', 'form.js', "a;\n{$formsave}\n  send();\n}\n{$forminit}\n  label();\n}\n");
        $write('/idevices/scrambled-list/', 'scrambled-list.js', "b;\n{$scrsave}\n  send();\n  return;\n}\n");

        exelearning_patch_idevice_save_guards($contextid, $revision);

        $form = $read('/idevices/form/', 'form.js');
        $scr  = $read('/idevices/scrambled-list/', 'scrambled-list.js');
        // SAVE guard: the exe-scorm condition is gone, leaving the bare isScorm check.
        $this->assertStringContainsString('if (data.isScorm > 0) {', $form);
        $this->assertStringNotContainsString("hasClass('exe-scorm') && data.isScorm", $form);
        $this->assertStringNotContainsString("contains('exe-scorm') && data.isScorm", $scr);
        // INIT guard (ldata.isScorm) is left untouched.
        $this->assertStringContainsString($forminit, $form);

        // Idempotent: a second run is a no-op (the guard is already gone).
        exelearning_patch_idevice_save_guards($contextid, $revision);
        $this->assertStringContainsString('if (data.isScorm > 0) {', $read('/idevices/form/', 'form.js'));
    }

    /**
     * Future-proofing canary (issue #13 / DEC-13-11): after extracting a package
     * with many iDevice types, NO served iDevice JS may still gate its score-save
     * on `body.exe-scorm`. The patch strips the two known offenders (form,
     * scrambled-list); if a future eXeLearning release ships another iDevice with
     * the same coupling — or the patch stops matching — this test fails, telling
     * the maintainer to add that guard to exelearning_patch_idevice_save_guards().
     *
     * Coverage is limited to the iDevice types present in the fixture (superelpx,
     * ~30 of the 51 iDevices, including form + scrambled-list); the plugin only
     * ever sees the iDevices an uploaded package actually contains.
     *
     * @covers ::exelearning_patch_idevice_save_guards
     */
    public function test_no_idevice_keeps_an_exe_scorm_save_guard(): void {
        $instance = $this->create_activity(
            ['packagefilepath' => 'research/fixtures/elpx/superelpx.elpx']
        );
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;
        $revision = (int) $instance->revision;
        $fs = get_file_storage();

        // The save-guard signature: `body.exe-scorm` AND the per-attempt
        // `data.isScorm` check in one condition. The init-time guards use
        // `ldata.isScorm` (not `data`), so they do not match and are left alone.
        $signature = '~(hasClass\(\'exe-scorm\'\)|contains\(\'exe-scorm\'\))\s*&&\s*data\.isScorm\s*>\s*0~';

        $offenders = [];
        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'content',
            $revision,
            'filepath, filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $full = $file->get_filepath() . $file->get_filename();
            if (!preg_match('~/idevices/.+\.js$~', $full)) {
                continue;
            }
            if (preg_match($signature, $file->get_content())) {
                $offenders[] = $full;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'An iDevice still gates its score-save on body.exe-scorm after extraction. '
                . 'Add its save guard to exelearning_patch_idevice_save_guards() '
                . '(issue #13 / DEC-13-11): ' . implode(', ', $offenders)
        );
    }

    /**
     * The first sync stores a contenthash for each gradable iDevice and reports
     * them all as "added" (a fresh activity has no prior state).
     */
    public function test_sync_persists_contenthash(): void {
        global $DB;

        $instance = $this->create_activity();

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        );
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $row->contenthash);
        }
    }

    /**
     * Re-syncing the same package reports no changes; a stored hash that no
     * longer matches the package (simulating an in-place options edit) is
     * reported as "changed" and the stored hash is refreshed (DEC-12-01).
     */
    public function test_sync_delta_detects_edited_options(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;

        // Re-syncing the unchanged package is a no-op delta.
        $delta = exelearning_sync_grade_items($instance->id, $contextid);
        $this->assertSame(
            ['added' => 0, 'removed' => 0, 'changed' => 0, 'capped' => 0],
            $delta
        );

        // Simulate an in-place edit: one stored hash diverges from the package.
        $target = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber ASC',
            '*',
            0,
            1
        );
        $target = reset($target);
        $original = $target->contenthash;
        $DB->set_field('exelearning_grade_item', 'contenthash', 'stalehash', ['id' => $target->id]);

        $delta = exelearning_sync_grade_items($instance->id, $contextid);
        $this->assertSame(1, $delta['changed']);
        $this->assertSame(0, $delta['added']);
        $this->assertSame(0, $delta['removed']);

        // The stored hash is refreshed back to the real content hash.
        $this->assertSame(
            $original,
            $DB->get_field('exelearning_grade_item', 'contenthash', ['id' => $target->id])
        );
    }

    /**
     * activity_has_attempts() reflects the presence of attempt rows.
     */
    public function test_activity_has_attempts(): void {
        global $DB;

        $instance = $this->create_activity();

        $this->assertFalse(
            \mod_exelearning\local\attempts::activity_has_attempts($instance->id)
        );

        $DB->insert_record('exelearning_attempt', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'attempt'       => 1,
            'itemnumber'    => 1,
            'rawscore'      => 50,
            'maxscore'      => 100,
            'scaledscore'   => 0.5,
            'status'        => 'completed',
            'sessiontoken'  => 'tok',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $this->assertTrue(
            \mod_exelearning\local\attempts::activity_has_attempts($instance->id)
        );
    }

    /**
     * The stale-grades warning is queued only when the gradable set changed AND
     * attempts exist; otherwise nothing is shown.
     */
    public function test_warn_if_grades_stale(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        $nochange = ['added' => 0, 'removed' => 0, 'changed' => 0];
        $changed  = ['added' => 0, 'removed' => 0, 'changed' => 1];

        // No attempts yet: even a real change is silent.
        \core\notification::fetch();
        exelearning_warn_if_grades_stale($instance->id, $changed, $cm->id);
        $this->assertCount(0, \core\notification::fetch());

        // With an attempt present, a change warns; no change stays silent.
        $DB->insert_record('exelearning_attempt', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'attempt'       => 1,
            'itemnumber'    => 1,
            'rawscore'      => 50,
            'maxscore'      => 100,
            'scaledscore'   => 0.5,
            'status'        => 'completed',
            'sessiontoken'  => 'tok',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        \core\notification::fetch();
        exelearning_warn_if_grades_stale($instance->id, $nochange, $cm->id);
        $this->assertCount(0, \core\notification::fetch());

        exelearning_warn_if_grades_stale($instance->id, $changed, $cm->id);
        $this->assertCount(1, \core\notification::fetch());
    }

    /**
     * The grade-item cap warning is queued only when sync dropped iDevices over the
     * gradeitems::MAX_ITEMNUMBER cap; a zero "capped" count stays silent.
     */
    public function test_warn_if_grade_items_capped(): void {
        $this->create_activity();

        // No overflow: silent.
        \core\notification::fetch();
        exelearning_warn_if_grade_items_capped(['capped' => 0]);
        $this->assertCount(0, \core\notification::fetch());

        // Overflow: exactly one warning.
        \core\notification::fetch();
        exelearning_warn_if_grade_items_capped(['capped' => 3]);
        $this->assertCount(1, \core\notification::fetch());
    }

    /**
     * sync() reports the number of gradable iDevices it could not register because
     * the package exceeds gradeitems::MAX_ITEMNUMBER, and registers none beyond the cap.
     */
    public function test_sync_caps_grade_items(): void {
        global $DB;

        $instance = $this->create_activity();
        $max = \mod_exelearning\grades\gradeitems::MAX_ITEMNUMBER;

        // Drop the registered fixture iDevices so they re-detect as new on the next
        // sync, and seed a filler row at the cap so MAX(itemnumber) == MAX_ITEMNUMBER.
        $DB->delete_records('exelearning_grade_item', ['exelearningid' => $instance->id]);
        $DB->insert_record('exelearning_grade_item', (object) [
            'exelearningid' => $instance->id,
            'itemnumber'    => $max,
            'objectid'      => 'filler-cap',
            'pageid'        => null,
            'idevicetype'   => 'filler',
            'name'          => 'filler',
            'grademax'      => 100,
            'grademin'      => 0,
            'deleted'       => 0,
            'contenthash'   => null,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $delta = exelearning_sync_grade_items($instance->id);

        // The cap emits a developer-level debugging() once; acknowledge it.
        $this->assertDebuggingCalled();
        // The default fixture has exactly two gradable iDevices, both pushed over the
        // cap by the filler, so the dropped count is deterministic.
        $this->assertSame(2, $delta['capped']);
        // No grade item is registered beyond the cap.
        $this->assertSame(0, $DB->count_records_select(
            'exelearning_grade_item',
            'exelearningid = ? AND itemnumber > ?',
            [$instance->id, $max]
        ));
    }

    /**
     * Gradebook deep-link (issue #13 #4, DEC-13-02): exelearning_grade_item_view_url()
     * maps an itemnumber to its iDevice objectid so grade.php can forward the click
     * straight to that iDevice; itemnumber 0 and unknown numbers fall back to the
     * activity front page.
     */
    public function test_grade_item_view_url_deeplinks_by_itemnumber(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        // The overall grade (itemnumber 0) links to the front page, no deep link.
        $overall = exelearning_grade_item_view_url($instance, (int) $cm->id, 0);
        $this->assertArrayNotHasKey('idevice', $overall->params());
        $this->assertSame((string) $cm->id, (string) $overall->params()['id']);

        // A per-iDevice grade item carries that iDevice's stable objectid.
        $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => 1,
            'deleted'       => 0,
        ]);
        $this->assertNotEmpty($objectid);
        $deeplink = exelearning_grade_item_view_url($instance, (int) $cm->id, 1);
        $this->assertSame($objectid, $deeplink->params()['idevice']);

        // An unknown itemnumber degrades gracefully to the front page.
        $unknown = exelearning_grade_item_view_url($instance, (int) $cm->id, 99);
        $this->assertArrayNotHasKey('idevice', $unknown->params());
    }

    /**
     * Builds a stored ZIP file from a map of [entry name => content].
     *
     * @param array $entries Map of in-archive path => file content.
     * @param string $filename Stored file name (extension drives the upload type).
     * @return \stored_file
     */
    protected function make_zip_storedfile(array $entries, string $filename = 'pkg.zip'): \stored_file {
        $stage = make_request_directory();
        $paths = [];
        foreach ($entries as $name => $content) {
            $full = $stage . '/' . $name;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
            $paths[$name] = $full;
        }
        $packer = get_file_packer('application/zip');
        $zip = make_request_directory() . '/' . $filename;
        $packer->archive_to_pathname($paths, $zip);

        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_exelearning', 'packagetest');
        return $fs->create_file_from_pathname(
            [
                'contextid' => $context->id,
                'component' => 'mod_exelearning',
                'filearea'  => 'packagetest',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $filename,
            ],
            $zip
        );
    }

    /**
     * exelearning_package_has_content_xml() recognises a real package and rejects a
     * plain .zip that does not contain content.xml (issue #13, DEC-16-01).
     */
    public function test_package_has_content_xml(): void {
        $this->resetAfterTest();

        $valid = $this->make_zip_storedfile(['content.xml' => '<ode/>', 'index.html' => '<html></html>']);
        $this->assertTrue(exelearning_package_has_content_xml($valid));

        $invalid = $this->make_zip_storedfile(['index.html' => '<html></html>', 'photo.txt' => 'x']);
        $this->assertFalse(exelearning_package_has_content_xml($invalid));
    }

    /**
     * A .zip package that contains content.xml is extracted and its gradable
     * iDevices detected exactly like an .elpx (issue #13, DEC-16-01).
     */
    public function test_zip_package_detected_like_elpx(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $contentxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0">' . "\n"
            . '<odeNavStructure>' . "\n"
            . '<odePageId>p1</odePageId><pageName>Page</pageName>' . "\n"
            . '<odePageId>p1</odePageId>' . "\n"
            . '<odeIdeviceId>idevice-tf-zip</odeIdeviceId>' . "\n"
            . '<odeIdeviceTypeName>trueorfalse</odeIdeviceTypeName>' . "\n"
            . '<jsonProperties>{"isScorm":1}</jsonProperties>' . "\n"
            . '</odeNavStructure>' . "\n</ode>\n";
        $stage = make_request_directory();
        file_put_contents($stage . '/content.xml', $contentxml);
        file_put_contents($stage . '/index.html', '<html><body>x</body></html>');
        $packer = get_file_packer('application/zip');
        $zip = make_request_directory() . '/pkg.zip';
        $packer->archive_to_pathname(
            ['content.xml' => $stage . '/content.xml', 'index.html' => $stage . '/index.html'],
            $zip
        );

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'packagefilepath' => $zip]);

        $rows = $DB->get_records('exelearning_grade_item', ['exelearningid' => $instance->id, 'deleted' => 0]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('trueorfalse', $row->idevicetype);
        $this->assertSame('idevice-tf-zip', $row->objectid);
    }

    /**
     * Master grading switch off (DEC-13-07): no grade items are registered even when
     * the package has gradable iDevices, and no overall grade item exists.
     */
    public function test_gradeenabled_off_creates_no_grade_items(): void {
        global $DB;

        $instance = $this->create_activity(['gradeenabled' => 0]);

        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
        $overall = grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => 0,
            'courseid'     => $instance->course,
        ]);
        $this->assertFalse($overall);
    }

    /**
     * Toggling grading off on an activity that already has grade items soft-deletes
     * them (deleted=1, columns removed) while preserving attempt history (DEC-13-07).
     */
    public function test_gradeenabled_toggle_off_softdeletes_and_preserves_attempts(): void {
        global $DB;

        $instance = $this->create_activity();
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $contextid = \context_module::instance($cm->id)->id;
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));

        // Seed an attempt to prove it survives the switch-off.
        $DB->insert_record('exelearning_attempt', (object) [
            'exelearningid' => $instance->id,
            'userid'        => 2,
            'attempt'       => 1,
            'itemnumber'    => 1,
            'rawscore'      => 50,
            'maxscore'      => 100,
            'scaledscore'   => 0.5,
            'status'        => 'completed',
            'sessiontoken'  => 'tok',
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        // Disable grading and re-sync.
        $DB->set_field('exelearning', 'gradeenabled', 0, ['id' => $instance->id]);
        exelearning_sync_grade_items($instance->id, $contextid);

        $this->assertSame(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
        $this->assertGreaterThan(0, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 1]
        ));
        $this->assertSame(1, $DB->count_records(
            'exelearning_attempt',
            ['exelearningid' => $instance->id]
        ));
    }

    /**
     * The gradebook "grade analysis" destination is role-based (issue #13 #4,
     * DEC-13-06): a teacher/grader lands on the attempts report; a student is
     * deep-linked to the specific iDevice in the content.
     */
    public function test_grade_analysis_url_role_based(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $context = \context_module::instance($cm->id);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Teacher (has viewreport) -> attempts report. Without a userid the report
        // carries no user filter.
        $this->setUser($teacher);
        $teacherurl = exelearning_grade_analysis_url($instance, (int) $cm->id, 1, $context);
        $this->assertStringContainsString('/mod/exelearning/report.php', $teacherurl->out(false));
        $this->assertArrayNotHasKey('userid', $teacherurl->params());

        // With a userid (forwarded by the gradebook "grade analysis" link), the
        // teacher is deep-linked to that student's attempts (DEC-13-06).
        $teacheruseridurl = exelearning_grade_analysis_url($instance, (int) $cm->id, 1, $context, (int) $student->id);
        $this->assertStringContainsString('/mod/exelearning/report.php', $teacheruseridurl->out(false));
        $this->assertEquals($student->id, $teacheruseridurl->params()['userid']);

        // Student -> the iDevice in the content (userid is ignored for students).
        $this->setUser($student);
        $studenturl = exelearning_grade_analysis_url($instance, (int) $cm->id, 1, $context, (int) $student->id);
        $this->assertStringContainsString('/mod/exelearning/view.php', $studenturl->out(false));
        $this->assertArrayNotHasKey('userid', $studenturl->params());
        $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
            'exelearningid' => $instance->id,
            'itemnumber'    => 1,
            'deleted'       => 0,
        ]);
        $this->assertSame($objectid, $studenturl->params()['idevice']);
    }
}
