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

namespace mod_exelearning;

use advanced_testcase;
use mod_exelearning\local\styles_service;

/**
 * The styles-management admin settings must render their per-row actions as links, not
 * as nested <form> elements.
 *
 * These settings are shown inside the plugin's admin settings page, which wraps every
 * setting in a single <form>. A nested <form> is invalid HTML: the browser hoists the
 * inner form's hidden fields (action/sesskey) into the outer form, so the page's
 * "Save changes" posts action=disable|delete instead of action=save-settings and the
 * whole settings page silently saves nothing. This regression guards the link rendering.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\admin\admin_setting_stylesuploaded
 * @covers     \mod_exelearning\admin\admin_setting_stylesbuiltins
 */
final class admin_setting_styles_render_test extends advanced_testcase {
    /**
     * An uploaded style renders enable/disable + delete as sesskey-protected links to
     * styles.php, with no nested <form> that would break the settings page save.
     */
    public function test_uploaded_styles_render_links_not_nested_forms(): void {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php', ['section' => 'modsettingexelearning']);

        styles_service::install_from_zip($this->make_style_zip('Theme Render'), 'render.zip');

        $setting = new \mod_exelearning\admin\admin_setting_stylesuploaded();
        $html = $setting->output_html('');

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('/mod/exelearning/admin/styles.php', $html);
        $this->assertStringContainsString('action=delete', $html);
        $this->assertMatchesRegularExpression('~action=(enable|disable)~', $html);
        $this->assertStringContainsString('sesskey=', $html);
    }

    /**
     * The built-in themes setting renders without any nested <form> as well (so the
     * settings page save is never broken by it).
     */
    public function test_builtin_styles_setting_emits_no_nested_form(): void {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php', ['section' => 'modsettingexelearning']);

        $setting = new \mod_exelearning\admin\admin_setting_stylesbuiltins();
        $html = $setting->output_html('');

        $this->assertStringNotContainsString('<form', $html);
    }

    /**
     * Build a minimal valid style ZIP (config.xml + one CSS) and return its path.
     *
     * @param string $name Style name for config.xml.
     * @return string
     */
    private function make_style_zip(string $name): string {
        $zippath = make_temp_directory('mod_exelearning') . '/style-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('config.xml', '<config><name>' . $name . '</name><title>' . $name
            . '</title><version>1.0</version></config>');
        $zip->addFromString('style.css', 'body { color: red; }');
        $zip->close();
        return $zippath;
    }

    /**
     * The styles admin page stays registered (it is the action endpoint the row
     * buttons post to, and admin_externalpage_setup() needs it) but hidden from
     * the admin menu: managing styles happens on the plugin settings page, and a
     * second visible manager page duplicated the widgets — including an upload
     * control that silently discarded files (UX-01, DEC-0067).
     */
    public function test_styles_admin_page_is_registered_but_hidden(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');

        $page = admin_get_root(true, false)->locate('mod_exelearning_styles');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertTrue($page->hidden);
    }
}
