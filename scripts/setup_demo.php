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
 * mod_exelearning demo seed script.
 *
 * Creates a course, two students and a mod_exelearning activity backed by the
 * `actividad-evaluable.elpx` fixture. Idempotent: can be re-run without
 * duplicating anything.
 *
 * Intended to be called from `POST_CONFIGURE_COMMANDS` in docker-compose,
 * or manually: `php mod/exelearning/scripts/setup_demo.php`.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/exelearning/lib.php');

global $CFG, $DB, $USER;

cli_writeln('=== mod_exelearning · setup_demo ===');

// Config.

$config = [
    'category_name'     => 'Demo eXeLearning',
    'course_shortname'  => 'EXEDEMO',
    'course_fullname'   => 'Demo eXeLearning · ejemplo de uso',
    'teacher_username'  => 'teacher_demo',
    'teacher_pass'      => 'Demo!2026',
    'students' => [
        ['username' => 'alumno1', 'firstname' => 'Alumno', 'lastname' => 'Uno', 'email' => 'alumno1@example.test'],
        ['username' => 'alumno2', 'firstname' => 'Alumno', 'lastname' => 'Dos', 'email' => 'alumno2@example.test'],
    ],
    'student_pass'      => 'Demo!2026',
    'activity_name'     => 'Actividad evaluable (demo)',
    'fixture_path'      => $CFG->dirroot . '/mod/exelearning/research/fixtures/elpx/actividad-evaluable.elpx',
];

// Session as admin.

\core\session\manager::set_user(get_admin());

// Workaround for erseco/alpine-moodle:v5.0.7: the mod_forum observer
// (mod_forum_observer::course_created) reads $CFG->forum_announcementsubscription
// and $CFG->forum_announcementmaxattachments and, if they are not set, inserts
// NULL into `forcesubscribe`/`maxattachments` of mdl_forum → exception.
// Set reasonable defaults BEFORE create_course().
if (!isset($CFG->forum_announcementsubscription)) {
    set_config('forum_announcementsubscription', '1'); // FORUM_FORCESUBSCRIBE.
    $CFG->forum_announcementsubscription = '1';
}
if (!isset($CFG->forum_announcementmaxattachments)) {
    set_config('forum_announcementmaxattachments', '9');
    $CFG->forum_announcementmaxattachments = '9';
}

// 1) Category.

$category = $DB->get_record('course_categories', ['name' => $config['category_name']]);
if (!$category) {
    $cat = \core_course_category::create((object) [
        'name'        => $config['category_name'],
        'idnumber'    => 'demo-exelearning',
        'description' => 'Categoría para el curso demo de mod_exelearning.',
    ]);
    $category = $DB->get_record('course_categories', ['id' => $cat->id], '*', MUST_EXIST);
    cli_writeln('  · Categoría creada: ' . $category->name . ' (id=' . $category->id . ')');
} else {
    cli_writeln('  · Categoría existente: ' . $category->name . ' (id=' . $category->id . ')');
}

// 2) Course.

$course = $DB->get_record('course', ['shortname' => $config['course_shortname']]);
if (!$course) {
    $course = create_course((object) [
        'category'        => $category->id,
        'shortname'       => $config['course_shortname'],
        'fullname'        => $config['course_fullname'],
        'summary'         => 'Curso demo creado por mod_exelearning/scripts/setup_demo.php.',
        'summaryformat'   => FORMAT_HTML,
        'format'          => 'topics',
        'numsections'     => 1,
        'startdate'       => time(),
        'visible'         => 1,
    ]);
    cli_writeln('  · Curso creado: ' . $course->shortname . ' (id=' . $course->id . ')');
} else {
    cli_writeln('  · Curso existente: ' . $course->shortname . ' (id=' . $course->id . ')');
}
$coursecontext = context_course::instance($course->id);

// 3) Users and enrolment.

$teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
$studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

// Ensure a user exists and enrol them with the given role.
$ensureuser = function (array $u, string $pass, int $roleid) use ($course, $coursecontext) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/user/lib.php');

    $user = $DB->get_record('user', ['username' => $u['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $newuser = (object) [
            'username'      => $u['username'],
            'password'      => $pass,
            'firstname'     => $u['firstname'],
            'lastname'      => $u['lastname'],
            'email'         => $u['email'],
            'confirmed'     => 1,
            'mnethostid'    => $CFG->mnet_localhost_id,
            'auth'          => 'manual',
            'lang'          => 'es',
            'timezone'      => 'Europe/Madrid',
        ];
        $newuser->id = user_create_user($newuser, true, false);
        $user = $DB->get_record('user', ['id' => $newuser->id], '*', MUST_EXIST);
        cli_writeln('    · Usuario creado: ' . $user->username . ' (id=' . $user->id . ')');
    } else {
        cli_writeln('    · Usuario existente: ' . $user->username . ' (id=' . $user->id . ')');
    }

    // Manual enrolment.
    $enrol = enrol_get_plugin('manual');
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*');
    if (!$instance) {
        $courseobj = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
        // In Moodle 5.0.7 + erseco/alpine-moodle the global defaults may not be
        // set → add_default_instance() inserts NULL into `status` and the row
        // fails. Pass explicit field values.
        $fields = [
            'status'           => ENROL_INSTANCE_ENABLED,
            'enrolperiod'      => 0,
            'expirynotify'     => 0,
            'notifyall'        => 0,
            'expirythreshold'  => 86400,
            'roleid'           => 0,
            'customint1'       => 0,
            'enrolstartdate'   => 0,
            'enrolenddate'     => 0,
        ];
        $instanceid = $enrol->add_instance($courseobj, $fields);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }
    if (!is_enrolled($coursecontext, $user, '', true)) {
        $enrol->enrol_user($instance, $user->id, $roleid);
        cli_writeln('      ↳ matriculado como roleid=' . $roleid);
    } else {
        cli_writeln('      ↳ ya matriculado');
    }
    return $user;
};

cli_writeln('  · Profesor:');
$ensureuser([
    'username'  => $config['teacher_username'],
    'firstname' => 'Profesor',
    'lastname'  => 'Demo',
    'email'     => $config['teacher_username'] . '@example.test',
], $config['teacher_pass'], $teacherroleid);

cli_writeln('  · Estudiantes:');
foreach ($config['students'] as $s) {
    $ensureuser($s, $config['student_pass'], $studentroleid);
}

// Enrol the site administrator too, so the demo course shows up under their
// "My courses" dashboard. The admin user already exists, so we only enrol it
// (as editing teacher) reusing the manual enrol instance.
cli_writeln('  · Administrador:');
$adminuser = get_admin();
$enrol = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*');
if (!$instance) {
    $courseobj = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
    $instanceid = $enrol->add_instance($courseobj, [
        'status'          => ENROL_INSTANCE_ENABLED,
        'enrolperiod'     => 0,
        'expirynotify'    => 0,
        'notifyall'       => 0,
        'expirythreshold' => 86400,
        'roleid'          => 0,
        'customint1'      => 0,
        'enrolstartdate'  => 0,
        'enrolenddate'    => 0,
    ]);
    $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
}
if (!is_enrolled($coursecontext, $adminuser, '', true)) {
    $enrol->enrol_user($instance, $adminuser->id, $teacherroleid);
    cli_writeln('    · ' . $adminuser->username . ' matriculado como editingteacher.');
} else {
    cli_writeln('    · ' . $adminuser->username . ' ya matriculado.');
}

// 4) Demo activities (idempotent).

require_once($CFG->libdir . '/completionlib.php');

// Enable completion tracking in the course (required for "require passing grade",
// the SCORM-style condition — DEC-0010).
if (empty($course->enablecompletion)) {
    $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
    $course->enablecompletion = 1;
    rebuild_course_cache($course->id, true);
    cli_writeln('  · Finalización por nota habilitada en el curso.');
}

$admin = get_admin();
$adminctx = context_user::instance($admin->id);
$fs = get_file_storage();
$plugindir = $CFG->dirroot . '/mod/exelearning';
$section = 1; // Section "Actividades evaluables" if present; Moodle falls back to General.

// Fixtures de las actividades.
$fixtures = [
    'exelearning' => $config['fixture_path'],
    'scorm'       => $plugindir . '/research/fixtures/scorm/actividad-evaluable_scorm.zip',
    'h5p'         => $plugindir . '/research/fixtures/h5p/question-set-demo.h5p',
];

// Checks whether a <modname> activity with that name already exists in the course.
$moduleexists = function (string $modname, string $name) use ($course): bool {
    global $DB;
    return $DB->record_exists_sql(
        'SELECT 1 FROM {' . $modname . '} a
         JOIN {course_modules} cm ON cm.instance = a.id
         JOIN {modules} m ON m.id = cm.module AND m.name = :mname
         WHERE a.course = :course AND a.name = :name',
        ['mname' => $modname, 'course' => $course->id, 'name' => $name]
    );
};

// Create a draft itemid from a file on disk.
$makedraft = function (string $pathname) use ($adminctx, $fs): int {
    $draftid = file_get_unused_draft_itemid();
    $fs->create_file_from_pathname([
        'contextid' => $adminctx->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftid,
        'filepath'  => '/',
        'filename'  => basename($pathname),
    ], $pathname);
    return $draftid;
};

// Completion fields "SCORM style: must pass" (DEC-0010): use the core
// "require passing grade" condition (completionpassgrade), uniform across
// exelearning, SCORM and H5P.
$completionpass = [
    'completion'                => COMPLETION_TRACKING_AUTOMATIC,
    'completionview'            => 0,
    'completionusegrade'        => 1,
    'completionpassgrade'       => 1,
    'completiongradeitemnumber' => 0,
    'completionexpected'        => 0,
];

// 4a) mod_exelearning.
if (is_file($fixtures['exelearning']) && !$moduleexists('exelearning', $config['activity_name'])) {
    try {
        $data = (object) array_merge([
            'modulename'  => 'exelearning',
            'module'      => $DB->get_field('modules', 'id', ['name' => 'exelearning'], MUST_EXIST),
            'course'      => $course->id,
            'section'     => $section,
            'visible'     => 1,
            'visibleoncoursepage' => 1,
            'name'        => $config['activity_name'],
            'intro'       => 'Cuestionario demo con dos iDevices calificables (trueorfalse + guess).',
            'introformat' => FORMAT_HTML,
            'package'     => $makedraft($fixtures['exelearning']),
            // PERITEM (default): the gradebook shows one column per gradable iDevice
            // and no overall column (DEC-25-01). Completion targets a per-iDevice item
            // instead of an aggregate overall (workshop model); see the override
            // below. "Pass the whole activity to complete" lives in OVERALL mode.
            'grademax'    => 100,
            'grademin'    => 0,
            'grademethod' => \mod_exelearning\local\attempts::GRADE_HIGHEST,
            'gradedisplaytype' => 0,
            'cmidnumber'  => '',
            'groupmode'   => NOGROUPS,
            'groupingid'  => 0,
        ], [
            // Complete on receiving a grade for the first gradable iDevice. PERITEM
            // has no overall item (DEC-25-01), so completion points at iDevice 1
            // (completiongradeitemnumber=1) using Moodle's native selector.
            'completion'                => COMPLETION_TRACKING_AUTOMATIC,
            'completionview'            => 0,
            'completionusegrade'        => 1,
            'completionpassgrade'       => 0,
            'completiongradeitemnumber' => 1,
            'completionexpected'        => 0,
        ]);
        $cm = add_moduleinfo($data, $course);
        cli_writeln('  · mod_exelearning creado (cmid=' . $cm->coursemodule . ').');
    } catch (\Throwable $e) {
        cli_writeln('  ! mod_exelearning falló: ' . $e->getMessage());
    }
} else {
    cli_writeln('  · mod_exelearning ya existe (o falta fixture).');
}

// 4b) mod_scorm.
$scormname = 'Actividad SCORM evaluable (demo)';
if (is_file($fixtures['scorm']) && !$moduleexists('scorm', $scormname)) {
    try {
        $data = (object) array_merge([
            'modulename'  => 'scorm',
            'module'      => $DB->get_field('modules', 'id', ['name' => 'scorm'], MUST_EXIST),
            'course'      => $course->id,
            'section'     => $section,
            'visible'     => 1,
            'visibleoncoursepage' => 1,
            'name'        => $scormname,
            'intro'       => 'Paquete SCORM 1.2 de ejemplo (exportado desde eXeLearning).',
            'introformat' => FORMAT_HTML,
            'scormtype'   => 'local',
            'packagefile' => $makedraft($fixtures['scorm']),
            'popup'       => 0,
            'width'       => 100,
            'height'      => 500,
            'skipview'    => 0,
            'hidebrowse'  => 0,
            'hidetoc'     => 0,
            'nav'         => 1,
            'navpositionleft'  => -100,
            'navpositiontop'   => -100,
            'displayattemptstatus' => 1,
            'displaycoursestructure' => 0,
            'updatefreq'  => 0,
            'auto'        => 0,
            'grademethod' => 1, // GRADEHIGHEST.
            'maxgrade'    => 100,
            'whatgrade'   => 0, // HIGHESTATTEMPT.
            'maxattempt'  => 0, // Ilimitados.
            'forcecompleted' => 0,
            'forcenewattempt' => 0,
            'lastattemptlock' => 0,
            'masteryoverride' => 1,
            'cmidnumber'  => '',
            'groupmode'   => NOGROUPS,
            'groupingid'  => 0,
        ], $completionpass);
        $cm = add_moduleinfo($data, $course);
        // Belt-and-braces: if the package was stored but its SCOes were not
        // parsed (some programmatic creation paths skip parsing), scorm_get_toc()
        // later crashes with array_keys(null). Force a full parse when missing.
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $scormrec = $DB->get_record('scorm', ['id' => $cm->instance]);
        if ($scormrec && !$DB->record_exists('scorm_scoes', ['scorm' => $scormrec->id])) {
            scorm_parse($scormrec, true);
            cli_writeln('    · SCOes (re)parseados.');
        }
        cli_writeln('  · mod_scorm creado (cmid=' . $cm->coursemodule . ').');
    } catch (\Throwable $e) {
        cli_writeln('  ! mod_scorm falló: ' . $e->getMessage());
    }
} else {
    cli_writeln('  · mod_scorm ya existe (o falta fixture).');
}

// 4c) mod_h5pactivity.
$h5pname = 'Actividad H5P evaluable (demo)';
if (is_file($fixtures['h5p']) && !$moduleexists('h5pactivity', $h5pname)) {
    try {
        $data = (object) array_merge([
            'modulename'   => 'h5pactivity',
            'module'       => $DB->get_field('modules', 'id', ['name' => 'h5pactivity'], MUST_EXIST),
            'course'       => $course->id,
            'section'      => $section,
            'visible'      => 1,
            'visibleoncoursepage' => 1,
            'name'         => $h5pname,
            'intro'        => 'Conjunto de preguntas H5P (varias tareas evaluables con intentos).',
            'introformat'  => FORMAT_HTML,
            'packagefile'  => $makedraft($fixtures['h5p']),
            'displayoptions' => 0,
            'enabletracking' => 1,
            'grademethod'  => 1, // GRADEHIGHESTATTEMPT.
            'reviewmode'   => 1,
            'grade'        => 100,
            'gradepass'    => 50,
            'maxattempt'   => 0,
            'cmidnumber'   => '',
            'groupmode'    => NOGROUPS,
            'groupingid'   => 0,
        ], $completionpass);
        $cm = add_moduleinfo($data, $course);
        cli_writeln('  · mod_h5pactivity creado (cmid=' . $cm->coursemodule . ').');
    } catch (\Throwable $e) {
        cli_writeln('  ! mod_h5pactivity falló: ' . $e->getMessage());
    }
} else {
    cli_writeln('  · mod_h5pactivity ya existe (o falta fixture).');
}

cli_writeln('=== setup_demo terminado ===');
cli_writeln('  Curso:       http://localhost/course/view.php?id=' . $course->id);
cli_writeln('  Profesor:    ' . $config['teacher_username'] . ' / ' . $config['teacher_pass']);
cli_writeln('  Estudiantes: alumno1, alumno2 / ' . $config['student_pass']);
