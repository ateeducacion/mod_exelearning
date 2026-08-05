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
 * Builds the target eXeLearning activity for a migration (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\target;

use mod_exelearning\local\attempts;

/**
 * Creates an empty eXeLearning activity from a source row, preserving the source
 * course module's metadata (visibility, groups, availability, completion, intro).
 *
 * Mirrors the add_moduleinfo() flow the activity form uses. The package is left empty;
 * the caller imports the content afterwards. The source idnumber is deliberately not
 * copied: the source survives in the same course, so copying it would create a
 * course-wide duplicate (DEC-13-12).
 */
final class activity_builder {
    /**
     * Creates the target activity from a source row.
     *
     * @param \stdClass $source A row from source_interface::list_sources().
     * @param int $grademodel EXELEARNING_GRADEMODEL_OVERALL or _PERITEM.
     * @return \stdClass {cm: cm row, instance: exelearning row, contextid: int}.
     */
    public static function create_from_source(\stdClass $source, int $grademodel): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        $course = $DB->get_record('course', ['id' => (int) $source->course], '*', MUST_EXIST);
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'exelearning'], MUST_EXIST);

        $moduleinfo = (object) [
            'modulename'          => 'exelearning',
            'module'              => $moduleid,
            'course'              => (int) $source->course,
            'section'             => (int) $source->sectionnum,
            'visible'             => (int) $source->cmvisible,
            'visibleoncoursepage' => (int) $source->cmvisibleoncoursepage,
            'name'                => (string) $source->name,
            'intro'               => (string) ($source->intro ?? ''),
            'introformat'         => (int) ($source->introformat ?? FORMAT_HTML),
            'lang'                => (string) ($source->cmlang ?? ''),
            'grademodel'          => $grademodel,
            'grademax'            => 100,
            'grademin'            => 0,
            'gradepass'           => 0,
            'grademethod'         => attempts::GRADE_HIGHEST,
            'gradedisplaytype'    => 0,
            // The idnumber is never copied: the source keeps it in the same course.
            'cmidnumber'          => '',
            'groupmode'           => (int) $source->cmgroupmode,
            'groupingid'          => (int) $source->cmgroupingid,
        ];

        // The add_moduleinfo() call honours the plain 'availability' property when no
        // availabilityconditionsjson is present. Same-course copy keeps the JSON valid.
        if (!empty($CFG->enableavailability) && !empty($source->cmavailability)) {
            $moduleinfo->availability = $source->cmavailability;
        }

        // Completion is gated exactly like the activity form: pass the fields only when
        // completion is enabled, otherwise add_moduleinfo() raises a debugging notice.
        $completion = new \completion_info($course);
        if ($completion->is_enabled() && (int) $source->cmcompletion > COMPLETION_TRACKING_NONE) {
            $moduleinfo->completion = (int) $source->cmcompletion;
            $moduleinfo->completionview = (int) $source->cmcompletionview;
            $moduleinfo->completionexpected = (int) $source->cmcompletionexpected;
            $moduleinfo->completionpassgrade = (int) $source->cmcompletionpassgrade;
            // Per-item completion numbers do not map across plugins; only the overall
            // item (0) survives, and only when the target aggregates into itemnumber 0.
            $sourceitemnumber = (int) ($source->cmcompletiongradeitemnumber ?? -1);
            if ($sourceitemnumber === 0 && $grademodel === EXELEARNING_GRADEMODEL_OVERALL) {
                $moduleinfo->completiongradeitemnumber = 0;
            }
            $moduleinfo->completionunlocked = 1;
        }

        $created = add_moduleinfo($moduleinfo, $course);
        $cm = get_coursemodule_from_id('exelearning', $created->coursemodule, 0, false, MUST_EXIST);
        $instance = $DB->get_record('exelearning', ['id' => $created->instance], '*', MUST_EXIST);
        return (object) [
            'cm'        => $cm,
            'instance'  => $instance,
            'contextid' => (int) \context_module::instance($cm->id)->id,
        ];
    }
}
