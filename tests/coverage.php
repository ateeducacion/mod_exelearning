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
 * Coverage information for mod_exelearning.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Scopes code coverage to the plugin's own logic — the domain classes plus the
 * public API in lib.php — so `phpunit --coverage-*` reports a meaningful figure
 * focused on testable units (not the thin entry-point scripts or the bundled
 * editor build).
 */
return new class extends phpunit_coverage_info {
    /** @var array Folders relative to the plugin root to include in coverage. */
    protected $includelistfolders = [
        'classes',
    ];

    /** @var array Files relative to the plugin root to include in coverage. */
    protected $includelistfiles = [
        'lib.php',
    ];

    /**
     * @var array Folders to exclude from coverage. The admin setting classes are
     * thin Moodle admin-UI rendering adapters (output_html), not plugin logic, so
     * they are scoped out to keep the figure focused on testable behaviour.
     */
    protected $excludelistfolders = [
        'classes/admin',
        // Test infrastructure, not plugin logic: Moodle adds the generator to the
        // default coverage list, but it should not count toward the figure.
        'tests/generator',
    ];

    /**
     * @var array Files excluded from coverage. Nothing is excluded by file
     * (DEC-0048: testable code must stay in scope).
     */
    protected $excludelistfiles = [];
};
