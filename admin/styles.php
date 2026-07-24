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
 * Admin page to manage uploaded eXeLearning styles.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_exelearning\local\styles_service;

admin_externalpage_setup('mod_exelearning_styles');

$context = \context_system::instance();
require_capability('moodle/site:config', $context);
require_capability('mod/exelearning:manageembeddededitor', $context);

$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/mod/exelearning/admin/styles.php'));
$PAGE->set_title(get_string('stylesmanager', 'mod_exelearning'));
$PAGE->set_heading(get_string('stylesmanager', 'mod_exelearning'));

$returnurl = new moodle_url('/mod/exelearning/admin/styles.php');

if ($action !== '' && confirm_sesskey()) {
    if ($action === 'enable' || $action === 'disable') {
        $slug = required_param('slug', PARAM_ALPHANUMEXT);
        styles_service::set_uploaded_enabled($slug, $action === 'enable');
        \core\notification::success(get_string('changessaved'));
        redirect($returnurl);
    } else if ($action === 'delete') {
        $slug = required_param('slug', PARAM_ALPHANUMEXT);
        // Delete is destructive and arrives as a sesskey-protected GET link, so confirm
        // server-side: a first hit (or a link prefetch) only shows the confirmation; the
        // actual delete needs the confirmed POST that $OUTPUT->confirm() generates.
        if (!optional_param('confirm', 0, PARAM_BOOL)) {
            $confirmurl = new moodle_url($returnurl, [
                'action' => 'delete',
                'slug' => $slug,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(
                get_string('stylesdelete_confirm', 'mod_exelearning'),
                $confirmurl,
                $returnurl
            );
            echo $OUTPUT->footer();
            exit;
        }
        styles_service::delete_uploaded($slug);
        \core\notification::success(get_string('stylesdelete_success', 'mod_exelearning'));
        redirect($returnurl);
    } else if ($action === 'enablebuiltin' || $action === 'disablebuiltin') {
        $id = required_param('id', PARAM_ALPHANUMEXT);
        styles_service::set_builtin_enabled($id, $action === 'enablebuiltin');
        \core\notification::success(get_string('changessaved'));
        redirect($returnurl);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('stylesmanager', 'mod_exelearning'));

echo \html_writer::tag('p', get_string('stylesmanager_intro', 'mod_exelearning'));

// Upload form (native filemanager; auto-installs the dropped ZIP on save).
$upload = new \mod_exelearning\admin\admin_setting_stylesupload(
    'exelearning/styles_drops',
    get_string('stylesupload_label', 'mod_exelearning'),
    get_string(
        'stylesupload_hint',
        'mod_exelearning',
        display_size(styles_service::get_max_zip_size())
    ),
    'styles_drops',
    0,
    [
        'accepted_types' => ['.zip'],
        'maxbytes' => styles_service::get_max_zip_size(),
        'maxfiles' => -1,
        'subdirs' => 0,
    ]
);
echo $upload->output_html('');

// Uploaded styles list.
$uploaded = new \mod_exelearning\admin\admin_setting_stylesuploaded();
echo $uploaded->output_html('');

// Built-in themes list.
$builtins = new \mod_exelearning\admin\admin_setting_stylesbuiltins();
echo $builtins->output_html('');

echo $OUTPUT->footer();
