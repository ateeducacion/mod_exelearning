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

/**
 * Private store for complete, expiring editor-preview snapshots.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class snapshot_store {
    /** Idle capability lifetime. */
    private const TTL_SECONDS = 1800;

    /** Maximum number of files in one snapshot. */
    private const MAX_FILES = 5000;

    /** Maximum total uncompressed size. */
    private const MAX_BYTES = 104857600;

    /** @var string Private storage root. */
    private string $root;

    /** @var callable Clock returning a Unix timestamp. */
    private $clock;

    /**
     * Create a preview snapshot store.
     *
     * @param string|null $root Storage root override for tests.
     * @param callable|null $clock Clock override for tests.
     */
    public function __construct(?string $root = null, ?callable $clock = null) {
        global $CFG;
        $this->root = $root ?? $CFG->tempdir . '/mod_exelearning/preview';
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Atomically create or replace a complete snapshot.
     *
     * @param int $ownerid Moodle user id.
     * @param int $cmid Course module id.
     * @param string $zippath Uploaded ZIP pathname.
     * @param string|null $previewid Existing capability when replacing.
     * @return string Capability UUID.
     */
    public function replace(int $ownerid, int $cmid, string $zippath, ?string $previewid = null): string {
        $this->sweep_expired();
        $id = $previewid ?? $this->uuid();
        if (!$this->valid_id($id)) {
            throw new \invalid_parameter_exception('Invalid preview capability');
        }
        $metadata = $this->metadata($id);
        if ($previewid !== null && $metadata === null) {
            throw new \moodle_exception('Preview snapshot not found');
        }
        if ($metadata !== null && ($metadata['ownerid'] !== $ownerid || $metadata['cmid'] !== $cmid)) {
            throw new \UnexpectedValueException('Preview snapshot belongs to another activity');
        }

        $this->ensure_directory($this->root);
        $staging = $this->root . '/.staging-' . bin2hex(random_bytes(12));
        $this->ensure_directory($staging);
        try {
            $this->extract($zippath, $staging);
            $json = json_encode(['ownerid' => $ownerid, 'cmid' => $cmid], JSON_THROW_ON_ERROR);
            if (
                file_put_contents($staging . '/.metadata.json', $json) === false
                || !touch($staging . '/.accessed', ($this->clock)())
            ) {
                throw new \moodle_exception('Cannot write preview metadata');
            }
            $target = $this->root . '/' . $id;
            $backup = $target . '.old-' . bin2hex(random_bytes(6));
            if (is_dir($target) && !rename($target, $backup)) {
                throw new \moodle_exception('Cannot replace preview snapshot');
            }
            if (!rename($staging, $target)) {
                if (is_dir($backup)) {
                    rename($backup, $target);
                }
                throw new \moodle_exception('Cannot publish preview snapshot');
            }
            $this->remove_tree($backup);
        } catch (\Throwable $error) {
            $this->remove_tree($staging);
            throw $error;
        }
        return $id;
    }

    /**
     * Read a capability file and refresh the idle lifetime.
     *
     * @param string $previewid Capability UUID.
     * @param string $path Snapshot-relative path.
     * @return array{content:string,mimetype:string}|null
     */
    public function get(string $previewid, string $path): ?array {
        $this->sweep_expired();
        if (!$this->valid_id($previewid) || $this->metadata($previewid) === null) {
            return null;
        }
        $decoded = rawurldecode($path);
        if (!$this->safe_path($decoded) || $this->reserved_path($decoded)) {
            return null;
        }
        $root = realpath($this->root . '/' . $previewid);
        $file = realpath($this->root . '/' . $previewid . '/' . $decoded);
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        touch($this->root . '/' . $previewid . '/.accessed', ($this->clock)());
        return ['content' => $content, 'mimetype' => self::mimetype($decoded)];
    }

    /**
     * Delete a snapshot after checking owner and activity scope.
     *
     * @param int $ownerid Moodle user id.
     * @param int $cmid Course module id.
     * @param string $previewid Capability UUID.
     * @return bool Whether a snapshot existed.
     */
    public function delete(int $ownerid, int $cmid, string $previewid): bool {
        $metadata = $this->metadata($previewid);
        if ($metadata === null) {
            return false;
        }
        if ($metadata['ownerid'] !== $ownerid || $metadata['cmid'] !== $cmid) {
            throw new \UnexpectedValueException('Preview snapshot belongs to another activity');
        }
        $this->remove_tree($this->root . '/' . $previewid);
        return true;
    }

    /**
     * Remove idle snapshots.
     *
     * @return int Number removed.
     */
    public function sweep_expired(): int {
        if (!is_dir($this->root)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($this->root) ?: [] as $id) {
            if (!$this->valid_id($id)) {
                continue;
            }
            $accessed = @filemtime($this->root . '/' . $id . '/.accessed');
            if ($accessed === false || ($this->clock)() - $accessed > self::TTL_SECONDS) {
                $this->remove_tree($this->root . '/' . $id);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Headers applied to every public capability response.
     *
     * @return array<string,string>
     */
    public static function response_headers(): array {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Access-Control-Allow-Origin' => '*',
        ];
    }

    /**
     * Sandbox CSP for directly opened scriptable files.
     *
     * @return string
     */
    public static function content_security_policy(): string {
        return "sandbox allow-scripts allow-popups allow-forms allow-downloads allow-presentation; "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob: https:; media-src 'self' data: blob: https:; "
            . "font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube-nocookie.com "
            . "https://player.vimeo.com; child-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; "
            . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";
    }

    /**
     * Determine whether a MIME type can execute as a document.
     *
     * @param string $mimetype MIME value.
     * @return bool Whether the resource can execute as a document.
     */
    public static function is_scriptable(string $mimetype): bool {
        $type = strtolower(trim(explode(';', $mimetype, 2)[0]));
        return in_array($type, ['text/html', 'image/svg+xml', 'application/xml', 'application/xhtml+xml'], true);
    }

    /**
     * Validate and extract an uploaded snapshot.
     *
     * @param string $zippath ZIP pathname.
     * @param string $target Staging directory.
     */
    private function extract(string $zippath, string $target): void {
        $zip = new \ZipArchive();
        if ($zip->open($zippath) !== true) {
            throw new \invalid_parameter_exception('Invalid preview ZIP');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new \LengthException('Preview ZIP contains too many files');
            }
            $total = 0;
            $hasindex = false;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                $directory = is_string($name) && str_ends_with($name, '/');
                $validated = $directory ? rtrim($name, '/') : $name;
                if (!is_string($name) || !is_array($stat) || !$this->safe_path($validated)) {
                    throw new \invalid_parameter_exception('Unsafe preview ZIP path');
                }
                if ($directory) {
                    continue;
                }
                if ($this->reserved_path($name)) {
                    throw new \invalid_parameter_exception('Reserved preview ZIP path');
                }
                $operationsystem = 0;
                $attributes = 0;
                if (
                    $zip->getExternalAttributesIndex($index, $operationsystem, $attributes)
                    && $operationsystem === \ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0xf000) === 0xa000
                ) {
                    throw new \invalid_parameter_exception('Preview ZIP contains a symbolic link');
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new \LengthException('Preview ZIP is too large');
                }
                $hasindex = $hasindex || $name === 'index.html';
            }
            if (!$hasindex || !$zip->extractTo($target)) {
                throw new \invalid_parameter_exception('Preview ZIP must contain index.html');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Check that a snapshot path is relative and canonical.
     *
     * @param string $path Relative path.
     * @return bool Whether the path is canonical and safe.
     */
    private function safe_path(string $path): bool {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Check whether a path belongs to private store metadata.
     *
     * @param string $path Relative path.
     * @return bool
     */
    private function reserved_path(string $path): bool {
        return $path === '.metadata.json' || $path === '.accessed';
    }

    /**
     * Read capability ownership metadata.
     *
     * @param string $id Capability UUID.
     * @return array{ownerid:int,cmid:int}|null
     */
    private function metadata(string $id): ?array {
        if (!$this->valid_id($id)) {
            return null;
        }
        $contents = @file_get_contents($this->root . '/' . $id . '/.metadata.json');
        $metadata = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($metadata) || !isset($metadata['ownerid'], $metadata['cmid'])) {
            return null;
        }
        return ['ownerid' => (int) $metadata['ownerid'], 'cmid' => (int) $metadata['cmid']];
    }

    /**
     * Validate a UUIDv4 capability.
     *
     * @param string $id Capability value.
     * @return bool
     */
    private function valid_id(string $id): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) === 1;
    }

    /**
     * Generate a UUIDv4 capability.
     *
     * @return string UUIDv4 capability.
     */
    private function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /**
     * Select a safe MIME type from a snapshot path.
     *
     * @param string $path File path.
     * @return string MIME type.
     */
    private static function mimetype(string $path): string {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html; charset=utf-8',
            'xhtml' => 'application/xhtml+xml',
            'xml' => 'application/xml',
            'svg' => 'image/svg+xml',
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => 'application/octet-stream',
        };
    }

    /**
     * Create a private directory when needed.
     *
     * @param string $path Directory path.
     */
    private function ensure_directory(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \moodle_exception('Cannot create preview directory');
        }
    }

    /**
     * Recursively remove a private snapshot directory.
     *
     * @param string $path Directory path.
     */
    private function remove_tree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->remove_tree($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
