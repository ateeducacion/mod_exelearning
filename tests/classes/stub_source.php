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
 * Configurable source_interface stub for migration tests (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\tests;

use mod_exelearning\local\migration\source\classification;
use mod_exelearning\local\migration\source\source_interface;

/**
 * A source_interface whose behaviour is injected per test.
 *
 * Lets the orchestrator be driven over hand-built rows without either sibling plugin
 * installed: list_sources() returns the given rows, while classify()/resolve_elpx()
 * delegate to optional closures.
 */
class stub_source implements source_interface {
    /**
     * Constructor.
     *
     * @param array $sources Rows returned by list_sources().
     * @param \Closure|null $classifyfn Maps a source row to a classification (default OK).
     * @param \Closure|null $resolvefn Maps a source row to an .elpx path (default null).
     * @param bool $needsgrades Whether grade migration runs.
     * @param int $grademodel Target grade model.
     * @param string $component Source frankenstyle component.
     * @param string $module Source module name.
     */
    public function __construct(
        /** @var array Source rows. */
        private array $sources,
        /** @var \Closure|null Classification provider. */
        private ?\Closure $classifyfn = null,
        /** @var \Closure|null Path provider. */
        private ?\Closure $resolvefn = null,
        /** @var bool Whether grade migration runs. */
        private bool $needsgrades = false,
        /** @var int Target grade model. */
        private int $grademodel = EXELEARNING_GRADEMODEL_PERITEM,
        /** @var string Source frankenstyle component. */
        private string $component = 'mod_exeweb',
        /** @var string Source module name. */
        private string $module = 'exeweb',
    ) {
    }

    /**
     * The bare module name.
     *
     * @return string
     */
    public function get_module_name(): string {
        return $this->module;
    }

    /**
     * The frankenstyle component.
     *
     * @return string
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * Always available (the stub stands in for an installed sibling).
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * The injected source rows.
     *
     * @return array
     */
    public function list_sources(): array {
        return $this->sources;
    }

    /**
     * Delegates to the injected classifier, defaulting to a migratable verdict.
     *
     * @param \stdClass $source A source row.
     * @return classification
     */
    public function classify(\stdClass $source): classification {
        return $this->classifyfn ? ($this->classifyfn)($source) : classification::ok();
    }

    /**
     * Delegates to the injected resolver, defaulting to null.
     *
     * @param \stdClass $source A source row.
     * @return string|null
     */
    public function resolve_elpx(\stdClass $source): ?string {
        return $this->resolvefn ? ($this->resolvefn)($source) : null;
    }

    /**
     * The configured target grade model.
     *
     * @return int
     */
    public function get_target_grademodel(): int {
        return $this->grademodel;
    }

    /**
     * Whether grade migration runs for this stub.
     *
     * @return bool
     */
    public function needs_grade_migration(): bool {
        return $this->needsgrades;
    }
}
