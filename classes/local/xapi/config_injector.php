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

namespace mod_exelearning\local\xapi;

/**
 * Hardens the served package's xAPI config (DEC-85-01, RIE-013).
 *
 * The upstream emitter (`exe_xapi.js`) reads `window.exeXapi` and posts statements to
 * `parentOrigin || '*'`. When served by Moodle the host knows its own origin, so this
 * injector pins `parentOrigin` to it and forces `actor: null` (the host attaches the
 * real learner server-side). This restricts the postMessage target to Moodle — defense
 * in depth even though the receiving `js/xapi_listener.js` already validates
 * `event.origin` and the emitter strips PII when broadcasting to `'*'`.
 *
 * The merge script is spliced in *immediately before* the `xapi/exe_xapi.js` <script>
 * tag (so it runs after the export's own `window.exeXapi={…}` assignment and before the
 * emitter initialises). It is a no-op for legacy packages that bundle no emitter, and
 * idempotent (guarded by a marker comment). Mirrors {@see \mod_exelearning\local\scorm\scorm_injector}.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_injector {
    /**
     * Splices the parentOrigin/actor merge script before the emitter in every HTML page.
     *
     * @param int    $contextid  The activity module context id.
     * @param int    $revision   The content filearea revision (itemid).
     * @param string $hostorigin The trusted host origin (scheme://host[:port]).
     * @return void
     */
    public static function inject(int $contextid, int $revision, string $hostorigin): void {
        $fs = get_file_storage();
        $marker = '<!-- mod_exelearning:xapi-config -->';
        // JSON-encoding keeps the origin safe inside a <script> (escapes </, quotes, ...).
        $origin = json_encode($hostorigin, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $merge = $marker . '<script>window.exeXapi=Object.assign(window.exeXapi||{},'
                . '{parentOrigin:' . $origin . ',actor:null});</script>' . "\n";

        $files = $fs->get_area_files(
            $contextid,
            'mod_exelearning',
            'content',
            $revision,
            'filepath, filename',
            false
        );
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $name = $file->get_filename();
            if (!preg_match('~\.html?$~i', $name)) {
                continue;
            }
            $html = $file->get_content();
            if ($html === '' || strpos($html, $marker) !== false) {
                continue;
            }
            // Find the emitter's own <script src="…/xapi/exe_xapi.js"></script>; a
            // package without it (legacy export) is left untouched.
            if (
                !preg_match(
                    '~<script[^>]*\ssrc="[^"]*xapi/exe_xapi\.js"[^>]*>\s*</script>~i',
                    $html,
                    $m,
                    PREG_OFFSET_CAPTURE
                )
            ) {
                continue;
            }
            // Use substr_replace (not preg_replace) so the merge script — which holds
            // escaped slashes from the JSON origin — is inserted verbatim.
            $newhtml = substr_replace($html, $merge, (int) $m[0][1], 0);
            if ($newhtml === $html) {
                continue;
            }
            $record = [
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => $revision,
                'filepath'  => $file->get_filepath(),
                'filename'  => $name,
            ];
            $file->delete();
            $fs->create_file_from_string($record, $newhtml);
        }
    }
}
