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
 * Content-based detection of a migratable eXeLearning source inside a stored
 * legacy package (issue #13 #3, DEC-13-12).
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_exelearning\local\migration\source;

use mod_exelearning\local\zip_utils;

/**
 * Decides whether a stored legacy package carries a migratable eXeLearning source
 * and resolves it to a temporary path for installation.
 *
 * The migratability marker is an eXeLearning ODE 2.0 `content.xml`. A package is
 * migratable when:
 *  - `content.xml` sits at the archive root — a native `.elpx`, a content.xml
 *    `.zip`, an IMS Content Package, or an eXeLearning web export that bundles its
 *    source (resolved by installing the whole archive); or
 *  - the archive embeds exactly one safe `.elpx` — an eXeLearning SCORM export
 *    wrapping its editable source (resolved by extracting only that entry).
 *
 * Everything else is not migratable: legacy `.elp` (which carries `contentv3.xml`,
 * not `content.xml`), a source-less SCORM package, a plain web export with no
 * bundled source, more than one embedded `.elpx` (ambiguous), or a corrupt/
 * unreadable archive. The caller leaves the legacy activity untouched — the
 * migration never deletes the source, so a skipped package loses no data.
 *
 * Both source handlers (mod_exeweb, mod_exescorm) share this single detector so
 * the rule lives in exactly one place. Detection reads only the ZIP central
 * directory (no extraction), keeping the preflight pass cheap.
 */
final class package_probe {
    /**
     * The ODE 2.0 source marker expected at the archive root.
     *
     * Mirrors {@see \mod_exelearning\local\package_manager::validate_content_xml()},
     * the same root-level `content.xml` test the upload form uses to accept a package.
     *
     * @var string
     */
    private const CONTENT_XML = 'content.xml';

    /**
     * Classifies a stored package by the eXeLearning source it carries.
     *
     * Never extracts and never throws: an unreadable archive is downgraded to a
     * nosource classification, matching the source_interface contract.
     *
     * @param \stored_file $pkg The stored legacy package (any zip/.elpx).
     * @param int|null $itemid Resolved package itemid, threaded back into the
     *                         classification for the mod_exeweb revision fallback.
     * @return classification
     */
    public static function classify(\stored_file $pkg, ?int $itemid = null): classification {
        // Read only the central directory (preflight-cheap): no extraction.
        $entries = $pkg->list_files(get_file_packer('application/zip'));
        if (!is_array($entries)) {
            // Corrupt or unreadable archive: nothing we can recover.
            return classification::nosource();
        }

        $elpx = [];
        foreach ($entries as $entry) {
            if (!empty($entry->is_directory)) {
                continue;
            }
            // A content.xml at the archive root is the genuine ODE 2.0 marker and
            // takes precedence: the whole archive is installable as-is, exactly
            // like a native .elpx (which is itself a zip with content.xml at root).
            if ($entry->pathname === self::CONTENT_XML) {
                return classification::ok(null, $itemid);
            }
            if (str_ends_with(strtolower($entry->pathname), '.elpx')) {
                // The entry name is attacker-influenced (an uploaded SCORM zip can
                // embed an .elpx under a path-traversal / absolute / backslash /
                // stream-wrapper name). Drop any unsafe entry so it is never
                // selected for extraction; an otherwise-fine package then degrades
                // to nosource, exactly as if it carried no usable .elpx at all.
                if (zip_utils::is_unsafe_zip_entry($entry->pathname)) {
                    continue;
                }
                $elpx[] = $entry->pathname;
            }
        }

        return match (count($elpx)) {
            0 => classification::nosource(),
            1 => classification::ok($elpx[0], $itemid),
            default => classification::ambiguoussource(),
        };
    }

    /**
     * Resolves a classified package to a readable temporary path.
     *
     * Returns null when the verdict is not migratable. For a root-content.xml
     * package the whole archive is copied out verbatim (install_package() extracts
     * and validates it downstream). For an embedded .elpx only that single entry is
     * extracted, with the same path-traversal / symlink defences the rest of the
     * plugin uses.
     *
     * @param \stored_file $pkg The stored legacy package.
     * @param classification $verdict The verdict returned by classify().
     * @return string|null Absolute path to a temporary package, or null.
     */
    public static function resolve(\stored_file $pkg, classification $verdict): ?string {
        if (!$verdict->is_ok()) {
            return null;
        }
        $tmpdir = make_request_directory();
        if ($verdict->elpxentry === null) {
            // Direct package (native .elpx or content.xml-bearing zip): copy it out
            // verbatim. install_package() extracts and validates it downstream.
            $tmp = $tmpdir . '/source.elpx';
            $pkg->copy_content_to($tmp);
            return $tmp;
        }
        // Defence in depth: classify() already drops unsafe entries, but re-check
        // here so resolve() never extracts a hostile name even if reached directly.
        if (zip_utils::is_unsafe_zip_entry($verdict->elpxentry)) {
            return null;
        }
        // Extract ONLY the embedded entry, not the whole archive. The packer drops
        // the $onlyfiles filter when handed a stored_file, so copy the archive out
        // first and extract from the path (cheap: one small entry).
        $ziptmp = $tmpdir . '/package.zip';
        $pkg->copy_content_to($ziptmp);
        get_file_packer('application/zip')->extract_to_pathname($ziptmp, $tmpdir, [$verdict->elpxentry]);
        // Verify nothing escaped $tmpdir (no symlinks, every materialised path stays
        // inside it) before trusting the resolved path.
        zip_utils::assert_extraction_contained($tmpdir, 'migrateextractfailed');
        $path = $tmpdir . '/' . $verdict->elpxentry;
        return is_file($path) ? $path : null;
    }
}
