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

namespace mod_exelearning\local\preview;

use ZipArchive;

/**
 * Decides whether an uploaded preview ZIP is safe to extract.
 *
 * Kept apart from the store on purpose: the store's job is to swap a tree into
 * place atomically, this one's is to refuse an archive before a single byte
 * reaches the disk. These rules are the trust boundary for untrusted author
 * content, so they are worth reading in one place.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class zip_inspector {
    /**
     * Vet every entry of an open ZIP before extraction.
     *
     * The whole archive is checked up front on purpose: extractTo() is all or
     * nothing, so a limit only noticed halfway through would leave a partially
     * written tree behind. Sizes come from the declared *uncompressed* size,
     * which is what a zip bomb inflates to.
     *
     * @param ZipArchive $zip    Open archive.
     * @param array $limits Active limits, keys maxfiles and maxbytes.
     * @return bool|string True when safe to extract, otherwise an error code.
     */
    public static function inspect(ZipArchive $zip, array $limits) {
        if ($zip->numFiles > $limits['maxfiles']) {
            return 'previewtoomanyfiles';
        }
        $total = 0;
        $hasindex = false;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = self::inspect_entry($zip, $index);
            if (is_string($entry)) {
                return $entry;
            }
            if ($entry === null) {
                continue;
            }
            $total += $entry['size'];
            if ($total > $limits['maxbytes']) {
                return 'previewtoolarge';
            }
            $hasindex = $hasindex || $entry['name'] === 'index.html';
        }
        if (!$hasindex) {
            return 'previewmissingindex';
        }
        return true;
    }

    /**
     * Vet a single ZIP entry.
     *
     * @param ZipArchive $zip   Open archive.
     * @param int        $index Entry index.
     * @return array{name:string,size:int}|null|string Entry data, null for a
     *                                                 directory, error code when unsafe.
     */
    private static function inspect_entry(ZipArchive $zip, int $index) {
        $name = $zip->getNameIndex($index);
        $stat = $zip->statIndex($index);
        if (!is_string($name) || !is_array($stat)) {
            return 'invalidpreviewpath';
        }
        $isdir = substr($name, -1) === '/';
        $candidate = $isdir ? rtrim($name, '/') : $name;
        // Same path guard the serving side applies, so an entry that could not be
        // requested back can never be written in the first place.
        if ($candidate === '' || serving::normalize_content_path($candidate) !== $candidate) {
            return 'invalidpreviewpath';
        }
        if ($isdir) {
            return null;
        }
        if (self::is_symlink_entry($zip, $index)) {
            return 'invalidpreviewpath';
        }
        return ['name' => $name, 'size' => (int) ($stat['size'] ?? 0)];
    }

    /**
     * Whether an entry is a Unix symbolic link.
     *
     * A link is stored as a tiny entry whose contents are the target path, so it
     * passes the size and path checks while pointing anywhere on the filesystem.
     *
     * @param ZipArchive $zip   Open archive.
     * @param int        $index Entry index.
     * @return bool
     */
    private static function is_symlink_entry(ZipArchive $zip, int $index): bool {
        $opsys = 0;
        $attributes = 0;
        return $zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && $opsys === ZipArchive::OPSYS_UNIX
            && (($attributes >> 16) & 0xf000) === 0xa000;
    }
}
