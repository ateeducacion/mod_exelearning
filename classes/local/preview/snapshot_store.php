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
 * File-backed store for opaque editor-preview snapshots.
 *
 * The editor sends the WHOLE project as one ZIP on every opaque refresh and gets
 * back an unguessable capability id; preview.php then serves that tree without a
 * session. This replaces the layered protocol-v2 store (immutable asset keys,
 * incremental revisions, fixed installation resources): the editor no longer
 * speaks that protocol, and a full snapshot removes the machinery that existed
 * only to avoid re-uploading unchanged bytes.
 *
 * PHP is request-scoped and the authless GETs that serve a snapshot carry no
 * session, so the capability must live on disk:
 *
 *   {previewId}/
 *     meta.json    ownerUserId, cmid
 *     access       empty marker; its mtime is the idle-TTL clock
 *     content/     the extracted snapshot
 *
 * The content lives in its own subdirectory so no author path can ever collide
 * with the store's own files — there are no reserved names to police.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class snapshot_store {
    /** @var int Idle lifetime; a snapshot untouched for this long is reclaimed. */
    const TTL_SECONDS = 1800;

    /** @var int Default cap on entries in one snapshot. */
    const DEFAULT_MAX_FILES = 10000;

    /** @var int Default cap on total uncompressed bytes in one snapshot. */
    const DEFAULT_MAX_BYTES = 1073741824;

    /** @var string|null Storage root override, tests only. */
    private static $rootoverride = null;

    /** @var array<string,int> Limit overrides, tests only. */
    private static $limitoverrides = [];

    /**
     * Point the store at a scratch directory.
     *
     * @param string $dir Absolute directory path.
     * @return void
     */
    public static function set_root_for_testing(string $dir): void {
        self::$rootoverride = $dir;
    }

    /**
     * Restore the real storage root.
     *
     * @return void
     */
    public static function reset_root_for_testing(): void {
        self::$rootoverride = null;
    }

    /**
     * Shrink the limits so a test can trip them without building a huge ZIP.
     *
     * @param array<string,int> $overrides Keys 'maxfiles' and/or 'maxbytes'.
     * @return void
     */
    public static function set_limits_for_testing(array $overrides): void {
        self::$limitoverrides = $overrides;
    }

    /**
     * Restore the real limits.
     *
     * @return void
     */
    public static function reset_limits_for_testing(): void {
        self::$limitoverrides = [];
    }

    /**
     * The active snapshot limits.
     *
     * Admins can raise them for large projects; a non-positive value falls back
     * to the default, so the guard cannot be switched off.
     *
     * @return array{maxfiles:int,maxbytes:int}
     */
    public static function limits(): array {
        $maxfiles = (int) get_config('mod_exelearning', 'previewmaxfiles');
        $maxbytes = (int) get_config('mod_exelearning', 'previewmaxbytes');
        return [
            'maxfiles' => self::$limitoverrides['maxfiles'] ?? ($maxfiles > 0 ? $maxfiles : self::DEFAULT_MAX_FILES),
            'maxbytes' => self::$limitoverrides['maxbytes'] ?? ($maxbytes > 0 ? $maxbytes : self::DEFAULT_MAX_BYTES),
        ];
    }

    /**
     * Create or atomically replace a snapshot.
     *
     * Everything is built beside the live tree and swapped in at the end, so a
     * reader sees either the previous snapshot or the new one, never a partial
     * extraction, and a failure anywhere leaves the live one untouched.
     *
     * @param int         $owneruserid Authoring user.
     * @param int         $cmid        Course module the snapshot belongs to.
     * @param string      $zippath     Uploaded ZIP pathname.
     * @param string|null $previewid   Existing capability when replacing.
     * @return array{previewid:string}|array{error:string} Capability id, or an error code.
     */
    public static function replace(int $owneruserid, int $cmid, string $zippath, ?string $previewid = null) {
        self::sweep_expired();
        $id = $previewid !== null && $previewid !== '' ? $previewid : self::generate_id();
        $authorized = self::authorize($id, $owneruserid, $cmid, $previewid);
        if ($authorized !== true) {
            return ['error' => $authorized];
        }
        $staging = self::stage($owneruserid, $cmid, $zippath);
        if (!is_array($staging) || !isset($staging['dir'])) {
            return $staging;
        }
        $published = self::publish($staging['dir'], self::root() . '/' . $id);
        if ($published !== true) {
            return ['error' => $published];
        }
        return ['previewid' => $id];
    }

    /**
     * Decide whether this caller may write to a capability.
     *
     * Replacing demands an existing snapshot owned by the same user AND bound to
     * the same course module; a fresh capability has no metadata to match yet.
     *
     * @param string      $id          Capability id.
     * @param int         $owneruserid Authoring user.
     * @param int         $cmid        Course module id.
     * @param string|null $previewid   Existing capability when replacing.
     * @return bool|string True, or an error code.
     */
    private static function authorize(string $id, int $owneruserid, int $cmid, ?string $previewid) {
        if (!preg_match(serving::UUID_RE, $id)) {
            return 'invalidpreviewid';
        }
        $meta = self::metadata($id);
        if ($previewid !== null && $previewid !== '' && $meta === null) {
            return 'missingpreview';
        }
        if (
            $meta !== null
                && ((int) $meta['ownerUserId'] !== $owneruserid || (int) $meta['cmid'] !== $cmid)
        ) {
            return 'previewforbidden';
        }
        return true;
    }

    /**
     * Extract a snapshot and write its metadata into a private staging directory.
     *
     * The staging directory is removed on any failure, so a rejected upload
     * leaves nothing behind.
     *
     * @param int    $owneruserid Authoring user.
     * @param int    $cmid        Course module id.
     * @param string $zippath     Uploaded ZIP pathname.
     * @return array{dir:string}|array{error:string}
     */
    private static function stage(int $owneruserid, int $cmid, string $zippath) {
        $staging = self::root() . '/.staging-' . bin2hex(random_bytes(12));
        if (!make_writable_directory($staging . '/content', false)) {
            return ['error' => 'previewstorage'];
        }
        $extracted = self::extract($zippath, $staging . '/content');
        if ($extracted !== true) {
            remove_dir($staging);
            return ['error' => $extracted];
        }
        $meta = json_encode(['ownerUserId' => $owneruserid, 'cmid' => $cmid]);
        if (
            file_put_contents($staging . '/meta.json', $meta) === false
                || !touch($staging . '/access')
        ) {
            remove_dir($staging);
            return ['error' => 'previewstorage'];
        }
        return ['dir' => $staging];
    }

    /**
     * Swap a staged snapshot into place, keeping the previous one until it lands.
     *
     * @param string $staging Staging directory pathname.
     * @param string $target  Final snapshot directory pathname.
     * @return bool|string True, or an error code.
     */
    private static function publish(string $staging, string $target) {
        $backup = $target . '.old-' . bin2hex(random_bytes(6));
        if (is_dir($target) && !rename($target, $backup)) {
            remove_dir($staging);
            return 'previewstorage';
        }
        if (!rename($staging, $target)) {
            if (is_dir($backup)) {
                rename($backup, $target);
            }
            remove_dir($staging);
            return 'previewstorage';
        }
        if (is_dir($backup)) {
            remove_dir($backup);
        }
        return true;
    }

    /**
     * Validate and extract a ZIP into a directory.
     *
     * @param string $zippath Uploaded ZIP pathname.
     * @param string $target  Destination directory.
     * @return bool|string True, or an error code.
     */
    private static function extract(string $zippath, string $target) {
        $zip = new ZipArchive();
        if ($zip->open($zippath) !== true) {
            return 'invalidpreviewzip';
        }
        $inspected = zip_inspector::inspect($zip, self::limits());
        if ($inspected !== true) {
            $zip->close();
            return $inspected;
        }
        if (!$zip->extractTo($target)) {
            $zip->close();
            return 'previewstorage';
        }
        $zip->close();
        return true;
    }

    /**
     * Resolve a snapshot for an authless serving request and refresh its idle clock.
     *
     * @param string $previewid Capability id.
     * @return string|null Absolute content directory, or null when unknown or expired.
     */
    public static function get_content_dir(string $previewid): ?string {
        if (!preg_match(serving::UUID_RE, $previewid)) {
            return null;
        }
        $dir = self::root() . '/' . $previewid;
        $access = $dir . '/access';
        $touched = @filemtime($access);
        if ($touched === false || time() - $touched > self::TTL_SECONDS) {
            return null;
        }
        // Refresh the idle clock: an in-use preview must not expire under the author.
        @touch($access);
        return is_dir($dir . '/content') ? $dir . '/content' : null;
    }

    /**
     * Delete a snapshot owned by this user and course module.
     *
     * @param string $previewid   Capability id.
     * @param int    $owneruserid Authoring user.
     * @param int    $cmid        Course module id.
     * @return bool Whether a snapshot was removed.
     */
    public static function delete(string $previewid, int $owneruserid, int $cmid): bool {
        if (!preg_match(serving::UUID_RE, $previewid)) {
            return false;
        }
        $meta = self::metadata($previewid);
        if (
            $meta === null
                || (int) $meta['ownerUserId'] !== $owneruserid || (int) $meta['cmid'] !== $cmid
        ) {
            return false;
        }
        remove_dir(self::root() . '/' . $previewid);
        return true;
    }

    /**
     * Reclaim snapshots whose idle lifetime has elapsed.
     *
     * Cron is traffic-dependent, so this also runs opportunistically on every
     * replace: the store never depends on a scheduled task to bound its size.
     *
     * @return int Number of snapshots removed.
     */
    public static function sweep_expired(): int {
        $root = self::root();
        $count = 0;
        $entries = is_dir($root) ? scandir($root) : false;
        foreach ($entries ?: [] as $id) {
            if (!preg_match(serving::UUID_RE, $id)) {
                continue;
            }
            $touched = @filemtime($root . '/' . $id . '/access');
            if ($touched === false || time() - $touched > self::TTL_SECONDS) {
                remove_dir($root . '/' . $id);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Read a snapshot's ownership record.
     *
     * @param string $previewid Capability id.
     * @return array{ownerUserId:int,cmid:int}|null
     */
    private static function metadata(string $previewid): ?array {
        $path = self::root() . '/' . $previewid . '/meta.json';
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['ownerUserId'], $decoded['cmid'])) {
            return null;
        }
        return $decoded;
    }

    /**
     * Mint an unguessable capability id.
     *
     * @return string
     */
    private static function generate_id(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Ensure the storage root exists and return it.
     *
     * @return string
     */
    private static function root(): string {
        if (self::$rootoverride !== null) {
            return self::$rootoverride;
        }
        return make_temp_directory('mod_exelearning/preview-snapshots');
    }
}
