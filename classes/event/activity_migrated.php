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
 * The mod_exelearning activity migrated event.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\event;

/**
 * Fired when one sibling activity is successfully migrated into eXeLearning (DEC-13-12).
 *
 * Logged at the created module's context, with the new exelearning instance as the
 * object and the source component/cmid in `other`. Logged at LEVEL_OTHER (a one-off
 * administrative copy, not a teaching action).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_migrated extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'exelearning';
    }

    /**
     * Returns the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventactivitymigrated', 'mod_exelearning');
    }

    /**
     * Returns a human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' migrated the source activity with course module id " .
            "'{$this->other['sourcecmid']}' ('{$this->other['sourcecomponent']}') into the eXeLearning " .
            "activity with course module id '{$this->contextinstanceid}'.";
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
        foreach (['sourcecomponent', 'sourcecmid'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
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

    /**
     * The source cmid in `other` is site-specific and not restorable.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
