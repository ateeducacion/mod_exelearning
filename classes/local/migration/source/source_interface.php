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
 * Read-only contract over a legacy sibling plugin (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

/**
 * Treats a legacy plugin (mod_exeweb / mod_exescorm) as a read-only source of
 * eXeLearning packages.
 *
 * Instance-based (not static) so tests can stub a source with hand-built rows and
 * exercise the orchestrator without the sibling plugin installed (the siblings are
 * absent in CI).
 */
interface source_interface {
    /**
     * The bare module name, e.g. 'exeweb' or 'exescorm'.
     *
     * @return string
     */
    public function get_module_name(): string;

    /**
     * The frankenstyle component, e.g. 'mod_exeweb' or 'mod_exescorm'.
     *
     * @return string
     */
    public function get_component(): string;

    /**
     * Whether the sibling plugin is installed on this site (directory + main table).
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Lists every source activity site-wide.
     *
     * Each returned row carries: cmid, course, coursename, sectionnum, instanceid,
     * contextid, name, intro, introformat, migrationid (null when not yet migrated),
     * the cm metadata columns (cmvisible, cmvisibleoncoursepage, cmgroupmode,
     * cmgroupingid, cmavailability, cmlang, cmcompletion, cmcompletionview,
     * cmcompletionexpected, cmcompletiongradeitemnumber, cmcompletionpassgrade) and
     * sibling extras (exeweb: revision; exescorm: exescormtype, reference).
     *
     * @return \stdClass[]
     */
    public function list_sources(): array;

    /**
     * Cheap verdict on whether a source is migratable. Never extracts and never
     * throws: I/O problems are downgraded to a nosource classification.
     *
     * @param \stdClass $source A row from list_sources().
     * @return classification
     */
    public function classify(\stdClass $source): classification;

    /**
     * Resolves a readable .elpx temp path for a source, or null when none. May extract.
     *
     * @param \stdClass $source A row from list_sources().
     * @return string|null Absolute path to a temporary .elpx, or null.
     */
    public function resolve_elpx(\stdClass $source): ?string;

    /**
     * The grade model the target eXeLearning activity should use for this source.
     *
     * @return int EXELEARNING_GRADEMODEL_PERITEM or EXELEARNING_GRADEMODEL_OVERALL.
     */
    public function get_target_grademodel(): int;

    /**
     * Whether source grades must be copied to the target's overall grade item.
     *
     * @return bool
     */
    public function needs_grade_migration(): bool;
}
