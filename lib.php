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
 * mod_exelearning public API.
 *
 * Implements two concerns:
 *   1. Storage and extraction of an ELPX package (mod_exeweb style):
 *      `mod_exelearning/package/0/<file>.elpx` and `mod_exelearning/content/<revision>/…`
 *      served via `exelearning_pluginfile()`.
 *   2. Multi-itemnumber gradebook pattern (AN-002 + AN-007):
 *      parses `content.xml`, enumerates gradable iDevices and registers N
 *      grade items with `grade_update(..., itemnumber=$n, ...)`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// DEC-0-08 (rev. 2026-05-29): gradebook columns model. Two mutually-exclusive
// presentations; the "both" mode was removed. Default is per-iDevice.
define('EXELEARNING_GRADEMODEL_OVERALL', 0); // Overall grade only (itemnumber=0).
define('EXELEARNING_GRADEMODEL_PERITEM', 1); // One column per gradable iDevice (default).

// DEC-69-01: custom completion rule `completionstatusrequired`. The activity is
// marked complete when the user's attempt reaches a required status. The column is
// nullable; NULL disables the rule. A module-level rule (one state per module),
// aligned with Moodle's completion abstraction (DEC-67-01 rejected per-iDevice).
define('EXELEARNING_COMPLETIONSTATUS_PASSED', 1); // Require a passed attempt.
define('EXELEARNING_COMPLETIONSTATUS_COMPLETED', 2); // Require a completed attempt.
define('EXELEARNING_COMPLETIONSTATUS_ANY', 3); // Require a passed OR completed attempt.

/**
 * Features supported by this module.
 *
 * @param string $feature
 * @return mixed
 */
function exelearning_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_ASSIGNMENT;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Add new instance.
 *
 * @param stdClass $data
 * @param mod_exelearning_mod_form|null $mform
 * @return int new instance id
 */
function exelearning_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->revision = 1;
    if (!isset($data->grademax)) {
        $data->grademax = 100;
    }
    if (!isset($data->grademin)) {
        $data->grademin = 0;
    }
    if (!isset($data->gradepass)) {
        $data->gradepass = 0;
    }
    if (!isset($data->grademethod)) {
        $data->grademethod = \mod_exelearning\local\attempts::GRADE_HIGHEST;
    }
    if (!isset($data->grademodel)) {
        $data->grademodel = EXELEARNING_GRADEMODEL_PERITEM;
    }
    if (!isset($data->maxattempt)) {
        $data->maxattempt = 0;
    }
    if (!isset($data->reviewmode)) {
        $data->reviewmode = \mod_exelearning\local\attempts::REVIEW_ALWAYS;
    }
    if (!isset($data->teachermodevisible)) {
        $data->teachermodevisible = 0;
    }
    if (!isset($data->gradecat)) {
        $data->gradecat = 0;
    }
    // Custom completion rule (DEC-69-01): NULL disables the rule. mod_form's
    // data_postprocessing() already normalises the submitted value to an int or
    // null; default to null when the caller does not provide it.
    if (!isset($data->completionstatusrequired)) {
        $data->completionstatusrequired = null;
    }

    $data->id = $DB->insert_record('exelearning', $data);

    // Persist the uploaded ELPX and extract it to the content filearea.
    exelearning_save_and_extract_package($data);

    // Detect gradable iDevices and synchronise grade items.
    // We pass the contextid explicitly because the course_module row is created
    // by Moodle AFTER returning from _add_instance.
    $contextid = context_module::instance($data->coursemodule)->id;
    $delta = exelearning_sync_grade_items($data->id, $contextid);

    // Warn the teacher if the package has more gradable iDevices than the gradebook
    // can register (the excess are dropped); the notice renders on the next page.
    exelearning_warn_if_grade_items_capped($delta);

    return $data->id;
}

/**
 * Update existing instance.
 *
 * @param stdClass $data
 * @param mod_exelearning_mod_form|null $mform
 * @return bool
 */
function exelearning_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    // Snapshot the pre-update grading model/method so we can tell a pure grading
    // change (re-aggregate valid attempts) from a package re-upload (DEC-12-01
    // snapshot-and-warn) after the record is written (B2, DEC-34-01).
    $oldrow = $DB->get_record(
        'exelearning',
        ['id' => $data->id],
        'revision, grademodel, grademethod',
        MUST_EXIST
    );
    $data->revision = (int) ($oldrow->revision ?: 0) + 1;
    if (!isset($data->grademax)) {
        $data->grademax = 100;
    }
    if (!isset($data->grademin)) {
        $data->grademin = 0;
    }
    if (!isset($data->gradepass)) {
        $data->gradepass = 0;
    }
    if (!isset($data->grademethod)) {
        $data->grademethod = \mod_exelearning\local\attempts::GRADE_HIGHEST;
    }
    if (!isset($data->grademodel)) {
        $data->grademodel = EXELEARNING_GRADEMODEL_PERITEM;
    }
    if (!isset($data->maxattempt)) {
        $data->maxattempt = 0;
    }
    if (!isset($data->reviewmode)) {
        $data->reviewmode = \mod_exelearning\local\attempts::REVIEW_ALWAYS;
    }
    if (!isset($data->teachermodevisible)) {
        $data->teachermodevisible = 0;
    }
    if (!isset($data->gradecat)) {
        $data->gradecat = 0;
    }
    // Custom completion rule (DEC-69-01): NULL disables the rule. mod_form's
    // data_postprocessing() sets this to an int or null; default to null when the
    // caller does not provide it so an update never leaves a stray stale value.
    if (!isset($data->completionstatusrequired)) {
        $data->completionstatusrequired = null;
    }

    $contextid = context_module::instance($data->coursemodule)->id;

    // Extract and validate the new revision BEFORE advancing the stored pointer (issue 73):
    // a corrupt replacement throws here and leaves the DB row and the previous content
    // untouched, so the activity keeps serving its last validated revision.
    exelearning_save_and_extract_package($data);
    $DB->update_record('exelearning', $data);

    // The new revision is now validated and active. Prune the superseded content/package
    // revisions only after the pointer has moved (so no concurrent view sees a gap), and
    // only when the new revision actually produced servable content (a programmatic update
    // with no package field skips extraction and relies on the view.php self-heal).
    $fs = get_file_storage();
    if ($fs->get_file($contextid, 'mod_exelearning', 'content', (int) $data->revision, '/', 'index.html')) {
        \mod_exelearning\local\package_manager::prune_content_revisions($contextid, (int) $data->revision);
        if (($storedpackage = exelearning_get_stored_package($contextid)) !== null) {
            \mod_exelearning\local\package_manager::prune_package_revisions(
                $contextid,
                (int) $storedpackage->get_itemid()
            );
        }
    }

    $delta = exelearning_sync_grade_items($data->id, $contextid);

    // A pure grading model/method change leaves the stored attempts valid, but
    // exelearning_sync_grade_items() deletes and recreates the gradebook columns
    // empty (PERITEM<->OVERALL) or keeps them aggregated with the old method — so
    // the published grades would vanish or go stale until students resubmit.
    // Re-publish them from the attempt history (B2, DEC-34-01). A package re-upload
    // (content change) is deliberately NOT recomputed here: it keeps DEC-12-01
    // snapshot-and-warn semantics via exelearning_warn_if_grades_stale() below.
    if (
        (int) $data->grademodel !== (int) $oldrow->grademodel
        || (int) $data->grademethod !== (int) $oldrow->grademethod
    ) {
        exelearning_update_grades($data, 0);
    }

    // Re-uploading a package over an activity that already has attempts may add,
    // remove or re-score gradable iDevices; warn that old grades are not
    // recomputed (DEC-12-01). The notice renders on the post-form redirect.
    exelearning_warn_if_grades_stale($data->id, $delta, (int) $data->coursemodule);

    // Also warn if the package exceeds the gradebook item cap (excess iDevices dropped).
    exelearning_warn_if_grade_items_capped($delta);

    return true;
}

/**
 * Delete existing instance.
 *
 * @param int $id
 * @return bool
 */
function exelearning_delete_instance($id) {
    global $DB;

    $instance = $DB->get_record('exelearning', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    $maxnum = (int) $DB->get_field_sql(
        "SELECT COALESCE(MAX(itemnumber),0) FROM {exelearning_grade_item}
             WHERE exelearningid = ?",
        [$id]
    );
    for ($n = 0; $n <= $maxnum; $n++) {
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $id,
            $n,
            null,
            ['deleted' => true]
        );
    }

    $DB->delete_records('exelearning_attempt', ['exelearningid' => $id]);
    $DB->delete_records('exelearning_grade_item', ['exelearningid' => $id]);
    $DB->delete_records('exelearning', ['id' => $id]);

    return true;
}

/**
 * Returns information to be displayed on the course page and the custom completion
 * rule configuration for the activity (DEC-69-01).
 *
 * Exposes the stored completionstatusrequired field so
 * \mod_exelearning\completion\custom_completion can read the rule's required status
 * from $cm->customdata. Mirrors mod_scorm's scorm_get_coursemodule_info().
 *
 * @param stdClass $coursemodule The course module record.
 * @return cached_cm_info|false An object with the cached information, or false if the
 *         instance is missing.
 */
function exelearning_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionstatusrequired';
    if (!$exelearning = $DB->get_record('exelearning', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $exelearning->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('exelearning', $exelearning, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only when the
    // completion mode is 'automatic'. A NULL value means the rule is disabled, in
    // which case activity_custom_completion treats it as not available.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionstatusrequired'] =
            $exelearning->completionstatusrequired;
    }

    return $result;
}

/**
 * Adds the course-reset form elements for mod_exelearning.
 *
 * Without these callbacks "Reset course" leaves every previous student's
 * attempt rows and gradebook grades intact (unlike mod_scorm/mod_h5pactivity),
 * so a recycled course inherits stale per-user data.
 *
 * @param MoodleQuickForm $mform The course-reset form being built.
 */
function exelearning_reset_course_form_definition($mform) {
    $mform->addElement('header', 'exelearningheader', get_string('modulenameplural', 'mod_exelearning'));
    $mform->addElement('advcheckbox', 'reset_exelearning', get_string('resetattempts', 'mod_exelearning'));
}

/**
 * Default values for the course-reset form (option checked by default).
 *
 * @param stdClass $course The course being reset.
 * @return array<string,int>
 */
function exelearning_reset_course_form_defaults($course) {
    return ['reset_exelearning' => 1];
}

/**
 * Removes all user attempt data and clears the gradebook for every
 * mod_exelearning instance in a course when the teacher resets it.
 *
 * @param stdClass $data The reset form data (courseid + reset_exelearning flag).
 * @return array<int,array<string,mixed>> Status entries for the reset report.
 */
function exelearning_reset_userdata($data) {
    global $DB;

    $status = [];
    if (empty($data->reset_exelearning)) {
        return $status;
    }

    $instanceids = $DB->get_fieldset_select(
        'exelearning',
        'id',
        'course = ?',
        [$data->courseid]
    );
    foreach ($instanceids as $instanceid) {
        $DB->delete_records('exelearning_attempt', ['exelearningid' => $instanceid]);
    }

    // Clear the gradebook so attempt deletion and grades stay consistent.
    exelearning_reset_gradebook($data->courseid);

    $status[] = [
        'component' => get_string('modulenameplural', 'mod_exelearning'),
        'item'      => get_string('resetattempts', 'mod_exelearning'),
        'error'     => false,
    ];

    return $status;
}

/**
 * Resets the gradebook grades of every mod_exelearning instance in a course.
 *
 * Called from exelearning_reset_userdata() and by the core gradebook-reset
 * workflow. Each registered grade item (overall + per iDevice) is reset via
 * grade_update(..., ['reset' => true]).
 *
 * @param int $courseid The course being reset.
 * @param string $type Optional grade reset type (unused; core API parity).
 */
function exelearning_reset_gradebook($courseid, $type = '') {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instances = $DB->get_records('exelearning', ['course' => $courseid], '', 'id');
    foreach ($instances as $instance) {
        // Reset only the grade items that actually exist in the gradebook. Looping
        // 0..MAX(itemnumber) and calling grade_update(['reset']) blindly used to
        // INSERT a bare, unnamed 100-point grade item for every itemnumber without
        // a live column — core grade_update() short-circuits a missing item only
        // for 'deleted', not 'reset'. In PERITEM the overall (0) never exists, in
        // OVERALL no per-iDevice item exists, and soft-deleted iDevices still raise
        // MAX(itemnumber), so a course reset spawned phantom columns that inflated
        // the course total (B3, DEC-34-01).
        $items = grade_item::fetch_all([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'courseid'     => $courseid,
        ]);
        if (!$items) {
            continue;
        }
        foreach ($items as $item) {
            grade_update(
                'mod/exelearning',
                $courseid,
                'mod',
                'exelearning',
                $instance->id,
                (int) $item->itemnumber,
                null,
                ['reset' => true]
            );
        }
    }
}

/**
 * Stub for the canonical grade item (itemnumber=0). In multi-item mode the rest
 * are created directly in exelearning_sync_grade_items().
 *
 * @param stdClass $exelearning
 * @param mixed $grades
 * @param array $itemdetails Extra grade item fields passed to grade_update().
 * @return int
 */
function exelearning_grade_item_update($exelearning, $grades = null, array $itemdetails = []) {
    return \mod_exelearning\grades\grade_item_manager::update_item($exelearning, $grades, $itemdetails);
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
 * grademethod/grademodel via exelearning_recalculate_user_grades(), so it is also
 * the correct primitive to call after a pure grademodel/grademethod change, which
 * deletes and recreates the gradebook columns empty (B2).
 *
 * @param stdClass $exelearning The activity instance row.
 * @param int $userid Recalculate a single user (0 = every user with attempts).
 * @return void
 */
function exelearning_update_grades(stdClass $exelearning, int $userid = 0): void {
    \mod_exelearning\grades\grade_sync::update_grades($exelearning, $userid);
}

/**
 * Required so that Moodle 5.x can display items with itemnumber>0 in
 * "Course overview" (without this function Moodle cannot label the columns).
 *
 * @param array $items grade_item objects (same iteminstance, different itemnumber).
 * @return array<int,string> indexed by grade_item->id.
 */
function exelearning_get_grade_item_names(array $items): array {
    global $DB;

    $names = [];
    foreach ($items as $item) {
        if ((int) $item->itemnumber === 0) {
            $names[$item->id] = get_string('gradeitem_overall', 'mod_exelearning');
            continue;
        }
        $row = $DB->get_record(
            'exelearning_grade_item',
            ['exelearningid' => $item->iteminstance, 'itemnumber' => $item->itemnumber],
            'name'
        );
        $names[$item->id] = $row ? format_string($row->name)
                : ('Item #' . $item->itemnumber);
    }
    return $names;
}

/**
 * pluginfile callback: serves files from the extracted package.
 *
 * Scheme (same as mod_exeweb):
 *   - `package`   itemid=0           Original ZIP (teachers only).
 *   - `content`   itemid=$revision    Files extracted from the ZIP.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool|null
 */
function exelearning_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG, $DB;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);
    require_capability('mod/exelearning:view', $context);

    if ($filearea === 'package') {
        // Only teachers can download the full ELPX package.
        require_capability('moodle/course:manageactivities', $context);
        $itemid = (int) array_shift($args);
        $fs = get_file_storage();
        $relativepath = implode('/', $args);
        $fullpath = "/{$context->id}/mod_exelearning/package/{$itemid}/{$relativepath}";
        if (!$file = $fs->get_file_by_hash(sha1($fullpath))) {
            return false;
        }
        send_stored_file($file, null, 0, $forcedownload, $options);
        return null;
    }

    if ($filearea !== 'content') {
        return false;
    }

    $revision = (int) array_shift($args);
    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = rtrim("/{$context->id}/mod_exelearning/{$filearea}/{$revision}/{$relativepath}", '/');

    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file) {
        // Fallback to index.html inside the requested folder (as mod_exeweb does).
        foreach (['index.html', 'index.htm', 'Default.htm'] as $candidate) {
            $file = $fs->get_file_by_hash(sha1("{$fullpath}/{$candidate}"));
            if ($file) {
                break;
            }
        }
    }
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Serve SVG inline (eXeLearning v4 embeds icons in content/css/icons/ that
    // must render, not be downloaded). Same flag used by mod_scorm.
    $options['dontforcesvgdownload'] = true;

    // Reasonable cache-control: a revision bump automatically invalidates the URL.
    $lifetime = $CFG->filelifetime ?? 86400;
    send_stored_file($file, $lifetime, 0, $forcedownload, $options);
    return null;
}

/**
 * Indicates that this activity has downloadable content under `content` and `package`.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @return array
 */
function exelearning_get_file_areas($course, $cm, $context) {
    return [
        'content' => get_string('areacontent', 'mod_exelearning'),
        'package' => get_string('areapackage', 'mod_exelearning'),
    ];
}

/**
 * Trigger course_module_viewed + completion update.
 *
 * @param stdClass $exelearning
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 */
function exelearning_view(
    stdClass $exelearning,
    stdClass $course,
    stdClass $cm,
    context $context
): void {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $params = [
        'context' => $context,
        'objectid' => $exelearning->id,
    ];
    $event = \mod_exelearning\event\course_module_viewed::create($params);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('exelearning', $exelearning);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Adds the "Reports" tab to the activity navigation, like mod_scorm.
 *
 * It appears in the module's secondary navigation for anyone with the capability
 * mod/exelearning:viewreport, linking to the attempts report (report.php).
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 */
function exelearning_extend_settings_navigation(
    settings_navigation $settings,
    navigation_node $node
): void {
    global $PAGE, $DB;

    $context = $PAGE->cm->context ?? null;
    if (!$context || !has_capability('mod/exelearning:viewreport', $context)) {
        return;
    }
    // No reports node when the activity is not graded (DEC-13-07).
    if (!$DB->get_field('exelearning', 'gradeenabled', ['id' => $PAGE->cm->instance])) {
        return;
    }

    $url = new moodle_url('/mod/exelearning/report.php', ['id' => $PAGE->cm->id]);
    $reportnode = navigation_node::create(
        get_string('reports', 'mod_exelearning'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'exelearningreport',
        new pix_icon('i/report', '')
    );

    // Insert before the roles/permissions node if present, as SCORM does.
    if ($beforekey = exelearning_navigation_before_key($node)) {
        $node->add_node($reportnode, $beforekey);
    } else {
        $node->add_node($reportnode);
    }
}

/**
 * Returns the key of the first "administrative" node in the module navigation
 * (roles/permissions/filters) so that "Reports" is inserted just before it,
 * replicating mod_scorm's ordering.
 *
 * @param navigation_node $node
 * @return string|null
 */
function exelearning_navigation_before_key(navigation_node $node): ?string {
    return \mod_exelearning\local\urls::navigation_before_key($node);
}

/**
 * Saves the uploaded ELPX in the 'package' filearea and extracts it to 'content/{revision}/'.
 *
 * @param stdClass $data Form data (with `coursemodule`, `package` draftid, `revision`).
 */
function exelearning_save_and_extract_package(stdClass $data): void {
    \mod_exelearning\local\package_manager::save_and_extract($data);
}

/**
 * Locate the stored ELPX in the 'package' filearea WITHOUT assuming an itemid.
 *
 * The form upload stores it at itemid=0, but programmatic paths leave it at a
 * different itemid (e.g. the Moodle Playground `addModule`, which uploads with
 * `itemid: 1`, or `editor/save.php`, which uses the revision as itemid). We scan
 * ALL itemids and return the most recent file.
 *
 * @param int $contextid
 * @return \stored_file|null
 */
function exelearning_get_stored_package(int $contextid): ?\stored_file {
    return \mod_exelearning\local\package_manager::get_stored_package($contextid);
}

/**
 * Whether a stored package archive is a real eXeLearning v4 package.
 *
 * Both `.elpx` and `.zip` are accepted on upload (DEC-16-01); the genuine marker is
 * a `content.xml` (ODE 2.0) entry at the archive root, which every eXeLearning v4
 * export contains. Used by mod_form to reject an arbitrary .zip at submit time.
 *
 * @param \stored_file $file The uploaded package (.elpx or .zip).
 * @return bool True when the archive contains content.xml at its root.
 */
function exelearning_package_has_content_xml(\stored_file $file): bool {
    return \mod_exelearning\local\package_manager::validate_content_xml($file);
}

/**
 * Extracts the ELPX already stored in `package` to `content/{revision}/`.
 *
 * Kept separate from exelearning_save_and_extract_package() so it can be
 * re-run WITHOUT a draft itemid (e.g. the view.php self-heal when a programmatic
 * upload such as the Playground's `addModule` left the package in the 'package'
 * filearea but did not extract/sync it). Idempotent: clears previous content
 * and re-extracts.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_extract_stored_package(int $contextid, int $revision): void {
    \mod_exelearning\local\package_manager::extract_stored($contextid, $revision);
}

/**
 * Injects SCORM wrapper script tags into the <head> of index.html and all
 * html/<slug>.html pages of the extracted package.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_inject_scorm_loader(int $contextid, int $revision): void {
    \mod_exelearning\local\scorm\scorm_injector::inject($contextid, $revision);
}

/**
 * Whether the extracted package bundles the eXeLearning xAPI emitter
 * (`libs/xapi/exe_xapi.js`, upstream PR #1867).
 *
 * Drives the channel choice in view.php (DEC-85-01): an xAPI-capable package grades via
 * xAPI (the SCORM shim is kept inert), while a legacy package keeps SCORM grading.
 *
 * @param int $contextid The activity module context id.
 * @param int $revision  The content filearea revision (itemid).
 * @return bool True when the emitter is present in the served content.
 */
function exelearning_package_emits_xapi(int $contextid, int $revision): bool {
    return get_file_storage()->file_exists(
        $contextid,
        'mod_exelearning',
        'content',
        $revision,
        '/libs/xapi/',
        'exe_xapi.js'
    );
}

/**
 * Whether the xAPI-primary grading channel is enabled site-wide (DEC-85-01).
 *
 * Master switch (admin setting `exelearning/xapiprimaryenabled`) sitting in front of the
 * per-package channel choice in view.php and the `xapi_track.php` endpoint: when on
 * (default), a package that bundles the eXeLearning xAPI emitter grades via xAPI and the
 * SCORM shim is kept inert; when off, those packages fall back to SCORM grading with no
 * code change (a kill switch). An unset value — e.g. a site upgraded before the setting
 * existed — is treated as enabled, matching the configcheckbox default.
 *
 * This is NOT cmi5 and NOT an external-LRS integration; SCORM 1.2 stays the compatibility
 * path for packages without the emitter (DEC-0-03).
 *
 * @return bool
 */
function exelearning_xapi_primary_enabled(): bool {
    $value = get_config('exelearning', 'xapiprimaryenabled');
    return ($value === false) ? true : (bool) (int) $value;
}

/**
 * Drop the `body.exe-scorm` condition from the score-save guard of the `form` and
 * `scrambled-list` iDevices in the extracted package (issue #13).
 *
 * eXeLearning's `exe-scorm` body class is its "running as a SCORM export" switch.
 * The web/elpx export we serve does not carry it, and 49 of 51 iDevices either do
 * not touch it or only use it to load the SCORM wrapper (which we already inject).
 * Only `form` and `scrambled-list` put `body.hasClass('exe-scorm')` in front of
 * their `sendScore()` call, so they never persist their score here — their
 * cmi.suspend_data entry stays at the seeded 0 (the gradebook shows 0).
 *
 * Rather than add `exe-scorm` to the body (which would also switch on the SCO page
 * lifecycle and the SCORM presentation CSS), we apply the same one-line change
 * upstream describes (exelearning/exelearning#1925) at serve time, only to these
 * two save guards, so they behave like every other gradable iDevice (save on
 * `isScorm > 0`). The patch targets the unique `data.isScorm` variant of the guard
 * (the init-time guards use `ldata.isScorm`), is idempotent (the matched string is
 * removed), and degrades safely: if a future producer reformats the guard the
 * replace is a no-op and behaviour reverts to today's. See research ADR DEC-13-11.
 *
 * @param int $contextid
 * @param int $revision
 */
function exelearning_patch_idevice_save_guards(int $contextid, int $revision): void {
    \mod_exelearning\local\scorm\idevice_patch::patch($contextid, $revision);
}

/**
 * Removes all gradebook items of an activity (master grading switch off, DEC-13-07).
 *
 * Soft-deletes the plugin's grade-item mapping rows and deletes the matching Moodle
 * grade items, including the overall item (itemnumber 0), so nothing shows in the
 * gradebook. The attempt history (exelearning_attempt) is preserved, so re-enabling
 * grading re-detects and recomputes from it.
 *
 * @param stdClass $instance The exelearning instance row.
 * @return void
 */
function exelearning_remove_all_grade_items(stdClass $instance): void {
    \mod_exelearning\grades\grade_item_manager::remove_all($instance);
}

/**
 * Detects gradable iDevices in the stored package and synchronises grade items.
 *
 * Returns the change delta against the previously synced state so callers can
 * warn the teacher when editing a graded package alters the gradable set
 * (DEC-12-01). "changed" means the same objectid whose content block hash
 * differs, i.e. an in-place options/scoring edit.
 *
 * @param int $exelearningid
 * @param int|null $contextid
 * @return array{added:int,removed:int,changed:int,capped:int}
 */
function exelearning_sync_grade_items(int $exelearningid, ?int $contextid = null): array {
    return \mod_exelearning\grades\grade_sync::sync($exelearningid, $contextid);
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
 * @param array $delta From exelearning_sync_grade_items(): keys added, removed, changed.
 * @param int|null $cmid Course module id, to link the attempts report.
 * @return void
 */
function exelearning_warn_if_grades_stale(int $exelearningid, array $delta, ?int $cmid = null): void {
    \mod_exelearning\grades\grade_sync::warn_if_stale($exelearningid, $delta, $cmid);
}

/**
 * Queues a teacher-facing warning when the package has more gradable iDevices than
 * the gradebook can register (gradeitems::MAX_ITEMNUMBER). The excess items are
 * silently dropped from the gradebook, so this surfaces the cap instead of leaving
 * it to a developer-only debugging() call.
 *
 * @param array $delta From exelearning_sync_grade_items(): the "capped" key counts dropped iDevices.
 * @return void
 */
function exelearning_warn_if_grade_items_capped(array $delta): void {
    \mod_exelearning\grades\grade_sync::warn_if_capped($delta);
}

/**
 * Recalculates a student's gradebook grades from their attempt history,
 * respecting grademethod and grademodel. Used after deleting an attempt
 * (DEC-0-07 phase 2). If an item has no remaining attempts, clears its grade
 * (rawgrade=null).
 *
 * Single-user façade kept for its existing callers (report.php attempt deletion,
 * privacy provider erasure). Delegates to exelearning_recalculate_grades_for_users()
 * so the aggregation/publish logic lives in one place.
 *
 * @param stdClass $instance
 * @param int $userid
 */
function exelearning_recalculate_user_grades(stdClass $instance, int $userid): void {
    \mod_exelearning\grades\grade_recalculator::recalculate_user($instance, $userid);
}

/**
 * Recalculates the gradebook grades of several users in a single batch.
 *
 * Bulk entry point for exelearning_update_grades($exelearning, 0): one SELECT for
 * every user's attempts (attempts::fetch_scaled_by_user_item()), an in-memory
 * group-by, and one grade_update() per itemnumber with the grades keyed by userid.
 * This replaces the former users × items N+1 (one SELECT and one grade_update()
 * per user per item).
 *
 * Aggregation respects grademethod (DEC-0-07, via attempts::aggregate_values()) and
 * the grademodel column rules: PERITEM has no overall column (DEC-25-01), OVERALL has
 * no per-iDevice columns (DEC-0-08). A user with no attempts for an item gets a null
 * rawgrade, clearing any stale grade.
 *
 * @param stdClass $instance
 * @param int[] $userids Users to recalculate; empty array is a no-op.
 */
function exelearning_recalculate_grades_for_users(stdClass $instance, array $userids): void {
    \mod_exelearning\grades\grade_recalculator::recalculate_for_users($instance, $userids);
}

/**
 * Human-readable label for the gradebook column of an iDevice.
 *
 * @param stdClass $instance
 * @param stdClass $detected
 * @return string
 */
function exelearning_grade_item_name(stdClass $instance, stdClass $detected): string {
    return \mod_exelearning\grades\grade_item_manager::format_name($instance, $detected);
}

/**
 * Relaxes core's "completion grade item has no grade field" validation error for a
 * registered gradable item (B7, DEC-34-01).
 *
 * Core's moodleform_mod::validation() rejects every completiongradeitemnumber with a
 * badcompletiongradeitemnumber error (key 'completionpassgrade') because
 * mod_exelearning maps 101 itemnumbers (gradeitems::MAX_ITEMNUMBER) but stores each
 * grade in its own table instead of exposing per-itemnumber grade_ideviceN form
 * fields — so core's "this item has no grade field" check always fails, making the
 * DEC-25-01 completion-by-grade feature impossible to save from the form. This
 * stopgap clears that specific error when "require passing grade" is OFF and the
 * chosen item is a real gradebook column (a per-iDevice item in PERITEM, or the
 * overall in OVERALL): it does carry a grade, just not via a core form field.
 * Because it only fires when completionpassgrade is unchecked, it never masks the
 * legitimate "grade to pass not set" validation. "Require passing grade" needs a
 * core_grades fieldname_mapping to validate the threshold and is left to that proper
 * fix (deferred). Kept as a pure function so it is unit-testable without building the
 * whole moodleform_mod (which couples to core availability/tags/completion fields).
 *
 * @param array $errors The errors array from moodleform_mod::validation().
 * @param array $data The submitted form data.
 * @param int $exelearningid The instance id (0 on a brand-new activity).
 * @return array The (possibly relaxed) errors array.
 */
function exelearning_relax_completion_grade_errors(array $errors, array $data, int $exelearningid): array {
    return \mod_exelearning\grades\completion_validator::relax_errors($errors, $data, $exelearningid);
}

/**
 * Places every grade item of the activity under the configured grade category.
 *
 * The grade category selector (DEC-19-01) is stored on exelearning.gradecat, but
 * grade_update() silently ignores the categoryid key (it is not in its allowed
 * field list), so the parent category must be set with grade_item::set_parent() —
 * the same API course/modlib.php uses for core's "Grade category" dropdown.
 * Applied to the overall and every per-iDevice item so a re-upload that adds
 * columns inherits the category too. gradecat=0 leaves the items where Moodle put
 * them (the course top category). A target category that no longer exists (e.g. a
 * cross-course restore) makes set_parent() a no-op, so items stay valid.
 *
 * @param stdClass $instance The exelearning instance (must carry id, course, gradecat).
 * @return void
 */
function exelearning_apply_grade_category(stdClass $instance): void {
    \mod_exelearning\grades\grade_item_manager::apply_category($instance);
}

// Note: exelearning_exclude_overall_grade() (DEC-19-02) was removed in DEC-25-01.
// It excluded the hidden overall (itemnumber=0) from aggregation so it would not
// blank the student's total. PERITEM no longer creates an overall item at all, so
// there is nothing to exclude and the root cause (a hidden item that aggregates)
// is gone.

// -----------------------------------------------------------------------------
// Embedded eXeLearning editor support (ported from mod_exeweb).
//
// These functions are the hooks consumed by the embedded editor
// (editor/index.php, editor/save.php and the "Edit" button in view.php).

/**
 * Returns the URL of the ELPX stored in the 'package' filearea of an instance.
 *
 * Ported from mod_exeweb::exeweb_get_package_url() and adapted to this plugin:
 * filearea 'package', component 'mod_exelearning'. The itemid is taken from the
 * stored file (editor uploads use itemid = revision; form uploads use itemid = 0)
 * to build a URL servable via exelearning_pluginfile().
 *
 * @param stdClass $exelearning Instance record.
 * @param context $context Module context.
 * @return moodle_url|null URL to the package file, or null if it does not exist.
 */
function exelearning_get_package_url($exelearning, $context) {
    return \mod_exelearning\local\package_manager::get_package_url($exelearning, $context);
}

/**
 * Builds the activity view URL a gradebook grade item should resolve to.
 *
 * The Moodle gradebook links each activity grade item to /mod/exelearning/grade.php
 * passing its itemnumber (same pattern as core mod_h5pactivity). This maps that
 * itemnumber to the owning iDevice's stable objectid so the view can deep-link
 * straight to that iDevice instead of the resource front page (issue #13 #4,
 * DEC-13-02). itemnumber 0 (the overall grade) links to the front page.
 *
 * @param stdClass $exelearning Instance record.
 * @param int $cmid Course module id.
 * @param int $itemnumber Grade item number (0 = overall, > 0 = per-iDevice).
 * @return moodle_url View URL, with an `idevice` parameter when one is known.
 */
function exelearning_grade_item_view_url(stdClass $exelearning, int $cmid, int $itemnumber): moodle_url {
    return \mod_exelearning\local\urls::grade_item_view_url($exelearning, $cmid, $itemnumber);
}

/**
 * Builds the destination of a gradebook "grade analysis" click, by role.
 *
 * The gradebook column header is fixed by Moodle core to view.php and cannot be
 * deep-linked by a plugin; the per-grade "grade analysis" link (which appears because
 * this module ships grade.php) is the only place we can target. Teachers/graders go to
 * the attempts report (the actual attempt behind the grade); students are deep-linked
 * to the specific iDevice in the content (issue #13 #4, DEC-13-06).
 *
 * @param stdClass $exelearning Instance record.
 * @param int $cmid Course module id.
 * @param int $itemnumber Grade item number (0 = overall).
 * @param context $context Module context (for the capability check).
 * @param int $userid Graded user, forwarded to the report so the teacher lands on
 *                    that student's attempts (0 = no user filter).
 * @return moodle_url
 */
function exelearning_grade_analysis_url(
    stdClass $exelearning,
    int $cmid,
    int $itemnumber,
    context $context,
    int $userid = 0
): moodle_url {
    return \mod_exelearning\local\urls::grade_analysis_url($exelearning, $cmid, $itemnumber, $context, $userid);
}

/**
 * Returns the absolute path to the index.html of the bundled embedded editor.
 *
 * Wrapper for embedded_editor_source_resolver::get_index_source(). The editor is
 * a release artifact shipped under dist/static/ (DEC-106-01); when it is absent or
 * invalid this returns null and embedded editing is unavailable.
 *
 * @return string|null Path to index.html, or null when no editor is available.
 */
function exelearning_get_embedded_editor_index_source(): ?string {
    return \mod_exelearning\local\embedded_editor_source_resolver::get_index_source();
}

/**
 * Returns whether embedded editing is available on this site.
 *
 * Two conditions must hold (DEC-106-01, DEC-108-01): the bundled editor passes
 * validation, and the administrator has not switched embedded editing off via
 * the site-wide `editordisabled` setting. The toggle is deliberately negative
 * (like `stylesblockimport`) so the unset state and the unticked checkbox both
 * mean "editing on" — a positive default would render unticked until
 * upgradesettings materialises it, contradicting the real behaviour.
 *
 * Used by view.php to decide whether to show the "Edit with eXeLearning" button
 * and by editor/static.php before serving editor assets. Activities keep
 * accepting `.elpx` uploads either way — the toggle only affects in-place
 * editing.
 *
 * @return bool True when the editor is bundled, valid and not disabled.
 */
function exelearning_embedded_editor_enabled(): bool {
    if (!empty(get_config('exelearning', 'editordisabled'))) {
        return false;
    }
    return \mod_exelearning\local\embedded_editor_source_resolver::is_available();
}

/**
 * Aborts the request when embedded editing is disabled site-wide (DEC-108-01).
 *
 * Guard for the editor endpoints (editor/index.php bootstrap, editor/save.php):
 * hiding the button is not enough, a direct request must be refused too.
 *
 * @return void
 * @throws moodle_exception editordisabledbyadmin when the toggle is off or no
 *                          valid bundle is available.
 */
function exelearning_require_embedded_editor_enabled(): void {
    if (!exelearning_embedded_editor_enabled()) {
        throw new moodle_exception('editordisabledbyadmin', 'mod_exelearning');
    }
}

/**
 * Absolute path to the bundled editor static directory, used by
 * editor/static.php to serve the editor's assets.
 *
 * @return string|null Directory path, or null when no editor is available.
 */
function exelearning_get_embedded_editor_local_static_dir(): ?string {
    return \mod_exelearning\local\embedded_editor_source_resolver::get_editor_dir();
}
