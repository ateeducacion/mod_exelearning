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
 * A single preview session handle, minted by {@see session_store}.
 *
 * Wraps the on-disk session directory and its ACTIVE revision, and resolves a
 * requested path across the three contract layers (documents → assetRefs→assets
 * → fixedRefs→fixed-manifest → miss) through {@see self::get_file()}. The active
 * revision is read once when the handle is constructed (the store passes the
 * pointer value), and revision directories are immutable once published, so a
 * handle serves one consistent revision for its whole lifetime — no request ever
 * mixes revision N and N+1.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_session {
    /** @var string The previewId (server-minted UUID). */
    public $previewid;

    /** @var int The owning user's id. */
    public $owneruserid;

    /** @var int Active revision number; 0 until the first revision publishes. */
    public $revision;

    /** @var string Absolute path to the session directory. */
    private $dir;

    /** @var int Session-asset bytes (from meta). */
    private $assetbytes;

    /** @var int Active-revision document bytes (from meta). */
    private $documentbytes;

    /** @var array{documents:array<string,int>,assetrefs:array<string,string>,fixedrefs:array<string,string>}|null */
    private $manifestcache = null;

    /** @var array<string,int>|null Asset index cache (assetKey → size). */
    private $assetindexcache = null;

    /**
     * Build a handle bound to a session directory and its active revision.
     *
     * @param string $previewid
     * @param int $owneruserid
     * @param string $dir Absolute session directory path.
     * @param int $revision Active revision number.
     * @param int $assetbytes Session-asset bytes.
     * @param int $documentbytes Active-revision document bytes.
     */
    public function __construct(
        string $previewid,
        int $owneruserid,
        string $dir,
        int $revision,
        int $assetbytes,
        int $documentbytes
    ) {
        $this->previewid = $previewid;
        $this->owneruserid = $owneruserid;
        $this->dir = $dir;
        $this->revision = $revision;
        $this->assetbytes = $assetbytes;
        $this->documentbytes = $documentbytes;
    }

    /**
     * Absolute path to this session's directory.
     *
     * @return string
     */
    public function dir(): string {
        return $this->dir;
    }

    /**
     * Session-asset bytes plus active-revision document bytes (the value the
     * per-session byte budget is measured against).
     *
     * @return int
     */
    public function session_bytes(): int {
        return $this->assetbytes + $this->documentbytes;
    }

    /**
     * Session-asset bytes only (assets persist across revisions).
     *
     * @return int
     */
    public function asset_bytes(): int {
        return $this->assetbytes;
    }

    /**
     * Resolve a served path against the active revision through the three
     * contract layers. Unsafe paths and a session without a published revision
     * (revision 0) resolve to null for everything.
     *
     * @param string $rawpath The requested path (below the capability prefix).
     * @return \stdClass|null {kind, bytes, contenttype, scriptable, etag} or null.
     */
    public function get_file(string $rawpath): ?\stdClass {
        if ($this->revision === 0) {
            return null;
        }
        $path = serving::normalize_content_path($rawpath);
        if ($path === null) {
            return null;
        }
        $manifest = $this->load_manifest();
        $contenttype = serving::content_type_for($path);
        $scriptable = serving::is_scriptable($contenttype);

        // Layer 3: generated documents.
        if (array_key_exists($path, $manifest['documents'])) {
            $bytes = @file_get_contents($this->dir . '/revisions/' . $this->revision . '/'
                . session_store::doc_basename($path));
            if ($bytes !== false) {
                return $this->file('document', $bytes, $contenttype, $scriptable, null);
            }
            return null;
        }

        // Layer 2: session project assets, addressed by assetKey.
        if (isset($manifest['assetrefs'][$path])) {
            $key = $manifest['assetrefs'][$path];
            $index = $this->load_asset_index();
            if (array_key_exists($key, $index)) {
                $bytes = @file_get_contents($this->dir . '/assets/' . session_store::asset_basename($key));
                if ($bytes !== false) {
                    return $this->file('asset', $bytes, $contenttype, $scriptable, $key);
                }
            }
            return null;
        }

        // Layer 1: fixed installation resources, addressed by fixedResourceId.
        if (isset($manifest['fixedrefs'][$path])) {
            $resource = fixed_resources::get_resource($manifest['fixedrefs'][$path]);
            if ($resource !== null) {
                return $this->file('fixed', $resource->bytes, $contenttype, $scriptable, null);
            }
        }

        return null;
    }

    /**
     * Build a resolved-file value object.
     *
     * @param string $kind document|asset|fixed
     * @param string $bytes
     * @param string $contenttype
     * @param bool $scriptable
     * @param string|null $etag
     * @return \stdClass
     */
    private function file(string $kind, string $bytes, string $contenttype, bool $scriptable, ?string $etag): \stdClass {
        $file = new \stdClass();
        $file->kind = $kind;
        $file->bytes = $bytes;
        $file->contenttype = $contenttype;
        $file->scriptable = $scriptable;
        $file->etag = $etag;
        return $file;
    }

    /**
     * Read the active revision's manifest (documents + both ref maps) once.
     *
     * @return array{documents:array<string,int>,assetrefs:array<string,string>,fixedrefs:array<string,string>}
     */
    private function load_manifest(): array {
        if ($this->manifestcache !== null) {
            return $this->manifestcache;
        }
        $empty = ['documents' => [], 'assetrefs' => [], 'fixedrefs' => []];
        $json = @file_get_contents($this->dir . '/revisions/' . $this->revision . '/revision.json');
        if ($json === false) {
            $this->manifestcache = $empty;
            return $empty;
        }
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            $this->manifestcache = $empty;
            return $empty;
        }
        $this->manifestcache = [
            'documents' => is_array($parsed['documents'] ?? null) ? $parsed['documents'] : [],
            'assetrefs' => is_array($parsed['assetrefs'] ?? null) ? $parsed['assetrefs'] : [],
            'fixedrefs' => is_array($parsed['fixedrefs'] ?? null) ? $parsed['fixedrefs'] : [],
        ];
        return $this->manifestcache;
    }

    /**
     * Read the session asset index (assetKey → size) once.
     *
     * @return array<string,int>
     */
    private function load_asset_index(): array {
        if ($this->assetindexcache !== null) {
            return $this->assetindexcache;
        }
        $json = @file_get_contents($this->dir . '/assets/index.json');
        $parsed = ($json !== false) ? json_decode($json, true) : null;
        $this->assetindexcache = is_array($parsed) ? $parsed : [];
        return $this->assetindexcache;
    }
}
