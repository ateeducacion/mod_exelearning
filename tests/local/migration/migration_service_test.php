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
use mod_exelearning\local\migration\source\exescorm_source;
use mod_exelearning\local\migration\source\source_interface;
use mod_exelearning\tests\helper_trait;
use mod_exelearning\tests\stub_source;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the migration orchestrator (issue #13 #3, DEC-13-12).
 *
 * The sibling plugins are not installed in CI, so orchestration is driven through a
 * stub source_interface (an anonymous class) over hand-built rows, while the
 * storage-level engine and cleanup are exercised against real eXeLearning activities.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\migration\migration_service
 */
final class migration_service_test extends advanced_testcase {
    use helper_trait;

    /**
     * Builds a stub source handler with injectable behaviour.
     *
     * @param array $sources Rows returned by list_sources().
     * @param \Closure|null $classify Maps a source row to a classification (default OK).
     * @param \Closure|null $resolve Maps a source row to an .elpx path (default null).
     * @param bool $needsgrades Whether grade migration runs.
     * @param int $grademodel Target grade model.
     * @param string $component Source frankenstyle component.
     * @param string $module Source module name.
     * @return source_interface
     */
    private function make_stub(
        array $sources,
        ?\Closure $classify = null,
        ?\Closure $resolve = null,
        bool $needsgrades = false,
        int $grademodel = EXELEARNING_GRADEMODEL_PERITEM,
        string $component = 'mod_exeweb',
        string $module = 'exeweb'
    ): source_interface {
        return new stub_source($sources, $classify, $resolve, $needsgrades, $grademodel, $component, $module);
    }

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
     * install_package() stores the .elpx, extracts the content and syncs grade items.
     */
    public function test_install_package_extracts_and_syncs_grade_items(): void {
        global $DB;
        [$instance, $ctxid] = $this->create_empty_target();

        migration_service::install_package($this->fixture(), $instance, $ctxid);

        $fs = get_file_storage();
        // The imported .elpx is staged at package/{revision}/ (issue 73), not itemid 0.
        $this->assertTrue($fs->file_exists($ctxid, 'mod_exelearning', 'package', (int) $instance->revision, '/', 'imported.elpx'));
        $this->assertNotEmpty($fs->get_file(
            $ctxid,
            'mod_exelearning',
            'content',
            (int) $instance->revision,
            '/',
            'index.html'
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
    }

    /**
     * End-to-end: a content.xml package stored under a non-.elpx name is detected by
     * exescorm_source, resolved, and installs into a servable, graded activity.
     *
     * Covers the detection gap (a content.xml package that is neither named .elpx nor
     * embeds an .elpx) through resolution and installation: the old handler reported
     * it nosource and never reached install_package().
     */
    public function test_content_xml_zip_resolves_and_installs(): void {
        global $DB;
        [$instance, $ctxid] = $this->create_empty_target();

        // A separate source context holds the genuine package under a .zip name.
        [, $srcctxid] = $this->create_empty_target();
        $this->store_sibling_package($srcctxid, 'mod_exescorm', $this->fixture(), 'content-export.zip', 0);
        $source = $this->make_source_row(['contextid' => $srcctxid, 'exescormtype' => 'local']);

        $src = new exescorm_source();
        $verdict = $src->classify($source);
        $this->assertTrue($verdict->is_ok());
        $this->assertNull($verdict->elpxentry);

        $path = $src->resolve_elpx($source);
        $this->assertNotNull($path);

        migration_service::install_package($path, $instance, $ctxid);

        $fs = get_file_storage();
        $this->assertNotEmpty($fs->get_file(
            $ctxid,
            'mod_exelearning',
            'content',
            (int) $instance->revision,
            '/',
            'index.html'
        ));
        $this->assertSame(2, $DB->count_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0]
        ));
    }

    /**
     * install_package() rejects a corrupt package instead of recording an empty shell.
     */
    public function test_install_package_throws_on_corrupt_elpx(): void {
        [$instance, $ctxid] = $this->create_empty_target();
        $broken = make_request_directory() . '/broken.elpx';
        file_put_contents($broken, 'not a real package');

        $threw = false;
        try {
            migration_service::install_package($broken, $instance, $ctxid);
        } catch (\moodle_exception $e) {
            $threw = true;
            $this->assertStringContainsString('migrateextractfailed', $e->errorcode);
        }
        $this->assertTrue($threw, 'A corrupt package must throw');
        // The packer logs a developer-only debugging message for the unreadable zip.
        $this->assertDebuggingCalled();
    }

    /**
     * migrate_one() creates the activity, records audit fields and is idempotent.
     */
    public function test_migrate_one_is_idempotent_and_records_audit_fields(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $standincm = $this->standin_cm($course->id);

        $fixture = $this->fixture();
        $src = $this->make_stub(
            [],
            fn($s) => classification::ok(),
            fn($s) => $fixture
        );
        $source = $this->make_source_row([
            'cmid'       => $standincm->id,
            'course'     => (int) $course->id,
            'instanceid' => $standincm->instance,
            'name'       => 'Migrated from exeweb',
        ]);

        $sink = $this->redirectEvents();
        $first = migration_service::migrate_one($src, $source);

        $this->assertSame(migration_result::STATUS_MIGRATED, $first->status);
        $this->assertNotEmpty($first->targetcmid);
        $row = $DB->get_record('exelearning_migration', [
            'sourcecomponent' => 'mod_exeweb', 'sourcecmid' => $standincm->id,
        ], '*', MUST_EXIST);
        $this->assertSame((int) $USER->id, (int) $row->userid);
        $this->assertGreaterThan(0, (int) $row->timemodified);
        $migrated = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_exelearning\event\activity_migrated
        );
        $this->assertCount(1, $migrated);

        // Idempotent: a second run skips and creates no new activity.
        $before = $DB->count_records('exelearning');
        $second = migration_service::migrate_one($src, $source);
        $this->assertSame(migration_result::STATUS_ALREADYMIGRATED, $second->status);
        $this->assertSame($before, $DB->count_records('exelearning'));
    }

    /**
     * A failure after the target is created rolls it back and leaves no mapping row.
     */
    public function test_migrate_one_cleans_up_created_module_on_failure(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $standincm = $this->standin_cm($course->id);
        $baseline = $DB->count_records('exelearning');

        // The resolver returns a corrupt package, so install_package() throws.
        $broken = make_request_directory() . '/broken.elpx';
        file_put_contents($broken, 'not a real package');
        $src = $this->make_stub([], fn($s) => classification::ok(), fn($s) => $broken);
        $source = $this->make_source_row([
            'cmid'       => $standincm->id,
            'course'     => (int) $course->id,
            'instanceid' => $standincm->instance,
            'name'       => 'Will fail',
        ]);

        $sink = $this->redirectEvents();
        $result = migration_service::migrate_one($src, $source);
        $this->assertDebuggingCalled();

        $this->assertSame(migration_result::STATUS_ERROR, $result->status);
        // The created target was rolled back: counts are back to baseline.
        $this->assertSame($baseline, $DB->count_records('exelearning'));
        $this->assertSame(0, $DB->count_records('exelearning_migration', [
            'sourcecomponent' => 'mod_exeweb', 'sourcecmid' => $standincm->id,
        ]));
        $failed = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_exelearning\event\migration_failed
        );
        $this->assertCount(1, $failed);

        // The failed source is retryable (not reported as already migrated).
        $sink->clear();
        $retry = migration_service::migrate_one($src, $source);
        $this->assertDebuggingCalled();
        $this->assertSame(migration_result::STATUS_ERROR, $retry->status);
    }

    /**
     * Blocked statuses skip without creating any module, and fire activity_skipped.
     */
    public function test_blocked_statuses_skip_without_creating_module(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $baseline = $DB->count_records('exelearning');

        foreach (
            [
            migration_result::STATUS_NOSOURCE,
            migration_result::STATUS_AMBIGUOUSSOURCE,
            migration_result::STATUS_UNSUPPORTED,
            ] as $status
        ) {
            $src = $this->make_stub([], fn($s) => $this->blocked($status));
            $source = $this->make_source_row([
                'cmid' => 1000 + strlen($status), 'course' => (int) $course->id, 'name' => $status,
            ]);

            $sink = $this->redirectEvents();
            $result = migration_service::migrate_one($src, $source);

            $this->assertSame($status, $result->status);
            $this->assertSame($baseline, $DB->count_records('exelearning'));
            $events = $sink->get_events();
            $this->assertInstanceOf(\mod_exelearning\event\activity_skipped::class, $events[0]);
            $this->assertSame($status, $events[0]->other['reason']);
        }
    }

    /**
     * A source that classifies OK but resolves to no .elpx (corruption/race between
     * classify and resolve) is reported as nosource and creates no module.
     */
    public function test_migrate_one_resolve_null_is_nosource(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $baseline = $DB->count_records('exelearning');

        $src = $this->make_stub([], fn($s) => classification::ok(), fn($s) => null);
        $source = $this->make_source_row(['cmid' => 555, 'course' => (int) $course->id]);

        $result = migration_service::migrate_one($src, $source);
        $this->assertSame(migration_result::STATUS_NOSOURCE, $result->status);
        $this->assertSame($baseline, $DB->count_records('exelearning'));
    }

    /**
     * get_available_sources() returns only installed siblings (none in CI).
     */
    public function test_get_available_sources_excludes_uninstalled_siblings(): void {
        $this->resetAfterTest();
        // The mod_exeweb / mod_exescorm plugins are not installed in CI, so none are available.
        $this->assertSame([], migration_service::get_available_sources());
    }

    /**
     * preflight() buckets sources without extracting any package.
     */
    public function test_preflight_buckets_and_never_extracts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $rows = [
            $this->make_source_row(['cmid' => 1, 'migrationid' => 99]), // Already migrated.
            $this->make_source_row(['cmid' => 2]), // Migratable.
            $this->make_source_row(['cmid' => 3]), // Migratable.
            $this->make_source_row(['cmid' => 4, 'name' => 'blocked']), // Blocked.
        ];
        $src = $this->make_stub(
            $rows,
            fn($s) => $s->name === 'blocked'
                ? $this->blocked(migration_result::STATUS_UNSUPPORTED)
                : classification::ok(),
            // The resolver must never be called during preflight.
            function ($s) {
                throw new \coding_exception('preflight must not extract');
            }
        );

        $pre = migration_service::preflight($src);

        $this->assertSame(4, $pre->total);
        $this->assertSame(1, $pre->alreadymigrated);
        $this->assertSame(2, $pre->migratable);
        $this->assertSame(1, $pre->blocked[migration_result::STATUS_UNSUPPORTED]);
        $this->assertCount(1, $pre->details);
    }

    /**
     * migrate_all() reports per-status counts and fires migration_started once.
     */
    public function test_migrate_all_reports_counts_and_fires_started_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $rows = [
            $this->make_source_row(['cmid' => 11, 'course' => (int) $course->id, 'name' => 'skip']),
            $this->make_source_row(['cmid' => 12, 'course' => (int) $course->id, 'name' => 'skip2']),
        ];
        // Both classify as unsupported, so migrate_all does no extraction.
        $src = $this->make_stub($rows, fn($s) => $this->blocked(migration_result::STATUS_UNSUPPORTED));

        $sink = $this->redirectEvents();
        $results = migration_service::migrate_all($src, new \core\progress\none());

        $this->assertCount(2, $results);
        $this->assertSame(migration_result::STATUS_UNSUPPORTED, $results[0]->status);
        $started = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_exelearning\event\migration_started
        );
        $this->assertCount(1, $started);
        $this->assertSame(2, reset($started)->other['total']);
    }

    /**
     * Returns a blocked classification for the given status (test convenience).
     *
     * @param string $status A blocked migration_result::STATUS_* value.
     * @return classification
     */
    private function blocked(string $status): classification {
        return match ($status) {
            migration_result::STATUS_AMBIGUOUSSOURCE => classification::ambiguoussource(),
            migration_result::STATUS_UNSUPPORTED     => classification::unsupported(),
            default                                  => classification::nosource(),
        };
    }

    /**
     * Creates a real exelearning activity to stand in as a migration source.
     *
     * Provides a genuine course module id and context for idempotency keying; the
     * stub source's resolve_elpx() supplies the actual package independently.
     *
     * @param int $courseid Course to create the stand-in in.
     * @return \stdClass The course module record.
     */
    private function standin_cm(int $courseid): \stdClass {
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $courseid, 'packagefilepath' => false]);
        return get_coursemodule_from_instance('exelearning', $instance->id);
    }
}
