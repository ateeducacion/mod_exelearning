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
 * Serve an opaque editor-preview snapshot through an expiring capability URL.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_exelearning\local\preview\snapshot_store;

define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

/**
 * Emit hardened headers and stop with a 404 response.
 *
 * @return never
 */
function exelearning_preview_not_found(): void {
    foreach (snapshot_store::response_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(404);
    echo 'Not found';
    exit;
}

$argument = ltrim((string) get_file_argument(), '/');
$slash = strpos($argument, '/');
$previewid = $slash === false ? $argument : substr($argument, 0, $slash);
$path = $slash === false ? 'index.html' : substr($argument, $slash + 1);

$store = new snapshot_store();
$file = $store->get($previewid, $path);
if ($file === null) {
    exelearning_preview_not_found();
}

foreach (snapshot_store::response_headers() as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Type: ' . $file['mimetype']);
if (snapshot_store::is_scriptable($file['mimetype'])) {
    header('Content-Security-Policy: ' . snapshot_store::content_security_policy());
}
echo $file['content'];
exit;
