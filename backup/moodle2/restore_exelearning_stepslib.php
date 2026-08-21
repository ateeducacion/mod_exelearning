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
 * Define all the restore steps that will be used by the restore_exelearning_activity_task.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one exelearning activity.
 */
class restore_exelearning_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the structure to be restored.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('exelearning', '/activity/exelearning');
        $paths[] = new restore_path_element(
            'exelearning_gradeitem',
            '/activity/exelearning/gradeitems/gradeitem'
        );

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'exelearning_attempt',
                '/activity/exelearning/attempts/attempt'
            );
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Processes the exelearning element data.
     *
     * @param array|object $data
     */
    protected function process_exelearning($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Remap the last-modifying user (annotated in the backup) so usermodified
        // does not point at a stale/non-existent user id after a cross-site restore.
        $data->usermodified = $this->get_mappingid('user', $data->usermodified) ?: 0;

        // The gradecat column (DEC-19-01) is a grade_categories.id that is
        // course-specific: it survives a same-course duplicate but not a
        // cross-course restore (where the category is recreated later with a
        // different id). Keep it only when the
        // category exists in the target course; otherwise fall back to the course
        // top category (0). The per-iDevice items are re-parented on first view via
        // exelearning_apply_grade_category() (B4, DEC-34-01).
        if (
            !empty($data->gradecat)
            && !$DB->record_exists('grade_categories', ['id' => $data->gradecat, 'courseid' => $data->course])
        ) {
            $data->gradecat = 0;
        }

        // Insert the exelearning record.
        $newitemid = $DB->insert_record('exelearning', $data);

        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);

        // Keep the mapping so child elements can resolve their parent.
        $this->set_mapping('exelearning', $oldid, $newitemid);
    }

    /**
     * Processes one exelearning_grade_item element.
     *
     * @param array|object $data
     */
    protected function process_exelearning_gradeitem($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->exelearningid = $this->get_new_parentid('exelearning');

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('exelearning_grade_item', $data);
        $this->set_mapping('exelearning_gradeitem', $oldid, $newitemid);
    }

    /**
     * Processes one exelearning_attempt element.
     *
     * @param array|object $data
     */
    protected function process_exelearning_attempt($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->exelearningid = $this->get_new_parentid('exelearning');

        // Map the attempt owner. If the user cannot be mapped (excluded or
        // anonymised in this restore), skip the row: inserting it would cast
        // false to userid 0 and silently attribute the attempt to nobody.
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // A backup taken before DEC-124-03 carries no 'gradable' element. Default it to
        // gradable, for the same reason the upgrade step defaults existing rows to 1:
        // those rows were written by an ingest() that made no such distinction, and
        // assuming gradable preserves grades the source site had published. Set it
        // explicitly rather than leaning on the column default, so the intent is
        // readable here and survives any future change to install.xml.
        $data->gradable = isset($data->gradable) ? (int) $data->gradable : 1;

        $newitemid = $DB->insert_record('exelearning_attempt', $data);
        $this->set_mapping('exelearning_attempt', $oldid, $newitemid);
    }

    /**
     * Once the database tables have been fully restored, restore the related files.
     */
    protected function after_execute() {
        // Add exelearning related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_exelearning', 'intro', null);
        $this->add_related_files('mod_exelearning', 'package', null);
        $this->add_related_files('mod_exelearning', 'content', null);
    }
}
