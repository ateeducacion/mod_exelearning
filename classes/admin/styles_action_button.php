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
 * Shared renderer for the styles-management admin action buttons.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\admin;

/**
 * Builds a styles-management action (enable/disable/delete) as a sesskey-protected link
 * styled as a button.
 *
 * Shared by admin_setting_stylesbuiltins and admin_setting_stylesuploaded, which render
 * the action as a link rather than an inline <form>: these settings appear inside the
 * admin settings page, which already wraps every setting in one <form>. A nested <form>
 * is invalid HTML and leaks its action/sesskey hidden fields into the outer form's
 * submission, so the page's "Save changes" posts the nested action (e.g. delete) instead
 * of action=save-settings and silently saves nothing. styles.php accepts these actions
 * over GET (optional_param + confirm_sesskey); the destructive delete is confirmed
 * server-side there, so a prefetch cannot destroy data.
 */
final class styles_action_button {
    /**
     * Build a single action as a sesskey-protected link styled as a button.
     *
     * @param \moodle_url $baseurl  The styles.php base URL.
     * @param string $action        The action (enable/disable/enablebuiltin/disablebuiltin/delete).
     * @param string $idkey         The query parameter name carrying the identifier ('id' or 'slug').
     * @param string $idval         The identifier value.
     * @param string $label         The button label.
     * @param string $btnclass      The Bootstrap button modifier class (e.g. 'btn-danger').
     * @return string
     */
    public static function link(
        \moodle_url $baseurl,
        string $action,
        string $idkey,
        string $idval,
        string $label,
        string $btnclass
    ): string {
        $url = new \moodle_url($baseurl, ['action' => $action, $idkey => $idval, 'sesskey' => sesskey()]);
        return \html_writer::link($url, $label, ['class' => 'btn btn-sm ' . $btnclass, 'role' => 'button']);
    }
}
