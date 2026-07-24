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
 * mod_exelearning admin settings.
 *
 * DEC-0009: embedded editor mode only. Integration with eXeLearning Online
 * was discarded to avoid external dependencies. The editor itself ships inside
 * the release package (DEC-0065) and has no runtime management; this page only
 * manages defined styles, gated by the
 * `mod/exelearning:manageembeddededitor` capability.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Register the styles management external page so it can be linked from the
// settings page and reached directly. Must be added before the $fulltree
// guard so it is always registered in the admin tree.
$ADMIN->add('modsettings', new admin_externalpage(
    'mod_exelearning_styles',
    get_string('stylesmanager', 'mod_exelearning'),
    new moodle_url('/mod/exelearning/admin/styles.php'),
    'mod/exelearning:manageembeddededitor'
));

// Register the site-wide migration tool only when a sibling plugin (mod_exeweb /
// mod_exescorm) is installed, so admins can bulk-migrate their activities into
// eXeLearning (issue #13 #3, DEC-0026). Registered outside the $fulltree guard.
$exelearninginstalledmods = \core_component::get_plugin_list('mod');
if (isset($exelearninginstalledmods['exeweb']) || isset($exelearninginstalledmods['exescorm'])) {
    $ADMIN->add('modsettings', new admin_externalpage(
        'mod_exelearning_migrate',
        get_string('migratetitle', 'mod_exelearning'),
        new moodle_url('/mod/exelearning/admin/migrate.php'),
        'mod/exelearning:migrate'
    ));
}

if ($ADMIN->fulltree) {
    // Embedded editor (DEC-0066): a single site-wide toggle. The editor itself
    // ships inside the release package (DEC-0065) and has no runtime management;
    // this switch lets a site use the plugin as a pure .elpx player — uploads
    // keep working, only in-place editing is hidden and refused.
    $settings->add(new admin_setting_heading(
        'mod_exelearning/editorsettingsheading',
        get_string('editorsettings', 'mod_exelearning'),
        get_string('editorsettings_desc', 'mod_exelearning')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'exelearning/editorenabled',
        get_string('editorenabled', 'mod_exelearning'),
        get_string('editorenabled_desc', 'mod_exelearning'),
        1
    ));

    // Defined styles management (upload / enable / disable / lockdown).
    $settings->add(new admin_setting_heading(
        'mod_exelearning/stylesheading',
        get_string('stylesmanager', 'mod_exelearning'),
        get_string('stylesmanager_intro', 'mod_exelearning')
    ));

    // Upload a new style ZIP (native filemanager; auto-installs on save).
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesupload(
        'exelearning/styles_drops',
        get_string('stylesupload_label', 'mod_exelearning'),
        get_string(
            'stylesupload_hint',
            'mod_exelearning',
            display_size(\mod_exelearning\local\styles_service::get_max_zip_size())
        ),
        'styles_drops',
        0,
        [
            'accepted_types' => ['.zip'],
            'maxbytes' => \mod_exelearning\local\styles_service::get_max_zip_size(),
            'maxfiles' => -1,
            'subdirs' => 0,
        ]
    ));

    // List of uploaded styles with enable/disable/delete actions.
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesuploaded());

    // List of built-in themes with enable/disable toggles.
    $settings->add(new \mod_exelearning\admin\admin_setting_stylesbuiltins());

    // Block importing/installing styles from project content.
    $settings->add(new admin_setting_configcheckbox(
        'exelearning/stylesblockimport',
        get_string('stylesblockimport', 'mod_exelearning'),
        get_string('stylesblockimport_desc', 'mod_exelearning'),
        0
    ));

    // Settings for the xAPI ingestion channel (DEC-0064).
    $settings->add(new admin_setting_heading(
        'mod_exelearning/xapiheading',
        get_string('xapisettings', 'mod_exelearning'),
        get_string('xapisettings_desc', 'mod_exelearning')
    ));

    // Master switch for the xAPI-primary grading channel. On (default): a package that
    // bundles the eXeLearning xAPI emitter grades via xAPI and the SCORM shim is kept
    // inert. Off: those packages fall back to SCORM grading — a kill switch that needs no
    // code change. Legacy packages without the emitter always use SCORM. This is NOT cmi5
    // and NOT an external-LRS integration; SCORM 1.2 stays the compatibility path.
    $settings->add(new admin_setting_configcheckbox(
        'exelearning/xapiprimaryenabled',
        get_string('xapiprimaryenabled', 'mod_exelearning'),
        get_string('xapiprimaryenabled_desc', 'mod_exelearning'),
        1
    ));
}
