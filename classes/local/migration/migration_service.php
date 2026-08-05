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
 * Site-wide sibling-to-eXeLearning migration orchestrator (issue #13 #3, DEC-13-05, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration;

use mod_exelearning\event\activity_migrated;
use mod_exelearning\event\activity_skipped;
use mod_exelearning\event\migration_failed;
use mod_exelearning\event\migration_started;
use mod_exelearning\local\migration\source\exescorm_source;
use mod_exelearning\local\migration\source\exeweb_source;
use mod_exelearning\local\migration\source\source_interface;
use mod_exelearning\local\migration\target\activity_builder;
use mod_exelearning\local\migration\grade\overall_grade_migrator;

/**
 * Coordinates the non-destructive migration of mod_exeweb / mod_exescorm activities
 * into new eXeLearning activities.
 *
 * The source plugins are treated as read-only via source_interface; this class owns
 * the destination side: building the target activity, installing the package,
 * migrating grades, recording idempotency, firing events and rolling back partial
 * failures so a failed activity leaves no orphan and can be retried.
 */
final class migration_service {
    /**
     * Returns the available sibling sources on this site, keyed by module name.
     *
     * @return source_interface[]
     */
    public static function get_available_sources(): array {
        $sources = [];
        foreach ([new exeweb_source(), new exescorm_source()] as $src) {
            if ($src->is_available()) {
                $sources[$src->get_module_name()] = $src;
            }
        }
        return $sources;
    }

    /**
     * Classify-only preflight: no module is created and no package is extracted.
     *
     * @param source_interface $src The sibling source to inspect.
     * @return \stdClass {total, alreadymigrated, migratable, blocked: array<status,int>,
     *                    details: migration_result[]}
     */
    public static function preflight(source_interface $src): \stdClass {
        $result = (object) [
            'total'          => 0,
            'alreadymigrated' => 0,
            'migratable'     => 0,
            'blocked'        => [],
            'details'        => [],
        ];
        foreach ($src->list_sources() as $source) {
            $result->total++;
            if (!empty($source->migrationid)) {
                $result->alreadymigrated++;
                continue;
            }
            $verdict = $src->classify($source);
            if ($verdict->is_ok()) {
                $result->migratable++;
                continue;
            }
            $result->blocked[$verdict->status] = ($result->blocked[$verdict->status] ?? 0) + 1;
            $result->details[] = migration_result::from_source($source, $verdict->status);
        }
        return $result;
    }

    /**
     * Migrates every source activity of a sibling, reporting progress.
     *
     * @param source_interface $src The sibling source to migrate.
     * @param \core\progress\base|null $progress Optional progress reporter.
     * @return migration_result[]
     */
    public static function migrate_all(source_interface $src, ?\core\progress\base $progress = null): array {
        $sources = $src->list_sources();
        migration_started::create([
            'context' => \context_system::instance(),
            'other'   => ['sourcecomponent' => $src->get_component(), 'total' => count($sources)],
        ])->trigger();

        $progress?->start_progress(
            get_string('migrateheadingrun', 'mod_exelearning', $src->get_component()),
            max(count($sources), 1)
        );
        $results = [];
        foreach ($sources as $i => $source) {
            $results[] = self::migrate_one($src, $source);
            $progress?->progress($i + 1);
        }
        $progress?->end_progress();
        return $results;
    }

    /**
     * Migrates a single source activity, rolling back any partial failure.
     *
     * @param source_interface $src The sibling source handler.
     * @param \stdClass $source A row from $src->list_sources().
     * @return migration_result
     */
    public static function migrate_one(source_interface $src, \stdClass $source): migration_result {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        // 1. Idempotency. Kept as an explicit DB check even though list_sources()
        // LEFT JOINs the map (a concurrent run may have inserted the row meanwhile).
        $alreadymigrated = $DB->record_exists('exelearning_migration', [
            'sourcecomponent' => $src->get_component(),
            'sourcecmid'      => (int) $source->cmid,
        ]);
        if ($alreadymigrated) {
            return migration_result::from_source($source, migration_result::STATUS_ALREADYMIGRATED);
        }

        // 2. Cheap classification; blocked statuses skip without touching the course.
        $verdict = $src->classify($source);
        if (!$verdict->is_ok()) {
            activity_skipped::create([
                'context' => \context_course::instance((int) $source->course),
                'other'   => [
                    'sourcecomponent' => $src->get_component(),
                    'sourcecmid'      => (int) $source->cmid,
                    'reason'          => $verdict->status,
                ],
            ])->trigger();
            return migration_result::from_source($source, $verdict->status);
        }

        // 3. Resolve the actual .elpx (may extract).
        $elpxpath = $src->resolve_elpx($source);
        if ($elpxpath === null) {
            // Source vanished or corrupted between classify and resolve: degrade to nosource.
            return migration_result::from_source($source, migration_result::STATUS_NOSOURCE);
        }

        // 4. Create + install + grades + mapping, compensating on failure. No DB
        // transaction on purpose: file writes are not transactional and
        // course_delete_module() cannot run inside one (DEC-13-12).
        $targetcmid = null;
        try {
            $target = activity_builder::create_from_source($source, $src->get_target_grademodel());
            $targetcmid = (int) $target->cm->id;
            self::install_package($elpxpath, $target->instance, $target->contextid);
            if ($src->needs_grade_migration()) {
                overall_grade_migrator::migrate(
                    (int) $source->course,
                    $src->get_component(),
                    (int) $source->instanceid,
                    $target->instance
                );
            }
            $now = time();
            $DB->insert_record('exelearning_migration', (object) [
                'sourcecomponent' => $src->get_component(),
                'sourcecmid'      => (int) $source->cmid,
                'targetcmid'      => $targetcmid,
                'userid'          => (int) $USER->id,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($targetcmid !== null) {
                try {
                    self::delete_course_module($targetcmid, (int) $source->course);
                } catch (\Throwable $cleanup) {
                    $message .= ' / cleanup failed: ' . $cleanup->getMessage();
                }
            }
            migration_failed::create([
                'context' => \context_course::instance((int) $source->course),
                'other'   => [
                    'sourcecomponent' => $src->get_component(),
                    'sourcecmid'      => (int) $source->cmid,
                    'error'           => \core_text::substr($message, 0, 255),
                ],
            ])->trigger();
            return migration_result::from_source($source, migration_result::STATUS_ERROR, $message);
        }

        activity_migrated::create([
            'context'  => \context_module::instance($targetcmid),
            'objectid' => (int) $target->instance->id,
            'other'    => [
                'sourcecomponent' => $src->get_component(),
                'sourcecmid'      => (int) $source->cmid,
            ],
        ])->trigger();
        return migration_result::from_source(
            $source,
            migration_result::STATUS_MIGRATED,
            '',
            $targetcmid
        );
    }

    /**
     * Storage-level engine: stores a resolved .elpx into the target and extracts it.
     *
     * Public so it can be unit-tested without either sibling installed.
     *
     * @param string $elpxpath Absolute path to the .elpx to install.
     * @param \stdClass $instance Target exelearning instance row (needs id, revision).
     * @param int $contextid Target module context id.
     * @return void
     */
    public static function install_package(string $elpxpath, \stdClass $instance, int $contextid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $fs = get_file_storage();
        // Stage at package/{revision}/ for symmetry with the form/editor paths (issue 73).
        // The target is a freshly created activity, so there is no prior content to preserve;
        // scope the wipe to this itemid (idempotent re-run) rather than the whole filearea.
        $revision = (int) $instance->revision;
        $fs->delete_area_files($contextid, 'mod_exelearning', 'package', $revision);
        $fs->create_file_from_pathname(
            [
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'package',
                'itemid'    => $revision,
                'filepath'  => '/',
                'filename'  => 'imported.elpx',
            ],
            $elpxpath
        );
        // Extraction routes through package_manager::extract_stored(), which now owns the
        // valid-extraction guard: a corrupt/empty archive that produces no servable
        // index.html raises 'migrateextractfailed' there, so a corrupt package never gets
        // recorded as migrated (the caller catches it and rolls the target back).
        exelearning_extract_stored_package($contextid, (int) $instance->revision);

        exelearning_sync_grade_items($instance->id, $contextid);
    }

    /**
     * Deletes a course module across supported Moodle versions.
     *
     * Removes the cm, instance, context fileareas and grade items. Moodle 5.2
     * deprecated course_delete_module() (MDL-86856) in favour of the course-format
     * cm actions, so the new API is used when its delete() method is present and the
     * global function on 4.5–5.1. The caller guards against the recycle-bin hook.
     *
     * @param int $cmid Course module id to delete.
     * @param int $courseid Course the module belongs to.
     * @return void
     */
    private static function delete_course_module(int $cmid, int $courseid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        if (method_exists('\core_courseformat\local\cmactions', 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid);
        } else {
            course_delete_module($cmid);
        }
    }
}
