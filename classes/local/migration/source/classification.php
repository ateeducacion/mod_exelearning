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
 * Cheap pre-migration verdict for one source activity (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

use mod_exelearning\local\migration\migration_result;

/**
 * Result of source_interface::classify(): whether a source can be migrated, and
 * the cheaply-resolved details needed to extract it later (without extracting now).
 *
 * Used by the preflight pass to bucket activities and by resolve_elpx() to avoid
 * re-deriving the package location.
 */
final class classification {
    /** @var string The source is migratable. */
    public const OK = 'ok';

    /**
     * Constructor.
     *
     * @param string $status self::OK or a migration_result::STATUS_* blocked value.
     * @param string|null $elpxentry Zip entry path when the .elpx is embedded in a SCORM zip.
     * @param int|null $itemid Resolved package itemid (mod_exeweb revision fallback).
     */
    private function __construct(
        /** @var string self::OK or a blocked migration_result::STATUS_* value. */
        public readonly string $status,
        /** @var string|null Zip entry path when the .elpx is embedded in a SCORM zip. */
        public readonly ?string $elpxentry,
        /** @var int|null Resolved package itemid (mod_exeweb revision fallback). */
        public readonly ?int $itemid,
    ) {
    }

    /**
     * Migratable source.
     *
     * @param string|null $elpxentry Zip entry path when the .elpx is embedded in a SCORM zip.
     * @param int|null $itemid Resolved package itemid (mod_exeweb revision fallback).
     * @return self
     */
    public static function ok(?string $elpxentry = null, ?int $itemid = null): self {
        return new self(self::OK, $elpxentry, $itemid);
    }

    /**
     * No importable eXeLearning source could be recovered.
     *
     * @return self
     */
    public static function nosource(): self {
        return new self(migration_result::STATUS_NOSOURCE, null, null);
    }

    /**
     * The package embeds more than one .elpx; the right one cannot be chosen safely.
     *
     * @return self
     */
    public static function ambiguoussource(): self {
        return new self(migration_result::STATUS_AMBIGUOUSSOURCE, null, null);
    }

    /**
     * The source is externally hosted, so there is no local package to copy.
     *
     * @return self
     */
    public static function unsupported(): self {
        return new self(migration_result::STATUS_UNSUPPORTED, null, null);
    }

    /**
     * Whether the source is migratable.
     *
     * @return bool
     */
    public function is_ok(): bool {
        return $this->status === self::OK;
    }
}
