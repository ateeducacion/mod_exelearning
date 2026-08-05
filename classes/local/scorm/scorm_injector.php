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
 * SCORM wrapper loader injection for extracted eXeLearning packages.
 *
 * Extracted verbatim from lib.php (DEC-71-01). The HTML mutation logic is
 * unchanged; lib.php keeps a thin delegator. See the known-debt note in
 * docs/ARCHITECTURE.md and DEC-34-02/DEC-36-01 for the planned exit.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\scorm;

/**
 * Injects the SCORM wrapper script tags into the package HTML at extraction time.
 */
final class scorm_injector {
    /**
     * Injects the SCORM client script tags into the <head> of index.html and all
     * html/<slug>.html pages of the extracted package.
     *
     * Two independent, idempotent blocks are injected:
     *  - The bridge client (libs/scorm_tracker.js + libs/exe_scorm_bridge.js) at the
     *    TOP of <head>, so its in-memory storage polyfill and local window.API are in
     *    place before any package script runs. It self-activates only in the secure
     *    opaque-origin iframe mode and is dormant otherwise (DEC-80-01).
     *  - The pipwerks SCORM wrapper (libs/SCORM_API_wrapper.js + libs/SCOFunctions.js)
     *    plus an init kick, just before </head>, used by both iframe modes.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function inject(int $contextid, int $revision): void {
        $fs = get_file_storage();
        $marker = '<!-- mod_exelearning:scorm-loader -->';
        $bridgemarker = '<!-- mod_exelearning:scorm-bridge -->';
        // After loading the wrapper, force `pipwerks.SCORM.init()` so that
        // connection.isActive=true and subsequent `set()` calls DO reach
        // window.parent.API.LMSSetValue. eXeLearning only invokes init() in the
        // on-click flow; with isScorm==1 (auto-save after each question) it never
        // gets called, so we trigger it here.
        $initscript = "\n    <script>\n" .
                "      (function(){\n" .
                "        var t = setInterval(function(){\n" .
                "          if (window.pipwerks && window.pipwerks.SCORM) {\n" .
                "            clearInterval(t);\n" .
                "            try { window.pipwerks.SCORM.init(); } catch(e){}\n" .
                "          }\n" .
                "        }, 50);\n" .
                "      })();\n" .
                "    </script>\n";
        $embedmarker = '<!-- mod_exelearning:embed-shim -->';

        // Iterate over all HTML files in the filearea.
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
            if ($html === '') {
                continue;
            }
            // Relative prefix to the package's libs/ dir: root pages use 'libs/', nested
            // html/<slug>.html pages climb one level with '../libs/'.
            $path = $file->get_filepath();
            $libs = ($path === '/') ? 'libs/' : '../libs/';
            $newhtml = $html;
            $changed = false;

            // Script payloads, built once per file from $libs. The bridge client
            // (scorm_tracker.js before exe_scorm_bridge.js: the shim calls
            // window.exeScormTracker.createScormApi) and the external-embed shim both go
            // at the TOP of <head>; the pipwerks SCORM wrapper + init kick go just before
            // </head>. No host list is baked for the embed shim: it promotes any candidate
            // and the parent relay is the authoritative gate (open vs strict, DEC-80-03).
            $bridge = $bridgemarker .
                    "\n    <script src=\"{$libs}scorm_tracker.js\"></script>" .
                    "\n    <script src=\"{$libs}exe_scorm_bridge.js\"></script>\n";
            $embed = $embedmarker .
                    "\n    <script src=\"{$libs}exe_embed_shim.js\"></script>\n";
            $scorm = $marker .
                    "\n    <script src=\"{$libs}SCORM_API_wrapper.js\"></script>" .
                    "\n    <script src=\"{$libs}SCOFunctions.js\"></script>" .
                    $initscript;

            // Idempotent <head> insertions, applied in order so each one matches against
            // the HTML already modified by the previous (e.g. the embed insert sees the
            // bridge-modified <head>). Each fires at most once, guarded by its own marker.
            // Entry = [marker, payload, anchor regex, top?]: top appends the payload AFTER
            // the matched <head> tag, otherwise it is prepended BEFORE the matched </head>.
            $inserts = [
                [$bridgemarker, $bridge, '~<head[^>]*>~i', true],
                [$embedmarker, $embed, '~<head[^>]*>~i', true],
                [$marker, $scorm, '~</head>~i', false],
            ];
            foreach ($inserts as [$mk, $payload, $regex, $top]) {
                if (strpos($newhtml, $mk) !== false) {
                    continue;
                }
                $replacement = $top ? '$0' . $payload : $payload . '$0';
                $replaced = preg_replace($regex, $replacement, $newhtml, 1);
                if ($replaced !== null && $replaced !== $newhtml) {
                    $newhtml = $replaced;
                    $changed = true;
                }
            }

            if (!$changed) {
                continue;
            }
            // Replace content in the filearea: delete and recreate.
            $record = [
                'contextid' => $contextid,
                'component' => 'mod_exelearning',
                'filearea'  => 'content',
                'itemid'    => $revision,
                'filepath'  => $path,
                'filename'  => $name,
            ];
            $file->delete();
            $fs->create_file_from_string($record, $newhtml);
        }
    }
}
