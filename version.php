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
 * mod_exelearning version information.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Real, monotonic Moodle version (YYYYMMDDXX). Never a sentinel: this value is
// part of Moodle's install/upgrade protocol and ships exactly as committed —
// packaging validates it but never rewrites it. Increment it whenever Moodle
// must detect a change (db/, classes/, JS source or builds, settings, language
// strings, tasks, capabilities, external services, other cache-sensitive
// metadata), and keep it strictly greater than the latest published version and
// every upgrade_mod_savepoint() in db/upgrade.php. The development marker lives
// in $plugin->release ('dev'); a release-preparation PR commits the final
// version + semver release BEFORE the tag is created (see DEVELOPMENT.md,
// "Versioning and releases").
$plugin->version   = 2026080700;
$plugin->release   = '4.0.3';
$plugin->requires  = 2024100700;       // Moodle 4.5 LTS+.
$plugin->supported = [405, 502];       // Moodle 4.5 LTS through Moodle 5.2.
$plugin->component = 'mod_exelearning';
$plugin->maturity  = MATURITY_STABLE;
