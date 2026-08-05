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
 * Immutable per-activity migration outcome (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration;

/**
 * Value object describing the result of migrating a single sibling activity.
 *
 * Replaces the loose stdClass returned by the old import_service::migresult().
 * Statuses are exposed as constants so callers and language strings stay in sync.
 */
final class migration_result {
    /** @var string A new eXeLearning activity was created from the source. */
    public const STATUS_MIGRATED = 'migrated';

    /** @var string The source was already migrated in a previous run (skipped). */
    public const STATUS_ALREADYMIGRATED = 'alreadymigrated';

    /** @var string No importable eXeLearning source could be recovered (skipped). */
    public const STATUS_NOSOURCE = 'nosource';

    /** @var string The package embeds more than one .elpx; migrate manually (skipped). */
    public const STATUS_AMBIGUOUSSOURCE = 'ambiguoussource';

    /** @var string The source is externally hosted, so there is nothing to copy (skipped). */
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var string An unexpected error aborted this activity's migration. */
    public const STATUS_ERROR = 'error';

    /** @var string[] Statuses where the source exists but cannot be migrated automatically. */
    public const BLOCKED_STATUSES = [
        self::STATUS_NOSOURCE,
        self::STATUS_AMBIGUOUSSOURCE,
        self::STATUS_UNSUPPORTED,
    ];

    /**
     * Constructor.
     *
     * @param int $sourcecmid Course module id of the source activity.
     * @param int $courseid Course the source (and target) belong to.
     * @param string $coursename Human-readable course name (for the result table).
     * @param string $name Source activity name.
     * @param string $status One of the self::STATUS_* constants.
     * @param string $message Human-readable detail (used for STATUS_ERROR).
     * @param int $targetcmid Created eXeLearning course module id (when migrated).
     */
    public function __construct(
        /** @var int Course module id of the source activity. */
        public readonly int $sourcecmid,
        /** @var int Course the source (and target) belong to. */
        public readonly int $courseid,
        /** @var string Human-readable course name. */
        public readonly string $coursename,
        /** @var string Source activity name. */
        public readonly string $name,
        /** @var string One of the self::STATUS_* constants. */
        public readonly string $status,
        /** @var string Human-readable detail (used for STATUS_ERROR). */
        public readonly string $message = '',
        /** @var int Created eXeLearning course module id (when migrated). */
        public readonly int $targetcmid = 0,
    ) {
    }

    /**
     * Builds a result from a source row produced by source_interface::list_sources().
     *
     * @param \stdClass $source Source row (needs cmid, course, coursename, name).
     * @param string $status One of the self::STATUS_* constants.
     * @param string $message Human-readable detail (used for STATUS_ERROR).
     * @param int $targetcmid Created eXeLearning course module id (when migrated).
     * @return self
     */
    public static function from_source(
        \stdClass $source,
        string $status,
        string $message = '',
        int $targetcmid = 0
    ): self {
        return new self(
            (int) $source->cmid,
            (int) $source->course,
            (string) ($source->coursename ?? ''),
            (string) $source->name,
            $status,
            $message,
            $targetcmid
        );
    }

    /**
     * Whether this result is a blocked status (source exists but is not migratable).
     *
     * @return bool
     */
    public function is_blocked(): bool {
        return in_array($this->status, self::BLOCKED_STATUSES, true);
    }
}
