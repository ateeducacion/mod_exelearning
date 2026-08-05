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
 * Privacy provider for mod_exelearning (DEC-0-07 attempt history).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\helper;

/**
 * Implements the Moodle privacy API for the attempt data stored by the plugin.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('exelearning_attempt', [
            'userid'       => 'privacy:metadata:exelearning_attempt:userid',
            'attempt'      => 'privacy:metadata:exelearning_attempt:attempt',
            'itemnumber'   => 'privacy:metadata:exelearning_attempt:itemnumber',
            'rawscore'     => 'privacy:metadata:exelearning_attempt:rawscore',
            'maxscore'     => 'privacy:metadata:exelearning_attempt:maxscore',
            'scaledscore'  => 'privacy:metadata:exelearning_attempt:scaledscore',
            'status'       => 'privacy:metadata:exelearning_attempt:status',
            'timecreated'  => 'privacy:metadata:exelearning_attempt:timecreated',
            'timemodified' => 'privacy:metadata:exelearning_attempt:timemodified',
        ], 'privacy:metadata:exelearning_attempt');

        // The xAPI ingestion audit/idempotency log (DEC-85-01): one row per processed
        // statement.id, attributed to the authenticated user (the client actor is
        // always ignored). The statements carry no PII; the personal datum is the link
        // between this user and the activity interactions.
        $collection->add_database_table('exelearning_tracking_events', [
            'userid'       => 'privacy:metadata:exelearning_tracking_events:userid',
            'statementid'  => 'privacy:metadata:exelearning_tracking_events:statementid',
            'verb'         => 'privacy:metadata:exelearning_tracking_events:verb',
            'objectid'     => 'privacy:metadata:exelearning_tracking_events:objectid',
            'registration' => 'privacy:metadata:exelearning_tracking_events:registration',
            'scaled'       => 'privacy:metadata:exelearning_tracking_events:scaled',
            'timecreated'  => 'privacy:metadata:exelearning_tracking_events:timecreated',
        ], 'privacy:metadata:exelearning_tracking_events');

        // The instance row records which user last edited the activity settings.
        $collection->add_database_table('exelearning', [
            'usermodified' => 'privacy:metadata:exelearning:usermodified',
        ], 'privacy:metadata:exelearning');

        // The migration tool records which manager migrated each mod_exeweb/mod_exescorm
        // activity into mod_exelearning (DEC-13-05 / DEC-13-12). This is a system-level
        // audit and idempotency map: deleting a row would let a re-run duplicate the
        // target, so on erasure the userid is anonymised to 0 (the table's existing
        // "pre-upgrade / unknown" sentinel) rather than deleted. This mirrors core_tag,
        // which keeps the shared/structural row and clears only the identity.
        $collection->add_database_table('exelearning_migration', [
            'userid'          => 'privacy:metadata:exelearning_migration:userid',
            'sourcecomponent' => 'privacy:metadata:exelearning_migration:sourcecomponent',
            'sourcecmid'      => 'privacy:metadata:exelearning_migration:sourcecmid',
            'targetcmid'      => 'privacy:metadata:exelearning_migration:targetcmid',
            'timecreated'     => 'privacy:metadata:exelearning_migration:timecreated',
            'timemodified'    => 'privacy:metadata:exelearning_migration:timemodified',
        ], 'privacy:metadata:exelearning_migration');

        // The plugin also pushes each user's scores into the Moodle gradebook
        // via grade_update() (track.php / lib.php), so declare that data flow.
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');

        return $collection;
    }

    /**
     * Recalculate (and clear) gradebook grades for users after their attempt
     * rows are deleted, so an erased user does not keep a stale gradebook grade
     * with no backing attempt history.
     *
     * @param int $exelearningid
     * @param int[] $userids
     */
    protected static function clear_grades_for_users(int $exelearningid, array $userids): void {
        global $CFG, $DB;

        if (empty($userids)) {
            return;
        }
        $instance = $DB->get_record('exelearning', ['id' => $exelearningid]);
        if (!$instance) {
            return;
        }
        require_once($CFG->dirroot . '/mod/exelearning/lib.php');
        foreach ($userids as $userid) {
            exelearning_recalculate_user_grades($instance, (int) $userid);
        }
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {exelearning_attempt} a
                  JOIN {exelearning} e ON e.id = a.exelearningid
                  JOIN {course_modules} cm ON cm.instance = e.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'modname'  => 'exelearning',
            'modlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        // The xAPI audit log can hold rows for a user even without an attempt (e.g. a
        // lifecycle statement or an ungraded activity), so surface its contexts too.
        $eventsql = "SELECT ctx.id
                       FROM {exelearning_tracking_events} te
                       JOIN {exelearning} e ON e.id = te.exelearningid
                       JOIN {course_modules} cm ON cm.instance = e.id
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                       JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
                      WHERE te.userid = :userid";
        $contextlist->add_from_sql($eventsql, [
            'modname'  => 'exelearning',
            'modlevel' => CONTEXT_MODULE,
            'userid'   => $userid,
        ]);

        // The migration audit table is a system-level record of which manager ran each
        // migration; surface the system context when the user appears there (idiom: core_tag).
        $contextlist->add_from_sql(
            "SELECT c.id
               FROM {context} c
               JOIN {exelearning_migration} mig ON mig.userid = :userid
              WHERE c.contextlevel = :syslevel",
            ['userid' => $userid, 'syslevel' => CONTEXT_SYSTEM]
        );

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_system) {
            // Managers who ran a migration (system-level audit record); the anonymised
            // userid 0 is not a real user, so exclude it.
            $userlist->add_from_sql(
                'userid',
                "SELECT userid FROM {exelearning_migration} WHERE userid <> 0",
                []
            );
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }

        $sql = "SELECT a.userid
                  FROM {exelearning_attempt} a
                  JOIN {exelearning} e ON e.id = a.exelearningid
                  JOIN {course_modules} cm ON cm.instance = e.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, [
            'modname' => 'exelearning',
            'cmid'    => $context->instanceid,
        ]);

        $eventsql = "SELECT te.userid
                       FROM {exelearning_tracking_events} te
                       JOIN {exelearning} e ON e.id = te.exelearningid
                       JOIN {course_modules} cm ON cm.instance = e.id
                       JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $eventsql, [
            'modname' => 'exelearning',
            'cmid'    => $context->instanceid,
        ]);
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::export_migrations_for_user($context, $user);
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('exelearning', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $attempts = $DB->get_records(
                'exelearning_attempt',
                ['exelearningid' => $cm->instance, 'userid' => $user->id],
                'attempt ASC, itemnumber ASC'
            );
            $events = $DB->get_records(
                'exelearning_tracking_events',
                ['exelearningid' => $cm->instance, 'userid' => $user->id],
                'timecreated ASC'
            );
            if (!$attempts && !$events) {
                continue;
            }

            $data = [];
            foreach ($attempts as $a) {
                $data[] = [
                    'attempt'      => $a->attempt,
                    'itemnumber'   => $a->itemnumber,
                    'rawscore'     => $a->rawscore,
                    'maxscore'     => $a->maxscore,
                    'scaledscore'  => $a->scaledscore,
                    'status'       => $a->status,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($a->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($a->timemodified),
                ];
            }
            $eventdata = [];
            foreach ($events as $ev) {
                $eventdata[] = [
                    'statementid'  => $ev->statementid,
                    'verb'         => $ev->verb,
                    'objectid'     => $ev->objectid,
                    'registration' => $ev->registration,
                    'scaled'       => $ev->scaled,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($ev->timecreated),
                ];
            }

            $contextdata = helper::get_context_data($context, $user);
            $contextdata = (object) array_merge(
                (array) $contextdata,
                ['attempts' => $data, 'xapi_events' => $eventdata]
            );
            writer::with_context($context)->export_data([], $contextdata);
        }
    }

    /**
     * Export the migration audit rows the given user (a manager) created, in the
     * system context.
     *
     * @param \context $context The system context.
     * @param \stdClass $user
     */
    protected static function export_migrations_for_user(\context $context, \stdClass $user): void {
        global $DB;

        $records = $DB->get_records('exelearning_migration', ['userid' => $user->id], 'timecreated ASC');
        if (!$records) {
            return;
        }

        $data = [];
        foreach ($records as $r) {
            $data[] = [
                'sourcecomponent' => $r->sourcecomponent,
                'sourcecmid'      => $r->sourcecmid,
                'targetcmid'      => $r->targetcmid,
                'timecreated'     => \core_privacy\local\request\transform::datetime($r->timecreated),
                'timemodified'    => \core_privacy\local\request\transform::datetime($r->timemodified),
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:migrations', 'mod_exelearning')],
            (object) ['migrations' => $data]
        );
    }

    /**
     * Delete all user data for all users in the given context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_system) {
            // Anonymise every migration audit row: keep the idempotency map, clear the
            // manager identity (set userid to the table's "unknown" sentinel, 0).
            $DB->set_field_select('exelearning_migration', 'userid', 0, 'userid <> 0', []);
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('exelearning', $context->instanceid);
        if (!$cm) {
            return;
        }
        $userids = $DB->get_fieldset_select(
            'exelearning_attempt',
            'DISTINCT userid',
            'exelearningid = ?',
            [$cm->instance]
        );
        $DB->delete_records('exelearning_attempt', ['exelearningid' => $cm->instance]);
        $DB->delete_records('exelearning_tracking_events', ['exelearningid' => $cm->instance]);
        self::clear_grades_for_users((int) $cm->instance, $userids);
    }

    /**
     * Delete all user data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                // Anonymise this manager's migration audit rows (keep the rows, drop
                // the identity), mirroring core_tag.
                $DB->set_field('exelearning_migration', 'userid', 0, ['userid' => $user->id]);
                continue;
            }
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('exelearning', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $DB->delete_records(
                'exelearning_attempt',
                ['exelearningid' => $cm->instance, 'userid' => $user->id]
            );
            $DB->delete_records(
                'exelearning_tracking_events',
                ['exelearningid' => $cm->instance, 'userid' => $user->id]
            );
            self::clear_grades_for_users((int) $cm->instance, [(int) $user->id]);
        }
    }

    /**
     * Delete data for multiple users in a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context instanceof \context_system) {
            $userids = $userlist->get_userids();
            if (empty($userids)) {
                return;
            }
            // Anonymise these managers' migration audit rows (keep the rows, drop the
            // identity), mirroring core_tag.
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $DB->set_field_select('exelearning_migration', 'userid', 0, "userid $insql", $inparams);
            return;
        }

        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('exelearning', $context->instanceid);
        if (!$cm) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge(['exelearningid' => $cm->instance], $inparams);
        $DB->delete_records_select(
            'exelearning_attempt',
            "exelearningid = :exelearningid AND userid $insql",
            $params
        );
        $DB->delete_records_select(
            'exelearning_tracking_events',
            "exelearningid = :exelearningid AND userid $insql",
            $params
        );
        self::clear_grades_for_users((int) $cm->instance, $userids);
    }
}
