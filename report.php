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
 * Attempts report for mod_exelearning (DEC-0-07).
 *
 * Teacher-facing table of users × attempts × items, modelled on the
 * mod_h5pactivity report.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

$id = required_param('id', PARAM_INT); // Course module id.

$cm = get_coursemodule_from_id('exelearning', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$exelearning = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/exelearning:viewreport', $context);

// Honour separate groups: a teacher without moodle/site:accessallgroups must
// only see and manage attempts of students in their own visible group (as
// view.php's participation summary already does). $restrictusers === null means
// no restriction (NOGROUPS / VISIBLEGROUPS / accessallgroups); an array (which
// may be empty) limits the report to those user ids.
$groupmode = groups_get_activity_groupmode($cm, $course);
$currentgroup = 0;
$restrictusers = null;
if ($groupmode != NOGROUPS) {
    $currentgroup = groups_get_activity_group($cm, true);
    if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
        $restrictusers = empty($currentgroup)
            ? []
            : array_map('intval', array_keys(get_enrolled_users($context, '', (int) $currentgroup, 'u.id')));
    }
}

// Optional userid: the gradebook "grade analysis" link (grade.php) forwards the
// graded user so the teacher lands on that student's attempts (DEC-13-06).
$userid = optional_param('userid', 0, PARAM_INT);
$baseurlparams = ['id' => $cm->id];
if ($userid > 0) {
    $baseurlparams['userid'] = $userid;
}
$PAGE->set_url('/mod/exelearning/report.php', $baseurlparams);
$PAGE->set_title(format_string($exelearning->name) . ': ' . get_string('attemptsreport', 'mod_exelearning'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Delete attempt (DEC-0-07 phase 2): removes every row of a (userid, attempt)
// pair and recalculates the student's grade from the remaining history.
$deleteuser = optional_param('deleteuser', 0, PARAM_INT);
$deleteattempt = optional_param('deleteattempt', 0, PARAM_INT);
if (
    $deleteuser && $deleteattempt && confirm_sesskey()
        && has_capability('mod/exelearning:deleteattempt', $context)
) {
    // Separate groups: refuse to delete attempts of out-of-group students.
    if ($restrictusers !== null && !in_array((int) $deleteuser, $restrictusers, true)) {
        throw new \moodle_exception('attemptnotingroup', 'mod_exelearning');
    }
    // Deletion is destructive and arrives as a sesskey-protected GET link, so
    // confirm server-side (same pattern as admin/styles.php): a first hit - or
    // a browser/proxy link prefetch - only renders the confirmation page; the
    // actual delete needs the confirmed request $OUTPUT->confirm() generates.
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        $confirmurl = new moodle_url('/mod/exelearning/report.php', $baseurlparams + [
            'deleteuser'    => $deleteuser,
            'deleteattempt' => $deleteattempt,
            'confirm'       => 1,
            'sesskey'       => sesskey(),
        ]);
        $returnurl = new moodle_url('/mod/exelearning/report.php', $baseurlparams);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('deleteattempt_confirm', 'mod_exelearning', $deleteattempt),
            $confirmurl,
            $returnurl
        );
        echo $OUTPUT->footer();
        exit;
    }
    // Delete and recalculate atomically: if recalculation failed after the
    // delete committed, the gradebook would keep the deleted attempt's grade.
    $transaction = $DB->start_delegated_transaction();
    $DB->delete_records('exelearning_attempt', [
        'exelearningid' => $exelearning->id,
        'userid'        => $deleteuser,
        'attempt'       => $deleteattempt,
    ]);
    exelearning_recalculate_user_grades($exelearning, $deleteuser);
    $transaction->allow_commit();
    \mod_exelearning\event\attempt_deleted::create([
        'context'       => $context,
        'objectid'      => $exelearning->id,
        'relateduserid' => $deleteuser,
        'other'         => ['attemptid' => $deleteattempt],
    ])->trigger();
    redirect(
        new moodle_url('/mod/exelearning/report.php', $baseurlparams),
        get_string('attemptdeleted', 'mod_exelearning'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$candelete = has_capability('mod/exelearning:deleteattempt', $context);

// The report reflects the grademodel (DEC-0-08): in "per iDevice only" the
// Overall row (itemnumber=0) is hidden; in "overall only" the per-iDevice rows
// are hidden. The internal history (exelearning_attempt) keeps recording both,
// so this only affects presentation, not the data nor the grade recalculation.
$grademodel = (int) ($exelearning->grademodel ?? EXELEARNING_GRADEMODEL_PERITEM);

// Map itemnumber -> human-readable name (overall + iDevices). Hoisted above the
// header so the download branch (DEC-0-07) and the on-screen table share a
// single dataset/filter definition (move, don't duplicate).
$itemnames = [0 => get_string('report_overall', 'mod_exelearning')];
$gradeitems = $DB->get_records(
    'exelearning_grade_item',
    ['exelearningid' => $exelearning->id],
    'itemnumber ASC',
    'itemnumber, name, idevicetype'
);
foreach ($gradeitems as $gi) {
    $itemnames[(int) $gi->itemnumber] = format_string($gi->name);
}

// Build the attempts query from the active filters: the separate-groups
// restriction and the optional single-user filter (grade-analysis deep link).
// $restrictusers === [] means the teacher sees no group, so no rows at all.
if ($restrictusers === []) {
    $attempts = [];
} else {
    $where = ['exelearningid = :exeid'];
    $params = ['exeid' => $exelearning->id];
    if ($restrictusers !== null) {
        [$insql, $inparams] = $DB->get_in_or_equal($restrictusers, SQL_PARAMS_NAMED, 'ru');
        $where[] = "userid $insql";
        $params += $inparams;
    }
    if ($userid > 0) {
        $where[] = 'userid = :uid';
        $params['uid'] = $userid;
    }
    $attempts = $DB->get_records_select(
        'exelearning_attempt',
        implode(' AND ', $where),
        $params,
        'userid ASC, attempt ASC, itemnumber ASC'
    );
}

// Load the users involved (empty when there are no attempts, which is harmless).
$userids = [];
foreach ($attempts as $a) {
    $userids[$a->userid] = true;
}
$users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];

// Whether a row matches the active model (DEC-0-08: peritem shows
// itemnumber>0; overall shows itemnumber=0).
$matchesmode = function (int $itemnumber) use ($grademodel): bool {
    return ($grademodel === EXELEARNING_GRADEMODEL_OVERALL)
            ? ($itemnumber === 0)
            : ($itemnumber > 0);
};

// Pre-pass: flag which (user, attempt) pairs have at least one row matching the
// model. If an attempt has none (e.g. in peritem, an attempt that only recorded
// the overall, with no iDevices), it is shown anyway as a fallback so it does
// not disappear from the report nor become impossible to delete.
$grouphasmatch = [];
foreach ($attempts as $a) {
    $key = $a->userid . '-' . $a->attempt;
    $grouphasmatch[$key] = ($grouphasmatch[$key] ?? false) || $matchesmode((int) $a->itemnumber);
}

// Download branch (DEC-0-07): stream the same dataset/filters as the on-screen
// table through core's dataformat API (CSV/Excel/ODS/JSON). It must run before
// any output, and intentionally before the report_viewed event below: a download
// is not a report *view*, so it does not log one (a dedicated report_downloaded
// event could be added later if auditing wants it). When there are no attempts,
// fall through to the normal empty-state page.
$download = optional_param('download', '', PARAM_ALPHA);
if ($download !== '' && $attempts) {
    $columns = [
        'fullname'     => get_string('report_user', 'mod_exelearning'),
        'attempt'      => get_string('report_attempt', 'mod_exelearning'),
        'item'         => get_string('report_item', 'mod_exelearning'),
        'rawscore'     => get_string('report_score', 'mod_exelearning'),
        'maxscore'     => get_string('grademax', 'core_grades'),
        'status'       => get_string('report_status', 'mod_exelearning'),
        // A completion-only row (DEC-124-03) carries a score that the gradebook does not,
        // because the activity was not a graded one when the learner produced it. The
        // on-screen table does not distinguish them yet, but the machine-readable export
        // must: without this column a downloaded CSV cannot be reconciled against the
        // gradebook at all.
        'gradable'     => get_string('report_gradable', 'mod_exelearning'),
        'timemodified' => get_string('report_date', 'mod_exelearning'),
    ];
    // Mirror the table loop: same grademodel row filtering plus the
    // no-matching-row fallback, so the export matches the screen exactly.
    $exportrows = [];
    foreach ($attempts as $a) {
        $itemnumber = (int) $a->itemnumber;
        $groupkey = $a->userid . '-' . $a->attempt;
        if ($grouphasmatch[$groupkey] && !$matchesmode($itemnumber)) {
            continue;
        }
        $exportrows[] = [
            'fullname'     => isset($users[$a->userid])
                    ? fullname($users[$a->userid]) : ('#' . $a->userid),
            'attempt'      => $a->attempt,
            'item'         => $itemnames[$itemnumber] ?? ('#' . $itemnumber),
            'rawscore'     => (float) $a->rawscore,
            'maxscore'     => (float) $a->maxscore,
            'status'       => $a->status,
            'gradable'     => empty($a->gradable) ? get_string('no') : get_string('yes'),
            'timemodified' => userdate($a->timemodified),
        ];
    }
    \core\dataformat::download_data(
        clean_filename($exelearning->name . '_attempts'),
        $download,
        $columns,
        $exportrows
    );
    die;
}

// Log the report view (a delete request redirects above, so this fires once per
// actual view, not on the delete POST; downloads return above, so they do not
// log a view either).
\mod_exelearning\event\report_viewed::create([
    'context'  => $context,
    'objectid' => $exelearning->id,
])->trigger();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attemptsreport', 'mod_exelearning'));

// When deep-linked from a specific grade ("grade analysis"), show whose attempts
// these are. Guarded by the group restriction so an out-of-group student's name
// is not revealed.
if ($userid > 0 && ($restrictusers === null || in_array($userid, $restrictusers, true))) {
    $filtereduser = $DB->get_record('user', ['id' => $userid]);
    if ($filtereduser) {
        // Escape the name: $OUTPUT->heading() does not HTML-escape its content, and a
        // display name set via LDAP/SAML/WS/CSV upload is not guaranteed tag-stripped,
        // so an unescaped name would run as stored XSS in the grader's session (B8,
        // DEC-34-01). The attempts table below already escapes the same value with s().
        echo $OUTPUT->heading(s(fullname($filtereduser)), 4);
    }
}

// Group selector: lets a teacher switch between the groups they may see. In
// separate-groups mode the options are limited to the teacher's own groups.
if ($groupmode != NOGROUPS) {
    groups_print_activity_menu($cm, $PAGE->url);
}

if (empty($attempts)) {
    echo $OUTPUT->notification(
        get_string('noattempts', 'mod_exelearning'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    die;
}

$table = new html_table();
$table->head = [
    get_string('report_user', 'mod_exelearning'),
    get_string('report_attempt', 'mod_exelearning'),
    get_string('report_item', 'mod_exelearning'),
    get_string('report_score', 'mod_exelearning'),
    get_string('report_status', 'mod_exelearning'),
    get_string('report_date', 'mod_exelearning'),
];
if ($candelete) {
    $table->head[] = get_string('report_actions', 'mod_exelearning');
}
$table->attributes['class'] = 'generaltable';

// Track the first VISIBLE row of each (user, attempt) pair to anchor the
// "Delete attempt" button there: deleting removes the whole attempt, so it is
// shown once per attempt and works in any model (it does not rely on overall).
$deleteanchored = [];
foreach ($attempts as $a) {
    $itemnumber = (int) $a->itemnumber;
    $groupkey = $a->userid . '-' . $a->attempt;

    // Reflect the grademodel: hide rows that do not apply to the model, except
    // for attempts with no matching row at all (deletability fallback).
    if ($grouphasmatch[$groupkey] && !$matchesmode($itemnumber)) {
        continue;
    }

    $username = isset($users[$a->userid])
            ? fullname($users[$a->userid]) : ('#' . $a->userid);
    $itemlabel = $itemnames[$itemnumber]
            ?? ('#' . $itemnumber);
    $score = format_float((float) $a->rawscore, 2) . ' / ' . format_float((float) $a->maxscore, 2);
    $row = [
        s($username),
        $a->attempt,
        $itemlabel,
        $score,
        s($a->status),
        userdate($a->timemodified),
    ];
    if ($candelete) {
        if (!isset($deleteanchored[$groupkey])) {
            $deleteanchored[$groupkey] = true;
            $delurl = new moodle_url('/mod/exelearning/report.php', $baseurlparams + [
                'deleteuser'    => $a->userid,
                'deleteattempt' => $a->attempt,
                'sesskey'       => sesskey(),
            ]);
            $row[] = html_writer::link(
                $delurl,
                get_string('deleteattempt', 'mod_exelearning'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        } else {
            $row[] = '';
        }
    }
    $table->data[] = $row;
}

echo html_writer::table($table);

// Download selector (DEC-0-07): reaches the download branch above with the same
// filters (id, optional userid) so the export reflects the on-screen dataset. It
// only renders here, after a non-empty table, so the empty-state page shows none.
echo $OUTPUT->download_dataformat_selector(
    get_string('downloadreport', 'mod_exelearning'),
    new moodle_url('/mod/exelearning/report.php'),
    'download',
    $baseurlparams
);

echo $OUTPUT->footer();
