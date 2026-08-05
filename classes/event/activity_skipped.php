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
 * The mod_exelearning activity skipped event.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\event;

/**
 * Fired when a source activity is skipped during migration (DEC-13-12).
 *
 * Covers the blocked statuses (no importable source, ambiguous embedded sources, or an
 * externally hosted package). Logged at the source course context with the source
 * component/cmid and the skip reason in `other`. Already-migrated sources do NOT fire
 * this event, to avoid flooding the log on re-runs.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_skipped extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventactivityskipped', 'mod_exelearning');
    }

    /**
     * Returns a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' skipped the source activity with course module id " .
            "'{$this->other['sourcecmid']}' ('{$this->other['sourcecomponent']}') during migration " .
            "(reason: '{$this->other['reason']}').";
    }

    /**
     * Returns the URL related to the event.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/exelearning/admin/migrate.php');
    }

    /**
     * Validates the custom event data.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['sourcecomponent', 'sourcecmid', 'reason'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }
}
