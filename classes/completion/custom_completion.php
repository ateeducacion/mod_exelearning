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

declare(strict_types=1);

namespace mod_exelearning\completion;

use core_completion\activity_custom_completion;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Loaded for the EXELEARNING_COMPLETIONSTATUS_* constants used by the rule.
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

/**
 * Activity custom completion subclass for mod_exelearning (DEC-69-01).
 *
 * Defines a single module-level completion rule, `completionstatusrequired`: the
 * activity is marked complete when the user has an attempt whose status reaches the
 * required value (passed, completed, or either). One state per module, aligned with
 * Moodle's completion abstraction — DEC-67-01 deliberately rejected per-iDevice
 * completion (multiple states per module).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state (COMPLETION_COMPLETE or COMPLETION_INCOMPLETE).
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        // The only defined rule is completionstatusrequired. The required status is
        // stored as an int: 1=passed, 2=completed, 3=passed or completed (any).
        $required = (int) ($this->cm->customdata['customcompletionrules']['completionstatusrequired'] ?? 0);
        switch ($required) {
            case EXELEARNING_COMPLETIONSTATUS_PASSED:
                $statuses = ['passed'];
                break;
            case EXELEARNING_COMPLETIONSTATUS_COMPLETED:
                $statuses = ['completed'];
                break;
            default:
                // EXELEARNING_COMPLETIONSTATUS_ANY (or an unexpected value): accept
                // either a passed or a completed attempt.
                $statuses = ['passed', 'completed'];
                break;
        }

        [$insql, $params] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'status');
        $params['exelearningid'] = $this->cm->instance;
        $params['userid'] = $this->userid;
        // Only the overall attempt row (itemnumber = 0) carries the real lesson_status.
        // Per-iDevice rows are always recorded as 'completed' by track::apply_one(), so
        // without this filter any scored iDevice would complete the activity even when
        // the overall attempt is failed or incomplete.
        $exists = $DB->record_exists_select(
            'exelearning_attempt',
            "exelearningid = :exelearningid AND userid = :userid AND itemnumber = 0 AND status $insql",
            $params
        );

        return $exists ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionstatusrequired',
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        $required = (int) ($this->cm->customdata['customcompletionrules']['completionstatusrequired'] ?? 0);
        switch ($required) {
            case EXELEARNING_COMPLETIONSTATUS_PASSED:
                $statusname = get_string('completionstatus_passed', 'mod_exelearning');
                break;
            case EXELEARNING_COMPLETIONSTATUS_COMPLETED:
                $statusname = get_string('completionstatus_completed', 'mod_exelearning');
                break;
            default:
                $statusname = get_string('completionstatus_any', 'mod_exelearning');
                break;
        }

        return [
            'completionstatusrequired' => get_string('completiondetail:status', 'mod_exelearning', $statusname),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionstatusrequired',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
