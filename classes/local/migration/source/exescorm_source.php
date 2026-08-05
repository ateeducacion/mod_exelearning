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
 * Read-only migration source for mod_exescorm (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

/**
 * Treats mod_exescorm activities as read-only sources of eXeLearning packages.
 *
 * The package lives at itemid 0. The stored file can itself BE an .elpx (embedded
 * editor flow: mod_exescorm skips SCORM parsing when its reference ends in .elpx,
 * see mod_exescorm/lib.php), or a SCORM zip that may embed one or more .elpx. Types
 * that keep no static local package — external SCORM / AICC URLs and synchronized
 * (localsync) sources, whose snapshot keeps re-syncing from a URL — are unsupported.
 */
final class exescorm_source implements source_interface {
    /**
     * External SCORM URL type: nothing is stored locally to migrate.
     *
     * Mirrors EXESCORM_TYPE_EXTERNAL (mod_exescorm/lib.php); duplicated here because
     * the sibling plugin may not be installed when this class is loaded.
     *
     * @var string
     */
    private const TYPE_EXTERNAL = 'external';

    /**
     * External AICC URL type: nothing is stored locally to migrate.
     *
     * Mirrors EXESCORM_TYPE_AICCURL (mod_exescorm/lib.php).
     *
     * @var string
     */
    private const TYPE_AICCURL = 'aiccurl';

    /**
     * Periodically-synced package type: although mod_exescorm keeps a local snapshot
     * at package/0, the activity stays in sync with an external URL, so migrating it
     * to a static eXeLearning snapshot would break that relationship. Treated as
     * non-migratable (DEC-13-12). Mirrors EXESCORM_TYPE_LOCALSYNC (mod_exescorm/lib.php).
     *
     * @var string
     */
    private const TYPE_LOCALSYNC = 'localsync';

    /**
     * The bare module name.
     *
     * @return string
     */
    public function get_module_name(): string {
        return 'exescorm';
    }

    /**
     * The frankenstyle component.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod_exescorm';
    }

    /**
     * Whether mod_exescorm is installed on this site.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $DB;
        $mods = \core_component::get_plugin_list('mod');
        return isset($mods['exescorm']) && $DB->get_manager()->table_exists('exescorm');
    }

    /**
     * Lists every mod_exescorm activity site-wide.
     *
     * @return \stdClass[]
     */
    public function list_sources(): array {
        global $DB;
        $sql = source_query::build('exescorm', 'a.exescormtype, a.reference');
        return array_values($DB->get_records_sql($sql, [
            'moduleid'  => $DB->get_field('modules', 'id', ['name' => 'exescorm'], MUST_EXIST),
            'ctxlevel'  => CONTEXT_MODULE,
            'component' => $this->get_component(),
        ]));
    }

    /**
     * Classifies a source by its exescormtype and the eXeLearning source its
     * stored package carries.
     *
     * External / AICC-URL / synchronized types keep no migratable local snapshot
     * and are unsupported up front. Otherwise the stored package is handed to the
     * shared {@see package_probe}, which is migratable when it holds a root
     * content.xml (a native .elpx, a content.xml zip, or an IMS export) or embeds
     * exactly one .elpx.
     *
     * @param \stdClass $source A row from list_sources().
     * @return classification
     */
    public function classify(\stdClass $source): classification {
        if (in_array($source->exescormtype, [self::TYPE_EXTERNAL, self::TYPE_AICCURL, self::TYPE_LOCALSYNC], true)) {
            // External source (no local package) or a synchronized source (whose local
            // snapshot keeps re-syncing from a URL): nothing we can safely migrate.
            return classification::unsupported();
        }

        $pkg = $this->stored_package((int) $source->contextid);
        if (!$pkg) {
            return classification::nosource();
        }

        return package_probe::classify($pkg);
    }

    /**
     * Resolves a readable package temp path: the package itself, or its single
     * embedded .elpx entry.
     *
     * @param \stdClass $source A row from list_sources().
     * @return string|null
     */
    public function resolve_elpx(\stdClass $source): ?string {
        $verdict = $this->classify($source);
        if (!$verdict->is_ok()) {
            return null;
        }
        $pkg = $this->stored_package((int) $source->contextid);
        if (!$pkg) {
            return null;
        }
        return package_probe::resolve($pkg, $verdict);
    }

    /**
     * The target migrates as a single overall grade (SCORM-style).
     *
     * @return int
     */
    public function get_target_grademodel(): int {
        return EXELEARNING_GRADEMODEL_OVERALL;
    }

    /**
     * mod_exescorm has grades that must be copied to the target's overall item.
     *
     * @return bool
     */
    public function needs_grade_migration(): bool {
        return true;
    }

    /**
     * Returns the stored package file (itemid 0), or null when absent.
     *
     * @param int $contextid Source module context id.
     * @return \stored_file|null
     */
    private function stored_package(int $contextid): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'mod_exescorm', 'package', 0, 'sortorder DESC, id ASC', false);
        $pkg = reset($files);
        return $pkg ?: null;
    }
}
