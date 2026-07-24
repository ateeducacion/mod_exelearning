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
 * Web service definitions for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Mobile / external client services. Available to the official Moodle App and
    // any external client; each enforces context, login and capabilities in code.
    'mod_exelearning_get_exelearnings_by_courses' => [
        'classname'    => 'mod_exelearning\external\get_exelearnings_by_courses',
        'methodname'   => 'execute',
        'description'  => 'Returns a list of mod_exelearning instances in the given courses '
            . '(or in the user\'s courses when none are given).',
        'type'         => 'read',
        'capabilities' => 'mod/exelearning:view',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_exelearning_view_exelearning' => [
        'classname'    => 'mod_exelearning\external\view_exelearning',
        'methodname'   => 'execute',
        'description'  => 'Trigger the course module viewed event and update completion.',
        'type'         => 'write',
        'capabilities' => 'mod/exelearning:view',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_exelearning_get_exelearning_access_information' => [
        'classname'    => 'mod_exelearning\external\get_exelearning_access_information',
        'methodname'   => 'execute',
        'description'  => 'Return the user\'s capability flags on a mod_exelearning activity.',
        'type'         => 'read',
        'capabilities' => '',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_exelearning_get_user_attempts' => [
        'classname'    => 'mod_exelearning\external\get_user_attempts',
        'methodname'   => 'execute',
        'description'  => 'Return a user\'s attempts on a mod_exelearning activity.',
        'type'         => 'read',
        'capabilities' => 'mod/exelearning:view',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_exelearning_get_user_grades' => [
        'classname'    => 'mod_exelearning\external\get_user_grades',
        'methodname'   => 'execute',
        'description'  => 'Return a user\'s per-item grades on a mod_exelearning activity.',
        'type'         => 'read',
        'capabilities' => 'mod/exelearning:view',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_exelearning_save_track' => [
        'classname'    => 'mod_exelearning\external\save_track',
        'methodname'   => 'execute',
        'description'  => 'Save a tracking submission (per-iDevice scores) for the current user.',
        'type'         => 'write',
        'capabilities' => 'mod/exelearning:savetrack',
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
