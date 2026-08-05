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
 * Action endpoint for the eXeLearning styles managed on the plugin settings page.
 *
 * This is deliberately NOT a management page (DEC-110-01). Styles are managed on
 * the plugin settings page, where the upload control sits inside the settings
 * form and actually saves; a second visible manager here duplicated the widgets
 * — including an upload control with no form around it, which silently discarded
 * dropped files (UX-01 in the external validation of release 4.0.2).
 *
 * The script itself must survive: the per-row enable/disable/delete buttons are
 * sesskey-protected GET links because a <form> nested inside the settings-page
 * form is invalid HTML and used to break the whole settings save. Those links
 * need a handler outside the settings form — this one — which processes the
 * action (with a server-side confirmation for the destructive delete) and
 * redirects back to the settings page. Do not add rendering back here.
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

// Every outcome lands back on the settings page, the single place styles are managed.
$returnurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingexelearning']);

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
            $confirmurl = new moodle_url('/mod/exelearning/admin/styles.php', [
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

// No action to process (a direct visit): there is nothing to show here.
redirect($returnurl);
