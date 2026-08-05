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
 * Embedded editor source resolver for mod_exelearning.
 *
 * @package    mod_exelearning
 * @copyright  2025 eXeLearning
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local;

/**
 * Resolves whether the bundled embedded editor is usable at runtime.
 *
 * The editor is a release artifact (DEC-106-01): every official release ZIP ships
 * it pre-built under dist/static/, and that is the only source the plugin ever
 * serves. There is no runtime installer and no moodledata copy any more — a
 * leftover moodledata/mod_exelearning/embedded_editor directory from the removed
 * installer era is deliberately ignored. When the bundle is absent or fails
 * validation (a source checkout without `make build-editor`, a broken unpack),
 * embedded editing is simply unavailable: the edit button is not offered and the
 * editor endpoints answer 404.
 */
class embedded_editor_source_resolver {
    /**
     * Directories expected in a valid static editor bundle.
     * At least one must exist alongside index.html.
     *
     * @var string[]
     */
    const EXPECTED_ASSET_DIRS = ['app', 'libs', 'files'];

    /**
     * Get the bundled editor directory inside the plugin.
     *
     * Unit tests may point this elsewhere via
     * $CFG->mod_exelearning_bundled_editor_dir (honoured only under PHPUnit),
     * because dist/static/ lives in dirroot and is absent from CI checkouts.
     *
     * @return string Absolute path.
     */
    public static function get_bundled_dir(): string {
        global $CFG;
        if (
            defined('PHPUNIT_TEST') && PHPUNIT_TEST
            && !empty($CFG->mod_exelearning_bundled_editor_dir)
        ) {
            return $CFG->mod_exelearning_bundled_editor_dir;
        }
        return $CFG->dirroot . '/mod/exelearning/dist/static';
    }

    /**
     * Validate that a directory contains a usable static editor installation.
     *
     * Checks that index.html exists and is readable, and that at least one
     * of the expected asset directories (app, libs, files) is present.
     *
     * @param string $dir Absolute path to the editor directory.
     * @return bool True if the directory passes integrity checks.
     */
    public static function validate_editor_dir(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        $indexpath = rtrim($dir, '/') . '/index.html';
        if (!is_file($indexpath) || !is_readable($indexpath)) {
            return false;
        }

        // At least one expected asset directory must exist.
        $dir = rtrim($dir, '/');
        foreach (self::EXPECTED_ASSET_DIRS as $assetdir) {
            if (is_dir($dir . '/' . $assetdir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the bundled editor is present and valid.
     *
     * @return bool True when embedded editing can be offered.
     */
    public static function is_available(): bool {
        return self::validate_editor_dir(self::get_bundled_dir());
    }

    /**
     * Get the filesystem path of the bundled editor, if usable.
     *
     * @return string|null Absolute path to the editor directory, or null when
     *                     the bundle is absent or invalid.
     */
    public static function get_editor_dir(): ?string {
        return self::is_available() ? self::get_bundled_dir() : null;
    }

    /**
     * Get the path of the editor index HTML, if usable.
     *
     * @return string|null Path to index.html, or null when no editor is available.
     */
    public static function get_index_source(): ?string {
        $dir = self::get_editor_dir();
        return ($dir !== null) ? $dir . '/index.html' : null;
    }
}
