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

$plugin->version   = 2026070701;       // Latest release +1; `make package` stamps the final YYYYMMDDXX (DEC-0030).
$plugin->release   = 'dev';            // Replaced with the git tag (semver) at package time.
$plugin->requires  = 2024100700;       // Moodle 4.5 LTS+.
$plugin->supported = [405, 502];       // Moodle 4.5 LTS through Moodle 5.2.
$plugin->component = 'mod_exelearning';
$plugin->cron      = 0;
$plugin->maturity  = MATURITY_STABLE;
