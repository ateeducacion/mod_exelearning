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
 * Gradebook synchronisation for mod_exelearning.
 *
 * Extracted verbatim from lib.php (DEC-71-01). It detects the gradable iDevices in
 * the stored package, registers/soft-deletes the matching Moodle grade items
 * (multi-itemnumber pattern, capped at gradeitems::MAX_ITEMNUMBER), reports the
 * change delta, warns the teacher when a graded re-upload alters the gradable set
 * with attempts already present (DEC-12-01), and re-publishes grades from the
 * attempt history. No grade math changed — every grade_update() call and guard is
 * preserved; lib.php keeps thin delegators with the original `exelearning_*`
 * signatures so every caller (view.php, editor/*, mod_form.php, migration) is
 * unchanged.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\grades;

use context;
use context_module;
use core_text;
use html_writer;
use mod_exelearning\local\attempts;
use mod_exelearning\local\package;
use mod_exelearning\local\package_manager;
use moodle_url;
use stdClass;

/**
 * Detects gradable iDevices and synchronises/publishes the gradebook.
 */
final class grade_sync {
    /**
     * Detects gradable iDevices in the stored package and synchronises grade items.
     *
     * Returns the change delta against the previously synced state so callers can
     * warn the teacher when editing a graded package alters the gradable set
     * (DEC-12-01). "changed" means the same objectid whose content block hash
     * differs, i.e. an in-place options/scoring edit.
     *
     * "capped" counts gradable iDevices that could not be registered because the
     * package exceeds gradeitems::MAX_ITEMNUMBER, so callers can warn the teacher.
     *
     * @param int $exelearningid
     * @param int|null $contextid
     * @return array{added:int,removed:int,changed:int,capped:int}
     */
    public static function sync(int $exelearningid, ?int $contextid = null): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $delta = ['added' => 0, 'removed' => 0, 'changed' => 0, 'capped' => 0];

        $instance = $DB->get_record('exelearning', ['id' => $exelearningid], '*', MUST_EXIST);

        if ($contextid === null) {
            $cm = get_coursemodule_from_instance('exelearning', $exelearningid);
            if (!$cm) {
                return $delta;
            }
            $contextid = context_module::instance($cm->id)->id;
        }
        $context = context::instance_by_id($contextid);

        // Master grading switch (DEC-13-07): when the activity is not graded, remove all
        // gradebook items (soft-delete our rows + delete the Moodle grade items, overall
        // included) and detect nothing. Attempt history (exelearning_attempt) is kept.
        if (empty($instance->gradeenabled)) {
            grade_item_manager::remove_all($instance);
            return $delta;
        }

        // Locate the ELPX in the 'package' filearea (any itemid: form=0,
        // Playground addModule=1, editor/save.php=revision).
        $elpx = package_manager::get_stored_package($context->id);
        if (!$elpx instanceof \stored_file) {
            return $delta;
        }

        $grademodel = (int) ($instance->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

        // Canonical grade item (itemnumber=0) according to the grading model
        // (DEC-0008, revised by DEC-25-01). The two models are now symmetric: OVERALL
        // shows only the aggregated column, PERITEM shows only the per-iDevice
        // columns. There is no longer a hidden overall stub in PERITEM — a hidden
        // item still shows (greyed) to teachers with moodle/grade:viewhidden and was
        // reported as a confusing "extra grade" (DEC-25-01). Completion-by-grade keeps
        // working the Moodle-native way: the teacher points completiongradeitemnumber
        // at a per-iDevice item (workshop model), or uses OVERALL mode to complete on
        // passing the activity as a whole (DEC-0010).
        if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
            // Overall only: the gradebook shows a single aggregated column (SCORM-style).
            // Pass hidden=0 explicitly so switching PERITEM -> OVERALL un-hides the
            // overall item; grade_update() leaves the flag untouched otherwise and the
            // column would stay hidden from when it was the completion-only stub.
            grade_item_manager::update_item($instance, null, ['hidden' => 0]);
        } else {
            // Per iDevice (default): no overall column at all. Delete any overall left
            // over from a previous sync or from the legacy hidden-stub model (DEC-25-01).
            grade_update(
                'mod/exelearning',
                $instance->course,
                'mod',
                'exelearning',
                $instance->id,
                0,
                null,
                ['deleted' => true]
            );
        }

        // Detection.
        $detected = (new package($elpx))->detect_gradable_idevices();

        // Record that this revision has been scanned for gradable iDevices, even when
        // none were found. Without this marker the view.php self-heal (which is keyed
        // on "has no gradable grade item") re-extracts and re-parses the whole ELPX
        // on EVERY view of a content-only package, since that condition stays
        // permanently true. Stored as max(revision, 1) so a package with revision=0
        // (e.g. a programmatic Playground upload) is still marked as scanned and not
        // re-extracted on every load.
        $DB->set_field(
            'exelearning',
            'gradesyncrev',
            max((int) $instance->revision, 1),
            ['id' => $exelearningid]
        );

        if ($detected === []) {
            // No gradable iDevices: PERITEM has no grade items at all, OVERALL keeps
            // its single aggregated column. Place whatever exists under the configured
            // grade category (DEC-19-01); a no-op when there are no items.
            grade_item_manager::apply_category($instance);
            return $delta;
        }

        $existing = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $exelearningid],
            '',
            'objectid, id, itemnumber, deleted, contenthash'
        );

        $nextnum = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
                 WHERE exelearningid = ?",
            [$exelearningid]
        );

        $seen = [];
        $capwarned = false;
        foreach ($detected as $d) {
            // Clamp the package-controlled identifiers to their column widths before
            // they are used as the $existing lookup key or written to the DB, so an
            // adversarial/overlong content.xml cannot throw a dml_write_exception
            // mid-sync (a student-facing fatal through the view.php self-heal) (B5,
            // DEC-34-01). objectid/pageid are char(191), idevicetype char(64).
            $d->idevicetype = core_text::substr((string) $d->idevicetype, 0, 64);
            $d->objectid    = core_text::substr((string) $d->objectid, 0, 191);
            $d->pageid      = ($d->pageid === null)
                ? null
                : core_text::substr((string) $d->pageid, 0, 191);

            $name = grade_item_manager::format_name($instance, $d);
            $now = time();

            $newhash = $d->contenthash ?? null;

            if (isset($existing[$d->objectid])) {
                $row = $existing[$d->objectid];
                // An in-place options/scoring edit keeps the objectid but changes
                // the content block hash; a re-appearing (un-deleted) iDevice also
                // counts as a change worth flagging. Rows synced before this column
                // existed have a NULL hash: backfill silently, never flag, so the
                // first sync after upgrade does not warn on every iDevice.
                $oldhash = $row->contenthash ?? null;
                if (($oldhash !== null && $oldhash !== $newhash) || (int) $row->deleted === 1) {
                    $delta['changed']++;
                }
                $row->name         = $name;
                $row->idevicetype  = $d->idevicetype;
                $row->pageid       = $d->pageid;
                $row->deleted      = 0;
                $row->contenthash  = $newhash;
                $row->timemodified = $now;
                $DB->update_record('exelearning_grade_item', $row);
                $itemnumber = (int) $row->itemnumber;
            } else {
                // Moodle 5.x can only label grade items whose itemnumber is declared
                // in the component mapping (gradeitems::MAX_ITEMNUMBER). Registering
                // beyond that creates columns Moodle cannot name, breaking the
                // completion-via-grade dropdown and Course overview labelling, so we
                // stop registering further iDevices once the cap is reached.
                if ($nextnum >= gradeitems::MAX_ITEMNUMBER) {
                    $delta['capped']++;
                    if (!$capwarned) {
                        debugging(
                            'mod_exelearning: package has more than '
                                . gradeitems::MAX_ITEMNUMBER
                                . ' gradable iDevices; the extra items are not registered '
                                . 'as gradebook columns.',
                            DEBUG_DEVELOPER
                        );
                        $capwarned = true;
                    }
                    continue;
                }
                $nextnum++;
                $itemnumber = $nextnum;
                $delta['added']++;
                $DB->insert_record('exelearning_grade_item', (object) [
                    'exelearningid' => $exelearningid,
                    'itemnumber'    => $itemnumber,
                    'objectid'      => $d->objectid,
                    'pageid'        => $d->pageid,
                    'idevicetype'   => $d->idevicetype,
                    'name'          => $name,
                    'grademax'      => $instance->grademax ?? 100,
                    'grademin'      => $instance->grademin ?? 0,
                    'deleted'       => 0,
                    'contenthash'   => $newhash,
                    'timecreated'   => $now,
                    'timemodified'  => $now,
                ]);
            }
            $seen[$d->objectid] = true;

            if ($grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
                // Overall only: do not expose per-iDevice columns in the gradebook
                // (the row is kept for the attempts report).
                grade_update(
                    'mod/exelearning',
                    $instance->course,
                    'mod',
                    'exelearning',
                    $instance->id,
                    $itemnumber,
                    null,
                    ['deleted' => true]
                );
            } else {
                grade_update(
                    'mod/exelearning',
                    $instance->course,
                    'mod',
                    'exelearning',
                    $instance->id,
                    $itemnumber,
                    null,
                    [
                            'itemname'  => $name,
                            'gradetype' => GRADE_TYPE_VALUE,
                            'grademax'  => $instance->grademax ?? 100,
                            'grademin'  => $instance->grademin ?? 0,
                            'display'   => (int) ($instance->gradedisplaytype ?? GRADE_DISPLAY_TYPE_DEFAULT),
                    ]
                );
            }
        }

        // Mark previously-known items that are gone as deleted, AND remove their
        // gradebook column. Marking our own row is not enough: the Moodle grade
        // item only disappears from the gradebook when grade_update() is called
        // with ['deleted' => true]. Grade history in grade_grades is preserved.
        foreach ($existing as $objectid => $row) {
            if (!isset($seen[$objectid]) && !$row->deleted) {
                $delta['removed']++;
                $row->deleted = 1;
                $row->timemodified = time();
                $DB->update_record('exelearning_grade_item', $row);
                grade_update(
                    'mod/exelearning',
                    $instance->course,
                    'mod',
                    'exelearning',
                    $instance->id,
                    (int) $row->itemnumber,
                    null,
                    ['deleted' => true]
                );
            }
        }

        // Place the overall and every per-iDevice column under the configured grade
        // category (DEC-19-01); grade_update() above cannot do this itself.
        grade_item_manager::apply_category($instance);

        return $delta;
    }

    /**
     * Queues a teacher-facing warning when editing a graded package changed its
     * gradable set while student attempts already exist (DEC-12-01).
     *
     * mod_exelearning keeps the snapshot semantics of mod_scorm / mod_h5pactivity:
     * existing attempts and the grades derived from them are NOT recomputed when the
     * content changes — the scoring runs client-side, so the server cannot re-derive
     * a past attempt's score against the new content. Mirroring mod_scorm's
     * "confirmloosetracks" notice, we tell the teacher so they can reset attempts
     * from the report if the edited tasks make the old grades misleading.
     *
     * @param int $exelearningid
     * @param array $delta From sync(): keys added, removed, changed.
     * @param int|null $cmid Course module id, to link the attempts report.
     * @return void
     */
    public static function warn_if_stale(int $exelearningid, array $delta, ?int $cmid = null): void {
        $changes = (int) ($delta['added'] ?? 0)
            + (int) ($delta['removed'] ?? 0)
            + (int) ($delta['changed'] ?? 0);
        if ($changes === 0) {
            return;
        }
        if (!attempts::activity_has_attempts($exelearningid)) {
            return;
        }
        $message = get_string('gradesetchangedwarning', 'mod_exelearning');
        if ($cmid !== null) {
            $url = new moodle_url('/mod/exelearning/report.php', ['id' => $cmid]);
            $message .= ' ' . html_writer::link($url, get_string('attemptsreport', 'mod_exelearning'));
        }
        \core\notification::warning($message);
    }

    /**
     * Queues a teacher-facing warning when the package has more gradable iDevices
     * than gradeitems::MAX_ITEMNUMBER, so the cap is no longer silent (it was only a
     * developer-level debugging() call). The excess iDevices are not registered as
     * gradebook columns; the teacher should split the content into separate activities.
     *
     * @param array $delta From sync(): the "capped" key counts the dropped iDevices.
     * @return void
     */
    public static function warn_if_capped(array $delta): void {
        $capped = (int) ($delta['capped'] ?? 0);
        if ($capped <= 0) {
            return;
        }
        \core\notification::warning(get_string(
            'gradeitemcapexceeded',
            'mod_exelearning',
            gradeitems::MAX_ITEMNUMBER
        ));
    }

    /**
     * Re-publishes the activity's gradebook grades from the stored attempt history.
     *
     * This is the second half of the gradebook module contract: core's
     * grade_update_mod_grades() only re-syncs a module when BOTH
     * exelearning_grade_item_update() and exelearning_update_grades() exist. With
     * only the former declared, core did nothing (and logged "you have declared one
     * of ... but not both"), so course-reset "remove all grades", grade-item unlock
     * and user-undelete history recovery silently dropped every exelearning grade
     * while exelearning_attempt still held the data (B2b, DEC-34-01).
     *
     * Each user's grade is re-aggregated from exelearning_attempt with the current
     * grademethod/grademodel via grade_recalculator, so it is also the correct
     * primitive to call after a pure grademodel/grademethod change, which deletes and
     * recreates the gradebook columns empty (B2).
     *
     * @param stdClass $exelearning The activity instance row.
     * @param int $userid Recalculate a single user (0 = every user with attempts).
     * @return void
     */
    public static function update_grades(stdClass $exelearning, int $userid = 0): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        // Not graded (DEC-13-07): no grade items exist, so there is nothing to publish.
        if (empty($exelearning->gradeenabled)) {
            return;
        }

        if ($userid > 0) {
            grade_recalculator::recalculate_user($exelearning, $userid);
            return;
        }

        $userids = $DB->get_fieldset_sql(
            'SELECT DISTINCT userid FROM {exelearning_attempt} WHERE exelearningid = ?',
            [$exelearning->id]
        );
        if (empty($userids)) {
            return;
        }
        grade_recalculator::recalculate_for_users($exelearning, array_map('intval', $userids));
    }
}
