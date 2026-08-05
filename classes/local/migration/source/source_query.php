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
 * Shared site-wide source enumeration SQL for migration handlers (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

/**
 * Builds the list_sources() query shared by every sibling handler.
 *
 * The query joins the cm metadata (so activity_builder can preserve it), the module
 * context (avoiding one context_module::instance() per row in preflight) and the
 * migration map (so preflight can count "already migrated" without N queries).
 */
final class source_query {
    /**
     * Builds the enumeration SQL for a sibling module.
     *
     * Expects named params :moduleid, :ctxlevel (CONTEXT_MODULE) and :component
     * (e.g. 'mod_exeweb'). The sibling table is the same as the module name.
     *
     * @param string $module Bare module name and table, e.g. 'exeweb' or 'exescorm'.
     * @param string $extracols Extra SELECT columns prefixed with 'a.' (e.g. 'a.revision').
     * @return string
     */
    public static function build(string $module, string $extracols): string {
        $extra = $extracols === '' ? '' : ', ' . $extracols;
        return "SELECT cm.id AS cmid, cm.course, c.fullname AS coursename,
                       cs.section AS sectionnum, a.id AS instanceid, a.name,
                       a.intro, a.introformat, ctx.id AS contextid,
                       cm.visible AS cmvisible, cm.visibleoncoursepage AS cmvisibleoncoursepage,
                       cm.groupmode AS cmgroupmode, cm.groupingid AS cmgroupingid,
                       cm.availability AS cmavailability, cm.lang AS cmlang,
                       cm.completion AS cmcompletion, cm.completionview AS cmcompletionview,
                       cm.completionexpected AS cmcompletionexpected,
                       cm.completiongradeitemnumber AS cmcompletiongradeitemnumber,
                       cm.completionpassgrade AS cmcompletionpassgrade,
                       mig.id AS migrationid{$extra}
                  FROM {" . $module . "} a
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = :moduleid
                                           AND cm.deletioninprogress = 0
                  JOIN {course_sections} cs ON cs.id = cm.section
                  JOIN {course} c ON c.id = cm.course
                  JOIN {context} ctx ON ctx.contextlevel = :ctxlevel AND ctx.instanceid = cm.id
             LEFT JOIN {exelearning_migration} mig ON mig.sourcecomponent = :component
                                                  AND mig.sourcecmid = cm.id
              ORDER BY c.fullname, a.name";
    }
}
