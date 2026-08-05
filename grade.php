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
 * Gradebook "grade analysis" redirect for mod_exelearning.
 *
 * The Moodle gradebook links the per-grade "grade analysis" entry to this script,
 * passing the clicked item's `itemnumber` (and `userid`). Shipping this file is also
 * what makes that link appear (same as core mod_scorm / mod_h5pactivity). The
 * destination is role-based (issue #13 #4, DEC-13-02/DEC-13-06): teachers/graders get
 * the attempts report (the actual attempt behind the grade); students are deep-linked
 * to the specific iDevice in the content. The gradebook column header itself is fixed
 * by Moodle core to view.php and cannot be deep-linked by a plugin.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$itemnumber = optional_param('itemnumber', 0, PARAM_INT); // Grade item number (0 = overall grade).
$userid = optional_param('userid', 0, PARAM_INT); // Graded user (gradebook forwards this for "grade analysis").

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Role-based destination: teacher -> that user's attempts in the report; student
// -> the iDevice content.
redirect(exelearning_grade_analysis_url($exelearning, $cm->id, $itemnumber, $context, $userid));
