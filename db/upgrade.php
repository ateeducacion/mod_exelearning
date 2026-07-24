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
 * mod_exelearning database upgrades.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Performs the mod_exelearning database schema upgrades.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_exelearning_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Stage 1 (2026052800): add multi-grade-items mapping table + grademin/grademax on instance.
    if ($oldversion < 2026052800) {
        $instance = new xmldb_table('exelearning');
        $grademax = new xmldb_field(
            'grademax',
            XMLDB_TYPE_NUMBER,
            '10,5',
            null,
            XMLDB_NOTNULL,
            null,
            '100',
            'revision'
        );
        if (!$dbman->field_exists($instance, $grademax)) {
            $dbman->add_field($instance, $grademax);
        }
        $grademin = new xmldb_field(
            'grademin',
            XMLDB_TYPE_NUMBER,
            '10,5',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'grademax'
        );
        if (!$dbman->field_exists($instance, $grademin)) {
            $dbman->add_field($instance, $grademin);
        }

        $table = new xmldb_table('exelearning_grade_item');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('exelearningid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectid', XMLDB_TYPE_CHAR, '191', null, XMLDB_NOTNULL, null, null);
            $table->add_field('pageid', XMLDB_TYPE_CHAR, '191', null, null, null, null);
            $table->add_field('idevicetype', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('grademax', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '100');
            $table->add_field('grademin', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('deleted', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('exelearningid_fk', XMLDB_KEY_FOREIGN, ['exelearningid'], 'exelearning', ['id']);
            $table->add_index('exelearningid_itemnumber', XMLDB_INDEX_UNIQUE, ['exelearningid', 'itemnumber']);
            $table->add_index('exelearningid_objectid', XMLDB_INDEX_UNIQUE, ['exelearningid', 'objectid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026052800, 'exelearning');
    }

    // Stage 2 (2026052801): add gradedisplaytype column to exelearning.
    if ($oldversion < 2026052801) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'gradedisplaytype',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'grademin'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026052801, 'exelearning');
    }

    // Stage 3 (2026052802): attempts — exelearning_attempt table +
    // grademethod field (attempt aggregation) on the instance.
    if ($oldversion < 2026052802) {
        $instance = new xmldb_table('exelearning');
        $grademethod = new xmldb_field(
            'grademethod',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'gradedisplaytype'
        );
        if (!$dbman->field_exists($instance, $grademethod)) {
            $dbman->add_field($instance, $grademethod);
        }

        $table = new xmldb_table('exelearning_attempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('exelearningid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('attempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('itemnumber', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('rawscore', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('maxscore', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '100');
            $table->add_field('scaledscore', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'completed');
            $table->add_field('sessiontoken', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('exelearningid_fk', XMLDB_KEY_FOREIGN, ['exelearningid'], 'exelearning', ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index(
                'exelearningid_userid_attempt_item',
                XMLDB_INDEX_UNIQUE,
                ['exelearningid', 'userid', 'attempt', 'itemnumber']
            );
            $table->add_index('exelearningid_userid', XMLDB_INDEX_NOTUNIQUE, ['exelearningid', 'userid']);

            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026052802, 'exelearning');
    }

    // Stage 4 (2026052803): grademodel + maxattempt/reviewmode on the instance.
    if ($oldversion < 2026052803) {
        $instance = new xmldb_table('exelearning');

        $grademodel = new xmldb_field(
            'grademodel',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '2',
            'grademethod'
        );
        if (!$dbman->field_exists($instance, $grademodel)) {
            $dbman->add_field($instance, $grademodel);
        }
        $maxattempt = new xmldb_field(
            'maxattempt',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'grademodel'
        );
        if (!$dbman->field_exists($instance, $maxattempt)) {
            $dbman->add_field($instance, $maxattempt);
        }
        $reviewmode = new xmldb_field(
            'reviewmode',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'maxattempt'
        );
        if (!$dbman->field_exists($instance, $reviewmode)) {
            $dbman->add_field($instance, $reviewmode);
        }

        upgrade_mod_savepoint(true, 2026052803, 'exelearning');
    }

    // Stage 5 (2026052804): ensure the gradepass field exists. It is
    // already in install.xml for fresh installs; this savepoint covers sites that
    // upgraded through 2026052802/03 before gradepass was added to that phase.
    if ($oldversion < 2026052804) {
        $instance = new xmldb_table('exelearning');
        $gradepass = new xmldb_field(
            'gradepass',
            XMLDB_TYPE_NUMBER,
            '10,5',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'grademin'
        );
        if (!$dbman->field_exists($instance, $gradepass)) {
            $dbman->add_field($instance, $gradepass);
        }
        upgrade_mod_savepoint(true, 2026052804, 'exelearning');
    }

    // Stage 6 (2026052806): teachermodevisible (mod_exeweb parity) — per-activity
    // toggle to hide the teacher preview/grading switch in the activity view.
    if ($oldversion < 2026052806) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'teachermodevisible',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'reviewmode'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026052806, 'exelearning');
    }

    // Stage 7 (2026052900): the "both" gradebook columns model
    // (grademodel=2) was removed. Collapse existing rows to per-iDevice (1),
    // which preserves the per-iDevice columns teachers were already seeing under
    // "both", and lower the field default from 2 to 1.
    if ($oldversion < 2026052900) {
        $instance = new xmldb_table('exelearning');

        // Migrate stored data: 2 (both) → 1 (per-iDevice).
        $DB->set_field('exelearning', 'grademodel', 1, ['grademodel' => 2]);

        // Lower the column default to match install.xml.
        $grademodel = new xmldb_field(
            'grademodel',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'grademethod'
        );
        if ($dbman->field_exists($instance, $grademodel)) {
            $dbman->change_field_default($instance, $grademodel);
        }

        upgrade_mod_savepoint(true, 2026052900, 'exelearning');
    }

    // Stage 8 (2026052901): teachermodevisible changes meaning and defaults to 0.
    // It used to gate the Moodle "Try as a student" preview banner; it now controls
    // whether the eXeLearning teacher-layer selector is shown in the embedded package
    // (default off = no selector, teacher content hidden). When on, the plugin appends
    // the package's own ?exe-teacher=1 URL parameter (set in view.php) so the selector
    // is available to every viewer; the plugin no longer injects CSS into the package.
    // Lower the default 1 -> 0 and reset existing rows to the new default.
    if ($oldversion < 2026052901) {
        $instance = new xmldb_table('exelearning');

        $DB->set_field('exelearning', 'teachermodevisible', 0);

        $field = new xmldb_field(
            'teachermodevisible',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'reviewmode'
        );
        if ($dbman->field_exists($instance, $field)) {
            $dbman->change_field_default($instance, $field);
        }

        upgrade_mod_savepoint(true, 2026052901, 'exelearning');
    }

    // Stage 9 (2026060100): gradesyncrev marker. Records the highest package
    // revision already scanned for gradable iDevices so the view.php self-heal
    // stops re-extracting + re-parsing the whole ELPX on EVERY view for
    // content-only packages (which permanently have 0 gradable iDevices).
    if ($oldversion < 2026060100) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'gradesyncrev',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'usermodified'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026060100, 'exelearning');
    }

    // Stage 10 (2026060102): per-iDevice contenthash on exelearning_grade_item.
    // Stores a sha1 of each iDevice's content block in content.xml so a re-sync
    // can detect an in-place options edit (same objectid, changed scoring) and
    // warn the teacher that existing grades/attempts are now stale.
    if ($oldversion < 2026060102) {
        $gradeitem = new xmldb_table('exelearning_grade_item');
        $field = new xmldb_field(
            'contenthash',
            XMLDB_TYPE_CHAR,
            '40',
            null,
            null,
            null,
            null,
            'deleted'
        );
        if (!$dbman->field_exists($gradeitem, $field)) {
            $dbman->add_field($gradeitem, $field);
        }
        upgrade_mod_savepoint(true, 2026060102, 'exelearning');
    }

    // Stage 11 (2026060400): per-activity "graded" master switch. When
    // off, the activity creates no grade items / reports and behaves like a plain
    // resource. Default 1 preserves the current (always-graded) behaviour.
    if ($oldversion < 2026060400) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'gradeenabled',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'grademodel'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026060400, 'exelearning');
    }

    // Stage 12 (2026060401): grade category column + back-fill the
    // per-iDevice visibility fix.
    if ($oldversion < 2026060401) {
        global $CFG;

        // 1) Grade category selector storage. All this activity's grade items are
        // placed under this category via grade_item::set_parent(); grade_update()
        // ignores categoryid. 0 = leave at the course top category.
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'gradecat',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'gradesyncrev'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }

        // 2) Data back-fill: existing per-iDevice activities (grademodel=1)
        // keep a hidden overall grade item (itemnumber=0) for completionpassgrade.
        // Because a hidden item that still aggregates makes Moodle blank
        // the student total (grade_report_user_showtotalsifcontainhidden defaults to
        // GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN), exclude those overall grades
        // from aggregation. set_excluded() leaves finalgrade/gradepass intact, so
        // completion is unaffected; get_hiding_affected() then skips the item and the
        // student total is shown again.
        require_once($CFG->libdir . '/gradelib.php');
        // EXELEARNING_GRADEMODEL_PERITEM = 1 (literal here to keep upgrade.php
        // independent of lib.php constants).
        $periteminstances = $DB->get_records('exelearning', ['grademodel' => 1], '', 'id, course');
        foreach ($periteminstances as $inst) {
            $overall = \grade_item::fetch([
                'itemtype'     => 'mod',
                'itemmodule'   => 'exelearning',
                'iteminstance' => $inst->id,
                'itemnumber'   => 0,
                'courseid'     => $inst->course,
            ]);
            if (!$overall) {
                continue;
            }
            $grades = \grade_grade::fetch_all(['itemid' => $overall->id]);
            if (!$grades) {
                continue;
            }
            foreach ($grades as $grade) {
                if (!$grade->is_excluded()) {
                    $grade->set_excluded(true);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026060401, 'exelearning');
    }

    // Stage 14 (2026060800): drop the hidden overall grade item in per-iDevice
    // mode (supersedes the exclusion above). The hidden overall
    // (itemnumber=0) still showed as a greyed "extra grade" column to teachers
    // (moodle/grade:viewhidden) and was reported as confusing. PERITEM now shows
    // only the per-iDevice columns; completion-by-grade targets a per-iDevice item
    // (workshop model) or uses OVERALL mode. Delete the leftover overall items so
    // existing activities match the new model without waiting for a re-sync.
    if ($oldversion < 2026060800) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        // EXELEARNING_GRADEMODEL_PERITEM = 1 (literal: keep upgrade.php independent
        // of lib.php constants).
        $periteminstances = $DB->get_records('exelearning', ['grademodel' => 1], '', 'id, course');
        foreach ($periteminstances as $inst) {
            grade_update(
                'mod/exelearning',
                $inst->course,
                'mod',
                'exelearning',
                $inst->id,
                0,
                null,
                ['deleted' => true]
            );
        }

        upgrade_mod_savepoint(true, 2026060800, 'exelearning');
    }

    // Stage 15 (2026061200): migration audit/idempotency table for the sibling
    // migration tool. Maps each migrated source course
    // module to the eXeLearning activity created from it, so re-running the tool
    // skips already-migrated activities. Numbered above every prior stage so it
    // also runs on sites already upgraded past 2026060800 (otherwise the table
    // would only ever be created on fresh installs via install.xml).
    if ($oldversion < 2026061200) {
        $table = new xmldb_table('exelearning_migration');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sourcecmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('targetcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('sourcecomponent_sourcecmid', XMLDB_INDEX_UNIQUE, ['sourcecomponent', 'sourcecmid']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026061200, 'exelearning');
    }

    // Stage 16 (2026061201): audit columns for the migration map. Records
    // the admin who ran the tool and a timemodified for future re-run bookkeeping.
    // Pre-upgrade rows are backfilled (userid 0, timemodified = timecreated).
    if ($oldversion < 2026061201) {
        $table = new xmldb_table('exelearning_migration');

        $field = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'targetcmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $dbman->add_key($table, new xmldb_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']));
        }

        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            $DB->execute("UPDATE {exelearning_migration} SET timemodified = timecreated");
        }

        upgrade_mod_savepoint(true, 2026061201, 'exelearning');
    }

    // Stage 17 (2026061202): custom completion rule storage. Adds the
    // nullable completionstatusrequired column to the instance so the activity can
    // be marked complete when the user's attempt reaches a required status (passed
    // or completed). NULL keeps the rule disabled, preserving current behaviour.
    if ($oldversion < 2026061202) {
        $instance = new xmldb_table('exelearning');
        $field = new xmldb_field(
            'completionstatusrequired',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            null,
            null,
            null,
            'gradecat'
        );
        if (!$dbman->field_exists($instance, $field)) {
            $dbman->add_field($instance, $field);
        }
        upgrade_mod_savepoint(true, 2026061202, 'exelearning');
    }

    // Stage 18 (2026061700): drop the inert display/displayoptions columns. They were
    // inherited from mod_resource and never read by the plugin (the only reference,
    // exelearning_package_legacy::save_draft_file(), is dead in production), so they
    // carried no data the plugin uses.
    if ($oldversion < 2026061700) {
        $instance = new xmldb_table('exelearning');
        foreach (['display', 'displayoptions'] as $fieldname) {
            $field = new xmldb_field($fieldname);
            if ($dbman->field_exists($instance, $field)) {
                $dbman->drop_field($instance, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026061700, 'exelearning');
    }

    // Stage 19 (2026061800): xAPI ingestion audit/idempotency table.
    // One row per processed xAPI statement.id so a repeated statement is not re-applied
    // (LRS idempotency). The grade/UI never depend on this table — the flat
    // exelearning_attempt table does; it is audit/dedup only.
    if ($oldversion < 2026061800) {
        $table = new xmldb_table('exelearning_tracking_events');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('exelearningid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('statementid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('verb', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectid', XMLDB_TYPE_CHAR, '191', null, null, null, null);
            $table->add_field('registration', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $table->add_field('scaled', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('exelearningid_fk', XMLDB_KEY_FOREIGN, ['exelearningid'], 'exelearning', ['id']);
            $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('statementid', XMLDB_INDEX_UNIQUE, ['statementid']);
            $table->add_index('exelearningid_userid', XMLDB_INDEX_NOTUNIQUE, ['exelearningid', 'userid']);

            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026061800, 'exelearning');
    }

    // Stage 20 (2026072400): the runtime editor installer is removed —
    // the editor is a release artifact bundled under dist/static/. Drop the three
    // configs the installer maintained. A leftover
    // moodledata/mod_exelearning/embedded_editor directory is deliberately NOT
    // deleted: nothing reads it any more and removing admin data automatically is
    // riskier than leaving an inert directory behind.
    if ($oldversion < 2026072400) {
        unset_config('embedded_editor_version', 'exelearning');
        unset_config('embedded_editor_installed_at', 'exelearning');
        unset_config('embedded_editor_installing', 'exelearning');
        upgrade_mod_savepoint(true, 2026072400, 'exelearning');
    }

    return true;
}
