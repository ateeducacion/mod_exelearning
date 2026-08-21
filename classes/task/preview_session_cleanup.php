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

namespace mod_exelearning\task;

use mod_exelearning\local\preview\snapshot_store;

/**
 * Scheduled task: reap idle-expired editor preview snapshots.
 *
 * The snapshot store keeps ephemeral, file-backed trees under the Moodle temp
 * directory. They are also swept opportunistically on every replace, but a
 * snapshot that is created and then never touched again is only reclaimed here.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_session_cleanup extends \core\task\scheduled_task {
    /**
     * The human-readable task name shown in the scheduled tasks admin report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('previewsessioncleanup', 'mod_exelearning');
    }

    /**
     * Sweep every idle-expired preview session.
     */
    public function execute(): void {
        $swept = snapshot_store::sweep_expired();
        if ($swept > 0) {
            mtrace('mod_exelearning: swept ' . $swept . ' expired preview session(s).');
        }
    }
}
