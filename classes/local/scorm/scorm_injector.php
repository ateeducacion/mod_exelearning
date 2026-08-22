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
     * Injects SCORM wrapper script tags into the <head> of index.html and all
     * html/<slug>.html pages of the extracted package.
     *
     * @param int $contextid
     * @param int $revision
     */
    public static function inject(int $contextid, int $revision): void {
        $fs = get_file_storage();
        $marker = '<!-- mod_exelearning:scorm-loader -->';
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
        $tags = $marker .
                "\n    <script src=\"libs/SCORM_API_wrapper.js\"></script>" .
                "\n    <script src=\"libs/SCOFunctions.js\"></script>" .
                $initscript;
        $tagshtml = $marker .
                "\n    <script src=\"../libs/SCORM_API_wrapper.js\"></script>" .
                "\n    <script src=\"../libs/SCOFunctions.js\"></script>" .
                $initscript;

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
            if ($html === '' || strpos($html, $marker) !== false) {
                continue;
            }
            $path = $file->get_filepath();
            $payload = ($path === '/') ? $tags : $tagshtml;
            // Drop any runtime the package brought its own script tags for. An
            // eXeLearning SCORM 1.2 export references these two files itself, and this
            // plugin accepts such a package, so without this the page loads the runtime
            // TWICE — package_manager has already replaced the files with the plugin's
            // own, so both tags point at the same bytes and the whole runtime is parsed
            // and executed a second time. One runtime, once, is the contract.
            $html = preg_replace(
                '~[ \t]*<script\b[^>]*\bsrc\s*=\s*"[^"]*(?:SCORM_API_wrapper|SCOFunctions)\.js"[^>]*>'
                    . '\s*</script>[ \t]*\r?\n?~i',
                '',
                $html
            ) ?? $html;
            // Insert just before </head> (case-insensitive).
            $newhtml = preg_replace('~</head>~i', $payload . '</head>', $html, 1);
            if ($newhtml === null || $newhtml === $html) {
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
