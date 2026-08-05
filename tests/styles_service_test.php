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
 * Tests for the styles service safety guards and slug/upload helpers.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\styles_service
 */
final class styles_service_test extends advanced_testcase {
    /**
     * is_unsafe_zip_entry() rejects traversal, absolute, scheme and backslash
     * entries (the shared zip-slip guard).
     */
    public function test_is_unsafe_zip_entry(): void {
        // Safe relative paths inside the archive.
        $this->assertFalse(styles_service::is_unsafe_zip_entry('index.html'));
        $this->assertFalse(styles_service::is_unsafe_zip_entry('app/main.js'));
        $this->assertFalse(styles_service::is_unsafe_zip_entry('files/icons/a.svg'));

        // Unsafe: empty, parent traversal, absolute, backslash and URL schemes.
        $this->assertTrue(styles_service::is_unsafe_zip_entry(''));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('../escape.txt'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('a/../../b'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('/etc/passwd'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('a\\b.txt'));
        $this->assertTrue(styles_service::is_unsafe_zip_entry('file:///etc/passwd'));
    }

    /**
     * is_allowed_filename() enforces the extension allow-list and permits
     * directory entries.
     */
    public function test_is_allowed_filename(): void {
        $this->assertTrue(styles_service::is_allowed_filename('style.css'));
        $this->assertTrue(styles_service::is_allowed_filename('app.js'));
        // The extension match is case-insensitive.
        $this->assertTrue(styles_service::is_allowed_filename('icon.PNG'));
        // A directory entry (trailing slash) is allowed.
        $this->assertTrue(styles_service::is_allowed_filename('fonts/'));

        // Disallowed extensions and extensionless files are blocked.
        $this->assertFalse(styles_service::is_allowed_filename('evil.php'));
        $this->assertFalse(styles_service::is_allowed_filename('nested.zip'));
        $this->assertFalse(styles_service::is_allowed_filename('noextension'));
    }

    /**
     * normalize_slug() lowercases, collapses unsafe characters to hyphens and
     * falls back to a safe default when nothing usable remains.
     */
    public function test_normalize_slug(): void {
        $this->assertSame('my-theme', styles_service::normalize_slug('My Theme!'));
        $this->assertSame('foo-bar-2', styles_service::normalize_slug('  Foo_Bar 2  '));
        $this->assertSame('already-good', styles_service::normalize_slug('already-good'));
        // Nothing usable falls back to the default slug.
        $this->assertSame('style', styles_service::normalize_slug('!!!'));
        $this->assertSame('style', styles_service::normalize_slug('   '));
    }

    /**
     * get_max_zip_size() returns the built-in default unless an admin override
     * is configured.
     */
    public function test_get_max_zip_size_default_and_override(): void {
        $this->resetAfterTest();

        $this->assertSame(styles_service::DEFAULT_MAX_ZIP_SIZE, styles_service::get_max_zip_size());

        set_config('styles_max_zip_size', 1234, 'exelearning');
        $this->assertSame(1234, styles_service::get_max_zip_size());
    }

    /**
     * parse_config_xml() reads the style metadata and normalises the slug.
     */
    public function test_parse_config_xml_reads_metadata(): void {
        $xml = '<config><name>My Style</name><title>My Style</title>'
            . '<version>1.2</version><author>ATE</author></config>';

        $meta = styles_service::parse_config_xml($xml);

        $this->assertSame('my-style', $meta['name']);
        $this->assertSame('My Style', $meta['title']);
        $this->assertSame('1.2', $meta['version']);
        $this->assertSame('ATE', $meta['author']);
    }

    /**
     * parse_config_xml() rejects a config that declares no name.
     */
    public function test_parse_config_xml_rejects_missing_name(): void {
        $this->expectException(\moodle_exception::class);
        styles_service::parse_config_xml('<config><name></name></config>');
    }

    /**
     * parse_config_xml() rejects malformed XML.
     */
    public function test_parse_config_xml_rejects_malformed_xml(): void {
        $this->expectException(\moodle_exception::class);
        styles_service::parse_config_xml('<config><name>x');
    }

    /**
     * parse_config_xml() rejects internal entity declarations (billion-laughs
     * style entity expansion) per the hardened parser policy (DEC-26-01).
     */
    public function test_parse_config_xml_rejects_entity_declarations(): void {
        $this->expectException(\moodle_exception::class);
        styles_service::parse_config_xml(
            '<?xml version="1.0"?><!DOCTYPE config [<!ENTITY x "y">]><config><name>&x;</name></config>'
        );
    }

    /**
     * parse_config_xml() rejects a DOCTYPE declaration outright (DEC-26-01).
     */
    public function test_parse_config_xml_rejects_doctype(): void {
        $this->expectException(\moodle_exception::class);
        styles_service::parse_config_xml(
            '<!DOCTYPE config SYSTEM "config.dtd"><config><name>ok</name></config>'
        );
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
     * install_from_zip() extracts the style, records it in the registry and lists it.
     */
    public function test_install_from_zip_registers_style(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $entry = styles_service::install_from_zip($this->make_style_zip('My Style'), 'My Style.zip');

        $this->assertSame('my-style', $entry['id']);
        $this->assertSame('My Style', $entry['title']);
        $this->assertTrue($entry['enabled']);
        $this->assertNotEmpty($entry['css_files']);

        $this->assertArrayHasKey('my-style', styles_service::get_registry()['uploaded']);
        $slugs = array_column(styles_service::list_uploaded_styles(), 'id');
        $this->assertContains('my-style', $slugs);
    }

    /**
     * The registry round-trips through plugin config.
     */
    public function test_registry_roundtrip(): void {
        $this->resetAfterTest();

        $empty = styles_service::get_registry();
        $this->assertSame([], $empty['uploaded']);
        $this->assertSame([], $empty['disabled_builtins']);

        styles_service::save_registry([
            'uploaded' => ['foo' => ['title' => 'Foo']],
            'disabled_builtins' => ['bar'],
        ]);

        $loaded = styles_service::get_registry();
        $this->assertArrayHasKey('foo', $loaded['uploaded']);
        $this->assertSame(['bar'], $loaded['disabled_builtins']);
    }

    /**
     * allocate_unique_slug() avoids colliding with an already-registered slug.
     */
    public function test_allocate_unique_slug(): void {
        $this->resetAfterTest();

        $this->assertSame('my-style', styles_service::allocate_unique_slug('My Style'));

        styles_service::save_registry(['uploaded' => ['my-style' => []], 'disabled_builtins' => []]);
        $this->assertNotSame('my-style', styles_service::allocate_unique_slug('My Style'));
    }

    /**
     * The storage path and public URL builders use the normalised slug.
     */
    public function test_path_builders(): void {
        $this->assertStringEndsWith('/mod_exelearning/styles', styles_service::get_storage_dir());
        $this->assertStringEndsWith('/mod_exelearning/styles/my-style', styles_service::get_style_dir('My Style'));
        $this->assertStringContainsString(
            '/mod/exelearning/editor/styles.php/my-style',
            styles_service::get_style_url('My Style')
        );
    }

    /**
     * set_uploaded_enabled() toggles the flag and reports unknown slugs.
     */
    public function test_set_uploaded_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        styles_service::install_from_zip($this->make_style_zip('Theme A'), 'a.zip');

        $this->assertTrue(styles_service::set_uploaded_enabled('theme-a', false));
        $this->assertFalse(styles_service::get_registry()['uploaded']['theme-a']['enabled']);
        $this->assertTrue(styles_service::set_uploaded_enabled('theme-a', true));
        $this->assertTrue(styles_service::get_registry()['uploaded']['theme-a']['enabled']);

        $this->assertFalse(styles_service::set_uploaded_enabled('does-not-exist', false));
    }

    /**
     * set_builtin_enabled() adds/removes the id from disabled_builtins.
     */
    public function test_set_builtin_enabled(): void {
        $this->resetAfterTest();

        styles_service::set_builtin_enabled('intef', false);
        $this->assertContains('intef', styles_service::get_registry()['disabled_builtins']);

        styles_service::set_builtin_enabled('intef', true);
        $this->assertNotContains('intef', styles_service::get_registry()['disabled_builtins']);
    }

    /**
     * delete_uploaded() removes the registry entry and the extracted files.
     */
    public function test_delete_uploaded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        styles_service::install_from_zip($this->make_style_zip('Theme B'), 'b.zip');
        $dir = styles_service::get_style_dir('theme-b');
        $this->assertDirectoryExists($dir);

        $this->assertTrue(styles_service::delete_uploaded('theme-b'));
        $this->assertArrayNotHasKey('theme-b', styles_service::get_registry()['uploaded']);
        $this->assertDirectoryDoesNotExist($dir);

        $this->assertFalse(styles_service::delete_uploaded('does-not-exist'));
    }

    /**
     * is_import_blocked() defaults to false and follows the admin setting.
     */
    public function test_is_import_blocked(): void {
        $this->resetAfterTest();

        $this->assertFalse(styles_service::is_import_blocked());
        set_config('stylesblockimport', 1, 'exelearning');
        $this->assertTrue(styles_service::is_import_blocked());
    }

    /**
     * build_theme_registry_override() lists enabled uploaded styles and omits
     * disabled ones.
     */
    public function test_build_theme_registry_override(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        styles_service::install_from_zip($this->make_style_zip('Theme C'), 'c.zip');

        $override = styles_service::build_theme_registry_override();
        $this->assertArrayHasKey('disabledBuiltins', $override);
        $this->assertArrayHasKey('blockImportInstall', $override);
        $this->assertSame('base', $override['fallbackTheme']);
        $this->assertContains('theme-c', array_column($override['uploaded'], 'id'));

        // A disabled style drops out of the override.
        styles_service::set_uploaded_enabled('theme-c', false);
        $this->assertNotContains(
            'theme-c',
            array_column(styles_service::build_theme_registry_override()['uploaded'], 'id')
        );
    }

    /**
     * validate_zip() rejects an empty file and a non-ZIP file.
     */
    public function test_validate_zip_rejects_bad_archives(): void {
        $this->resetAfterTest();

        $empty = make_temp_directory('mod_exelearning') . '/empty-' . random_string(6) . '.zip';
        file_put_contents($empty, '');
        try {
            styles_service::validate_zip($empty);
            $this->fail('Expected an exception for an empty archive.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $notzip = make_temp_directory('mod_exelearning') . '/bad-' . random_string(6) . '.zip';
        file_put_contents($notzip, 'this is not a zip archive');
        $this->expectException(\moodle_exception::class);
        styles_service::validate_zip($notzip);
    }

    /**
     * validate_zip() requires a config.xml and rejects disallowed file types.
     */
    public function test_validate_zip_rejects_missing_config_and_bad_ext(): void {
        $this->resetAfterTest();

        // No config.xml.
        $noconf = make_temp_directory('mod_exelearning') . '/noconf-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($noconf, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('style.css', 'body{}');
        $zip->close();
        try {
            styles_service::validate_zip($noconf);
            $this->fail('Expected an exception for a missing config.xml.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Disallowed extension.
        $badext = make_temp_directory('mod_exelearning') . '/badext-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($badext, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('config.xml', '<config><name>X</name></config>');
        $zip->addFromString('evil.php', '<?php echo 1;');
        $zip->close();
        $this->expectException(\moodle_exception::class);
        styles_service::validate_zip($badext);
    }

    /**
     * install_from_zip() rejects a package with no CSS file.
     */
    public function test_install_from_zip_requires_css(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $nocss = make_temp_directory('mod_exelearning') . '/nocss-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($nocss, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('config.xml', '<config><name>No CSS</name></config>');
        $zip->addFromString('readme.txt', 'just text');
        $zip->close();

        $this->expectException(\moodle_exception::class);
        styles_service::install_from_zip($nocss, 'nocss.zip');
    }

    /**
     * list_uploaded_files() and list_uploaded_styles() surface an installed style.
     */
    public function test_list_uploaded_files_and_styles(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        styles_service::install_from_zip($this->make_style_zip('Listed'), 'listed.zip');

        $this->assertContains('style.css', styles_service::list_uploaded_files('listed'));
        $this->assertContains('listed', array_column(styles_service::list_uploaded_styles(), 'id'));
    }

    /**
     * get_registry() tolerates a malformed persisted value.
     */
    public function test_get_registry_tolerates_malformed_json(): void {
        $this->resetAfterTest();
        set_config(styles_service::CONFIG_REGISTRY, 'not valid json', 'exelearning');

        $registry = styles_service::get_registry();
        $this->assertSame([], $registry['uploaded']);
        $this->assertSame([], $registry['disabled_builtins']);
    }

    /**
     * validate_zip() rejects an archive over the configured size cap.
     */
    public function test_validate_zip_rejects_oversize(): void {
        $this->resetAfterTest();
        set_config('styles_max_zip_size', 1, 'exelearning');

        $this->expectException(\moodle_exception::class);
        styles_service::validate_zip($this->make_style_zip('Too Big'));
    }

    /**
     * validate_zip() rejects an archive with more than one config.xml.
     */
    public function test_validate_zip_rejects_multiple_config(): void {
        $this->resetAfterTest();
        $zippath = make_temp_directory('mod_exelearning') . '/multi-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('config.xml', '<config><name>A</name></config>');
        $zip->addFromString('sub/config.xml', '<config><name>B</name></config>');
        $zip->addFromString('style.css', 'body{}');
        $zip->close();

        $this->expectException(\moodle_exception::class);
        styles_service::validate_zip($zippath);
    }

    /**
     * A style with an icons/ folder and a subdirectory exercises icon scanning,
     * recursive CSS discovery and recursive deletion.
     */
    public function test_style_with_icons_and_subdir(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $zippath = make_temp_directory('mod_exelearning') . '/rich-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('config.xml', '<config><name>Rich</name></config>');
        $zip->addFromString('style.css', 'body{}');
        $zip->addFromString('sub/extra.css', '.x{}');
        $zip->addFromString('icons/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $zip->close();

        styles_service::install_from_zip($zippath, 'rich.zip');

        // The registry override scans the icons/ folder.
        $override = styles_service::build_theme_registry_override();
        $entry = null;
        foreach ($override['uploaded'] as $u) {
            if ($u['id'] === 'rich') {
                $entry = $u;
            }
        }
        $this->assertNotNull($entry);
        $this->assertNotEmpty($entry['icons']);

        // Deletion recurses into icons/ and sub/.
        $this->assertTrue(styles_service::delete_uploaded('rich'));
        $this->assertDirectoryDoesNotExist(styles_service::get_style_dir('rich'));
    }

    /**
     * validate_zip() rejects an archive whose files do not share the config.xml
     * root directory.
     */
    public function test_validate_zip_rejects_mixed_roots(): void {
        $this->resetAfterTest();
        $zippath = make_temp_directory('mod_exelearning') . '/mixed-' . random_string(6) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('theme/config.xml', '<config><name>X</name></config>');
        $zip->addFromString('rogue.css', 'body{}');
        $zip->close();

        $this->expectException(\moodle_exception::class);
        styles_service::validate_zip($zippath);
    }

    /**
     * list_builtin_themes() parses the active editor's data/bundle.json manifest.
     */
    public function test_list_builtin_themes_from_bundle(): void {
        global $CFG;
        $this->resetAfterTest();

        // Point the resolver at a bundled editor carrying a themes manifest (DEC-106-01).
        $src = make_temp_directory('mod_exelearning/bt-' . random_string(6)) . '/static';
        make_writable_directory($src . '/app');
        make_writable_directory($src . '/data');
        file_put_contents($src . '/index.html', 'x');
        file_put_contents($src . '/data/bundle.json', json_encode([
            'themes' => ['themes' => [
                ['name' => 'intef', 'title' => 'INTEF', 'version' => '1.0'],
                ['name' => 'base', 'title' => 'Base'],
            ]],
        ]));
        $CFG->mod_exelearning_bundled_editor_dir = $src;

        $ids = array_column(styles_service::list_builtin_themes(), 'id');
        $this->assertContains('intef', $ids);
        $this->assertContains('base', $ids);
    }
}
