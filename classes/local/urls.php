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
 * URL builders for mod_exelearning gradebook deep-links and navigation.
 *
 * Extracted verbatim from lib.php (DEC-71-01) so the URL/routing logic is
 * unit-testable in isolation; lib.php keeps thin delegators with the same
 * function signatures Moodle and the plugin's own callers use.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

use context;
use moodle_url;
use navigation_node;
use stdClass;

/**
 * Builds the deep-link and navigation URLs used by the gradebook integration.
 */
final class urls {
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
    public static function grade_item_view_url(stdClass $exelearning, int $cmid, int $itemnumber): moodle_url {
        global $DB;

        $params = ['id' => $cmid];
        if ($itemnumber > 0) {
            $objectid = $DB->get_field('exelearning_grade_item', 'objectid', [
                'exelearningid' => $exelearning->id,
                'itemnumber'    => $itemnumber,
                'deleted'       => 0,
            ]);
            if (!empty($objectid)) {
                $params['idevice'] = $objectid;
            }
        }
        return new moodle_url('/mod/exelearning/view.php', $params);
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
    public static function grade_analysis_url(
        stdClass $exelearning,
        int $cmid,
        int $itemnumber,
        context $context,
        int $userid = 0
    ): moodle_url {
        if (has_capability('mod/exelearning:viewreport', $context)) {
            $params = ['id' => $cmid];
            if ($userid > 0) {
                $params['userid'] = $userid;
            }
            return new moodle_url('/mod/exelearning/report.php', $params);
        }
        return self::grade_item_view_url($exelearning, $cmid, $itemnumber);
    }

    /**
     * Returns the key of the first "administrative" node in the module navigation
     * (roles/permissions/filters) so that "Reports" is inserted just before it,
     * replicating mod_scorm's ordering.
     *
     * @param navigation_node $node
     * @return string|null
     */
    public static function navigation_before_key(navigation_node $node): ?string {
        foreach (['roleassign', 'roles', 'permissions', 'filtermanagement'] as $key) {
            if ($node->get($key)) {
                return $key;
            }
        }
        return null;
    }
}
