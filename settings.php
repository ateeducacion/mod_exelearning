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
 * DEC-0-09: embedded editor mode only. Integration with eXeLearning Online
 * was discarded to avoid external dependencies. The editor itself ships inside
 * the release package (DEC-106-01) and has no runtime management; this page only
 * manages defined styles, gated by the
 * `mod/exelearning:manageembeddededitor` capability.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Register the styles action endpoint. Hidden from the admin menu (DEC-110-01):
// styles are managed on this settings page; admin/styles.php only processes the
// enable/disable/delete links (they cannot be forms nested inside the settings
// form) and hosts the delete confirmation. The registration must stay — it is
// what admin_externalpage_setup() resolves — and must be added before the
// $fulltree guard so it is always present in the admin tree.
$ADMIN->add('modsettings', new admin_externalpage(
    'mod_exelearning_styles',
    get_string('stylesmanager', 'mod_exelearning'),
    new moodle_url('/mod/exelearning/admin/styles.php'),
    'mod/exelearning:manageembeddededitor',
    true
));

// Register the site-wide migration tool only when a sibling plugin (mod_exeweb /
// mod_exescorm) is installed, so admins can bulk-migrate their activities into
// eXeLearning (issue #13 #3, DEC-13-05). Registered outside the $fulltree guard.
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
    // Embedded editor (DEC-108-01): a single site-wide toggle. The editor itself
    // ships inside the release package (DEC-106-01) and has no runtime management;
    // this switch lets a site use the plugin as a pure .elpx player — uploads
    // keep working, only in-place editing is hidden and refused. The checkbox is
    // negative (disable) on purpose: unset config and unticked box then agree,
    // avoiding the "Default: Yes but unticked" confusion before upgradesettings
    // materialises a positive default (same pattern as stylesblockimport).
    $settings->add(new admin_setting_heading(
        'mod_exelearning/editorsettingsheading',
        get_string('editorsettings', 'mod_exelearning'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'exelearning/editordisabled',
        get_string('editordisabled', 'mod_exelearning'),
        get_string('editordisabled_desc', 'mod_exelearning'),
        0
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
}
