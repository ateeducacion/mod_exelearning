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

namespace mod_exelearning\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use context_module;
use core_user;

/**
 * External function: list a user's attempts on a mod_exelearning activity.
 *
 * Returns one entry per attempt (the overall itemnumber=0 row), with the overall
 * score percentage and status. A user may read their own attempts; reading another
 * user's attempts requires mod/exelearning:viewreport (mirrors mod_scorm).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_attempts extends external_api {
    /**
     * Parameters for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'exelearningid' => new external_value(PARAM_INT, 'exelearning instance id'),
            'userid'        => new external_value(PARAM_INT, 'User id (0 = current user)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the user's attempts.
     *
     * @param int $exelearningid The exelearning instance id.
     * @param int $userid The user id (0 = current user).
     * @return array attempts + grademethod + maxattempt + warnings.
     */
    public static function execute(int $exelearningid, int $userid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'exelearningid' => $exelearningid,
            'userid'        => $userid,
        ]);

        $exelearning = $DB->get_record('exelearning', ['id' => $params['exelearningid']], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($exelearning, 'exelearning');

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/exelearning:view', $context);

        $targetuserid = $params['userid'] ?: $USER->id;
        $user = core_user::get_user($targetuserid, '*', MUST_EXIST);
        core_user::require_active_user($user);
        if ($user->id != $USER->id) {
            // Only staff may read another user's attempts.
            require_capability('mod/exelearning:viewreport', $context);
        }

        $rows = $DB->get_records(
            'exelearning_attempt',
            ['exelearningid' => $exelearning->id, 'userid' => $user->id, 'itemnumber' => 0],
            'attempt ASC'
        );
        $attempts = [];
        foreach ($rows as $row) {
            $attempts[] = [
                'attempt'      => (int) $row->attempt,
                'status'       => (string) $row->status,
                'scorepercent' => round((float) $row->scaledscore * 100, 5),
                'gradable'     => (int) $row->gradable,
                'timecreated'  => (int) $row->timecreated,
                'timemodified' => (int) $row->timemodified,
            ];
        }

        return [
            'attempts'    => $attempts,
            'grademethod' => (int) ($exelearning->grademethod ?? 0),
            'maxattempt'  => (int) ($exelearning->maxattempt ?? 0),
            // The attempts list is deliberately unfiltered — a client showing a learner
            // their history should show all of it — so a client cannot derive the cap
            // from count($attempts): ungraded-period attempts do not count against
            // maxattempt (DEC-124-03). Return the number the server actually enforces.
            'usedattempts' => \mod_exelearning\local\attempts::count_user_attempts(
                (int) $exelearning->id,
                (int) $user->id
            ),
            'warnings'    => [],
        ];
    }

    /**
     * Return description for execute().
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attempts' => new external_multiple_structure(
                new external_single_structure([
                    'attempt'      => new external_value(PARAM_INT, 'Attempt number'),
                    'status'       => new external_value(PARAM_ALPHA, 'completed|passed|failed|incomplete'),
                    'scorepercent' => new external_value(PARAM_FLOAT, 'Overall score as a 0..100 percentage'),
                    'gradable'     => new external_value(
                        PARAM_INT,
                        'Whether the attempt counts towards the gradebook grade '
                            . '(0 = recorded while the activity was not a graded one)'
                    ),
                    'timecreated'  => new external_value(PARAM_INT, 'When the attempt started'),
                    'timemodified' => new external_value(PARAM_INT, 'When the attempt was last updated'),
                ])
            ),
            'grademethod' => new external_value(PARAM_INT, 'Attempt aggregation method'),
            'maxattempt'  => new external_value(PARAM_INT, 'Max attempts (0 = unlimited)'),
            'usedattempts' => new external_value(
                PARAM_INT,
                'Attempts counted against maxattempt: excludes ungraded-period attempts'
            ),
            'warnings'    => new external_warnings(),
        ]);
    }
}
