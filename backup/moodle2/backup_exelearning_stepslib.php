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
 * Define the complete exelearning structure for backup, with file and id annotations.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete exelearning structure for backup, with file and id annotations.
 */
class backup_exelearning_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the backup structure of the module.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define the root element describing the exelearning instance.
        // grademodel/maxattempt/reviewmode/teachermodevisible/gradepass/
        // gradeenabled/gradecat are all NOT NULL instance settings; omitting them
        // made restore silently revert grading model, attempt limits, review
        // policy, pass grade, the master grading switch (DEC-13-07) and the grade
        // category (DEC-19-01) to the install.xml defaults — so a deliberately
        // ungraded activity (gradeenabled=0) came back graded and re-created its
        // gradebook columns on first view (B4, DEC-34-01). gradesyncrev is
        // deliberately NOT backed up so the restored copy re-scans its package
        // once on first view.
        // completionstatusrequired is the custom completion rule's stored config
        // (DEC-69-01); it must round-trip so a restored copy keeps the rule.
        $exelearning = new backup_nested_element('exelearning', ['id'], [
            'course', 'name', 'intro', 'introformat',
            'entrypath', 'entryname', 'revision', 'gradeenabled', 'grademax',
            'grademin', 'gradepass', 'gradedisplaytype', 'grademethod', 'grademodel',
            'gradecat', 'maxattempt', 'reviewmode', 'teachermodevisible',
            'completionstatusrequired',
            'timecreated', 'timemodified', 'usermodified',
        ]);

        // Define each element separated.
        $gradeitems = new backup_nested_element('gradeitems');

        // The contenthash must round-trip: it backs the stale-grades warning
        // (DEC-12-01) when an iDevice is edited in place.
        $gradeitem = new backup_nested_element('gradeitem', ['id'], [
            'itemnumber', 'objectid', 'pageid', 'idevicetype', 'name',
            'grademax', 'grademin', 'contenthash', 'deleted', 'timecreated', 'timemodified',
        ]);

        $attempts = new backup_nested_element('attempts');

        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attempt', 'itemnumber', 'rawscore', 'maxscore',
            'scaledscore', 'status', 'sessiontoken', 'timecreated', 'timemodified',
        ]);

        // Build the tree.
        $exelearning->add_child($gradeitems);
        $gradeitems->add_child($gradeitem);

        $exelearning->add_child($attempts);
        $attempts->add_child($attempt);

        // Define sources.
        $exelearning->set_source_table('exelearning', ['id' => backup::VAR_ACTIVITYID]);

        // The grade items are package metadata (not user data), so they are
        // always backed up regardless of userinfo.
        $gradeitem->set_source_table(
            'exelearning_grade_item',
            ['exelearningid' => backup::VAR_PARENTID]
        );

        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            $attempt->set_source_table(
                'exelearning_attempt',
                ['exelearningid' => backup::VAR_PARENTID]
            );
        }

        // Define id annotations.
        $exelearning->annotate_ids('user', 'usermodified');
        $attempt->annotate_ids('user', 'userid');

        // Define file annotations.
        $exelearning->annotate_files('mod_exelearning', 'intro', null);
        $exelearning->annotate_files('mod_exelearning', 'package', null);
        $exelearning->annotate_files('mod_exelearning', 'content', null);

        // Return the root element (exelearning), wrapped into standard activity structure.
        return $this->prepare_activity_structure($exelearning);
    }
}
