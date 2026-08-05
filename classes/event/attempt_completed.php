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
 * The mod_exelearning attempt completed event.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\event;

/**
 * Fired the first time an attempt reaches a terminal status (passed/failed/completed).
 *
 * Emitted by {@see \mod_exelearning\local\track::ingest()} only on the commit that
 * transitions the attempt's overall status into a terminal value, NOT on every
 * tracking commit — the shim autocommits roughly every 500 ms, so a per-commit event
 * would flood the logstore (the reason DEC-26-03 rejected one). Together with
 * {@see attempt_started} this gives a once-per-attempt lifecycle (begin → outcome)
 * without that noise (DEC-68-01, extending DEC-26-03). The server-recomputed overall
 * grade and the terminal status travel in `other` (`score`, `status`). Logged at
 * LEVEL_PARTICIPATING.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_completed extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'exelearning';
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattemptcompleted', 'mod_exelearning');
    }

    /**
     * Returns a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' completed attempt '{$this->other['attempt']}' "
            . "with status '{$this->other['status']}' and score '{$this->other['score']}' in the exelearning "
            . "activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Returns the URL related to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/exelearning/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validates the custom event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['attempt'])) {
            throw new \coding_exception('The \'attempt\' value must be set in other.');
        }
        if (!isset($this->other['status'])) {
            throw new \coding_exception('The \'status\' value must be set in other.');
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    /**
     * Object id mapping for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'exelearning', 'restore' => 'exelearning'];
    }
}
