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
        // The plugin manufactures a SCORM session around content that is not a SCORM
        // package, so it has to open that session itself: eXeLearning only does it in its
        // on-click flow, and with isScorm == 1 (auto-save after each question) that flow
        // never runs.
        //
        // `exeScorm12.session.open({ ownsLifecycle: false })` is the runtime's supported
        // entry for a host like this one. It brings the client's own state machine up and
        // applies the entry policy — the runtime holds every LMS write until that policy
        // has run — while leaving the end-of-session handling to the host.
        //
        // Two things it deliberately does NOT do, both measured on a live Moodle:
        //
        // - It does not call `loadPage()`. That is the SCO lifecycle, and this serving
        // model has no SCOs: every page of the export shares one session and one
        // lesson_status, so after page one published `passed`, loadPage() on page two
        // saw a finished SCO and closed the session — page two ran with a dead
        // connection and recorded nothing. And nothing else on the page may call it
        // either: a SCORM 1.2 export arrives with its own SCO entry (`<body
        // onload="loadPage()">` plus the `exe-scorm` class exe_export.js keys on), and
        // the runtime hands the lifecycle to whichever open succeeds FIRST. Rather than
        // race the page's load events, neutralise_sco_entry() removes that entry before
        // the bootstrap goes in, so the bootstrap is the only opener on every page.
        // - It does not settle for `pipwerks.SCORM.init()`. That opens pipwerks'
        // connection while the runtime's own client stays idle, and then every write is
        // refused locally with 301 before it reaches the LMS. The failure is silent from
        // outside: the activity registry holds the learner's score, the entry policy
        // reports as applied, and only `cmi.core.score.raw` staying empty gives it away.
        // Measured: registry scored 1 with score 50, `cmi.core.score.raw` "", no POST.
        //
        // `init()` stays as the fallback for a package whose runtime predates
        // session.open(), and is tried only after the wait for that entry point has had
        // two seconds to fail — the two files load in sequence, so the runtime always
        // arrives after the wrapper.
        $initscript = "\n    <script>\n" .
                "      (function(){\n" .
                "        var opened = false, ticks = 0;\n" .
                "        var t = setInterval(function(){\n" .
                "          ticks++;\n" .
                "          var ns = window.exeScorm12;\n" .
                "          if (!opened && ns && ns.session && typeof ns.session.open === 'function') {\n" .
                "            try { opened = ns.session.open({ ownsLifecycle: false }) === true; } catch(e){}\n" .
                "          } else if (!opened && ticks > 40 && window.pipwerks && window.pipwerks.SCORM) {\n" .
                "            try { window.pipwerks.SCORM.init(); opened = true; } catch(e){}\n" .
                "          }\n" .
                "          if (opened || ticks > 200) { clearInterval(t); }\n" .
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
            // A SCORM export must not open the session as a SCO behind the bootstrap's
            // back (see the comment above and neutralise_sco_entry()); a web export
            // comes back from this untouched.
            $html = self::neutralise_sco_entry($html);
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

    /**
     * Removes the SCO entry of a page exported as SCORM 1.2, so that the injected
     * bootstrap is the only thing that opens the session.
     *
     * An eXeLearning SCORM 1.2 export carries `<body class="exe-export exe-scorm
     * exe-scorm12" onload="loadPage()">`. Both the onload attribute and the `exe-scorm`
     * class — exe_export.js calls window.loadPage() itself whenever the body has it —
     * open the session AS A SCO, `session.open({ ownsLifecycle: true })`, which installs
     * the page lifecycle this serving model must not run (see inject()). The runtime's
     * ownership rule is "the first successful open decides", so leaving that entry in
     * place makes who owns the lifecycle a race between the bootstrap's first tick and
     * the page's own load events. Removing it is deterministic: the page becomes what
     * every other package this plugin serves already is — a web export with no
     * `exe-scorm` switch. DEC-13-11 records why the plugin deliberately does not ADD
     * that class to the pages it serves; this is the same rule applied to a package
     * that arrives with it, and idevice_patch covers the two iDevices that gate their
     * save on it (DEC-105-02).
     *
     * Only the SCO entry is touched: an onload that is exactly `loadPage()` (what the
     * exporter emits; any other handler is left alone) and the `exe-scorm` /
     * `exe-scorm12` class tokens. Every other attribute and class of the body tag —
     * `exe-export`, a global-font class, `lang` — survives in place, a class attribute
     * left empty is dropped, and a page without a SCO entry is returned byte-for-byte.
     *
     * @param string $html A package page.
     * @return string The page with its SCO entry removed.
     */
    public static function neutralise_sco_entry(string $html): string {
        $rewritten = preg_replace_callback(
            '~<body\b[^>]*>~i',
            static function (array $body): string {
                $tag = $body[0];
                $tag = preg_replace('~\s+onload\s*=\s*(["\'])\s*loadPage\(\)\s*;?\s*\1~i', '', $tag) ?? $tag;
                $tag = preg_replace_callback(
                    '~(\s+class\s*=\s*)(["\'])(.*?)\2~is',
                    static function (array $attr): string {
                        $kept = [];
                        foreach (preg_split('~\s+~', trim($attr[3])) ?: [] as $class) {
                            if ($class !== '' && !in_array(strtolower($class), ['exe-scorm', 'exe-scorm12'], true)) {
                                $kept[] = $class;
                            }
                        }
                        return $kept === [] ? '' : $attr[1] . $attr[2] . implode(' ', $kept) . $attr[2];
                    },
                    $tag
                ) ?? $tag;
                return $tag;
            },
            $html,
            1
        );
        return $rewritten ?? $html;
    }
}
