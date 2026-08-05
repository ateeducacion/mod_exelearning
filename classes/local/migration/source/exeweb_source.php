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
 * Read-only migration source for mod_exeweb (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

/**
 * Treats mod_exeweb activities as read-only sources of eXeLearning packages.
 *
 * mod_exeweb stores its package at itemid = {exeweb}.revision (see
 * mod_exeweb/classes/exeweb_package.php save_draft_file(), which calls
 * file_save_draft_area_files(..., 'package', $data->revision, ...)) and wipes the
 * filearea on each save, so at most one file exists at a nonzero itemid. The old
 * import_service read itemid 0 unconditionally and reported every real exeweb
 * activity as nosource; this handler fixes that, with a fallback scan for revision
 * drift (e.g. restored backups).
 *
 * Once located, the stored package is handed to the shared {@see package_probe} so
 * migratability is decided by content (a recoverable eXeLearning content.xml)
 * rather than assumed: a legacy package without an ODE source is reported nosource
 * instead of being created as a degraded activity.
 */
final class exeweb_source implements source_interface {
    /**
     * The bare module name.
     *
     * @return string
     */
    public function get_module_name(): string {
        return 'exeweb';
    }

    /**
     * The frankenstyle component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod_exeweb';
    }

    /**
     * Whether mod_exeweb is installed on this site.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $DB;
        $mods = \core_component::get_plugin_list('mod');
        return isset($mods['exeweb']) && $DB->get_manager()->table_exists('exeweb');
    }

    /**
     * Lists every mod_exeweb activity site-wide.
     *
     * @return \stdClass[]
     */
    public function list_sources(): array {
        global $DB;
        $sql = source_query::build('exeweb', 'a.revision');
        return array_values($DB->get_records_sql($sql, [
            'moduleid'  => $DB->get_field('modules', 'id', ['name' => 'exeweb'], MUST_EXIST),
            'ctxlevel'  => CONTEXT_MODULE,
            'component' => $this->get_component(),
        ]));
    }

    /**
     * Classifies a source: locate the stored package, then defer to the shared
     * content-based probe (migratable only when an eXeLearning content.xml is
     * recoverable). The resolved itemid is threaded through so resolve_elpx() does
     * not have to re-derive it.
     *
     * @param \stdClass $source A row from list_sources().
     * @return classification
     */
    public function classify(\stdClass $source): classification {
        [$pkg, $itemid] = $this->locate_package($source);
        if (!$pkg) {
            return classification::nosource();
        }
        return package_probe::classify($pkg, $itemid);
    }

    /**
     * Resolves the package (or its embedded .elpx) to a temporary path.
     *
     * @param \stdClass $source A row from list_sources().
     * @return string|null
     */
    public function resolve_elpx(\stdClass $source): ?string {
        [$pkg, $itemid] = $this->locate_package($source);
        if (!$pkg) {
            return null;
        }
        return package_probe::resolve($pkg, package_probe::classify($pkg, $itemid));
    }

    /**
     * Locates the stored mod_exeweb package and the itemid it lives at.
     *
     * mod_exeweb stores the package at itemid = {exeweb}.revision and wipes the
     * filearea on each save, so the documented location is tried first and a
     * filearea scan (newest itemid wins) covers revision drift, e.g. restored
     * backups.
     *
     * @param \stdClass $source A row from list_sources().
     * @return array{0:\stored_file|null,1:int} The package file (or null) and its itemid.
     */
    private function locate_package(\stdClass $source): array {
        $fs = get_file_storage();
        // Primary: the documented location, itemid = {exeweb}.revision.
        $files = $fs->get_area_files(
            (int) $source->contextid,
            'mod_exeweb',
            'package',
            (int) $source->revision,
            'id ASC',
            false
        );
        if ($files) {
            return [reset($files), (int) $source->revision];
        }
        // Fallback: scan every itemid (covers revision drift, e.g. restored backups).
        $all = $fs->get_area_files(
            (int) $source->contextid,
            'mod_exeweb',
            'package',
            false,
            'itemid DESC, id ASC',
            false
        );
        if (!$all) {
            return [null, 0];
        }
        // The filearea is wiped on each save, so >1 file means drift: newest itemid wins.
        $pkg = reset($all);
        return [$pkg, (int) $pkg->get_itemid()];
    }

    /**
     * The target uses the default per-iDevice model (exeweb has no grades, DEC-0-08).
     *
     * @return int
     */
    public function get_target_grademodel(): int {
        return EXELEARNING_GRADEMODEL_PERITEM;
    }

    /**
     * mod_exeweb has no grades, so nothing to migrate.
     *
     * @return bool
     */
    public function needs_grade_migration(): bool {
        return false;
    }
}
