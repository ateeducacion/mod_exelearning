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
 * File-backed preview session store (serving contract v2, the three layers).
 *
 * PHP is request-scoped, so an in-memory session map (like eXe core's Bun
 * service) does not survive between the management request that populates a
 * session and the authless GETs that serve it. This store persists each session
 * under make_temp_directory('mod_exelearning/preview-sessions/{previewId}'):
 *
 *   {previewId}/
 *     meta.json                    ownerUserId, createdAt, assetBytes, documentBytes
 *     access                       empty marker; its mtime is the idle-TTL clock
 *     current                      the ACTIVE revision number (absent ⇒ revision 0)
 *     assets/
 *       index.json                 assetKey → size (immutable per key)
 *       asset_{sha1(key)}          asset bytes
 *     revisions/{n}/
 *       revision.json              documents{path→size}, assetrefs, fixedrefs
 *       doc_{sha1(path)}           document bytes
 *
 * Atomicity: a revision is staged in revisions/{n} and then published by an
 * atomic rename of the `current` pointer. A serving GET reads `current` ONCE and
 * serves strictly from that immutable revision directory, so it never observes a
 * mix of revision N and N+1. Mutations (asset store, revision publish) hold a
 * per-session core lock so the revision compare-and-swap is race-free.
 *
 * Sessions are ephemeral: an idle TTL sweeper (scheduled task + opportunistic
 * check on access) reaps them, and the client transparently recreates a session
 * on the next refresh after any 404.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class session_store {
    /** @var int Idle time-to-live in seconds (30 minutes). */
    const TTL_SECONDS = 1800;

    /** @var int Maximum concurrent sessions per user (LRU-evicted on create). */
    const MAX_SESSIONS_PER_USER = 4;

    /** @var int Maximum files (documents + asset refs + fixed refs) per session. */
    const MAX_FILES_PER_SESSION = 5000;

    /** @var int Maximum bytes (documents + assets) per session (200 MiB). */
    const MAX_BYTES_PER_SESSION = 209715200;

    /** @var int Maximum bytes for a single asset (128 MiB). */
    const MAX_ASSET_BYTES = 134217728;

    /** @var int Global byte ceiling across all sessions (2 GiB), LRU-evicted on create. */
    const GLOBAL_MAX_BYTES = 2147483648;

    /** @var string assetKey wire format: {assetId}@{contentHashPrefix}. */
    const ASSET_KEY_RE = '/^[0-9a-fA-F-]{36}@[0-9a-f]{8,64}$/';

    /** @var string Lock type for the preview-session locks. */
    const LOCK_TYPE = 'mod_exelearning';

    /** @var int Seconds to wait for a session lock before giving up. */
    const LOCK_TIMEOUT = 10;

    /** @var string|null Storage-root override for tests. */
    private static $rootoverride = null;

    /** @var array<string,int> Per-limit overrides for tests. */
    private static $limitoverrides = [];

    /**
     * Point the store at an explicit storage root (tests only).
     *
     * @param string $dir Absolute directory that will hold session subdirectories.
     */
    public static function set_root_for_testing(string $dir): void {
        self::$rootoverride = $dir;
    }

    /**
     * Restore the default storage root.
     */
    public static function reset_root_for_testing(): void {
        self::$rootoverride = null;
    }

    /**
     * Override one or more limits (tests only) so the 413 / too-large / eviction
     * / TTL branches are exercisable without allocating the production budgets.
     *
     * @param array $overrides Per-limit overrides, keyed by the limits() keys.
     */
    public static function set_limits_for_testing(array $overrides): void {
        self::$limitoverrides = $overrides;
    }

    /**
     * Restore the default limits.
     */
    public static function reset_limits_for_testing(): void {
        self::$limitoverrides = [];
    }

    /**
     * The effective session limits (route handlers advertise and enforce them).
     *
     * @return array<string,int>
     */
    public static function limits(): array {
        return [
            'ttl' => self::limitof('ttl'),
            'maxsessionsperuser' => self::limitof('maxsessionsperuser'),
            'maxfilespersession' => self::limitof('maxfilespersession'),
            'maxbytespersession' => self::limitof('maxbytespersession'),
            'maxassetbytes' => self::limitof('maxassetbytes'),
            'globalmaxbytes' => self::limitof('globalmaxbytes'),
        ];
    }

    /**
     * The effective value of a single limit (test override, else the constant).
     *
     * @param string $key
     * @return int
     */
    private static function limitof(string $key): int {
        if (array_key_exists($key, self::$limitoverrides)) {
            return self::$limitoverrides[$key];
        }
        $constants = [
            'ttl' => self::TTL_SECONDS,
            'maxsessionsperuser' => self::MAX_SESSIONS_PER_USER,
            'maxfilespersession' => self::MAX_FILES_PER_SESSION,
            'maxbytespersession' => self::MAX_BYTES_PER_SESSION,
            'maxassetbytes' => self::MAX_ASSET_BYTES,
            'globalmaxbytes' => self::GLOBAL_MAX_BYTES,
        ];
        return $constants[$key];
    }

    /**
     * On-disk basename for a document served at $path.
     *
     * @param string $path Normalized served path.
     * @return string
     */
    public static function doc_basename(string $path): string {
        return 'doc_' . sha1($path);
    }

    /**
     * On-disk basename for an asset addressed by $key.
     *
     * @param string $key assetKey.
     * @return string
     */
    public static function asset_basename(string $key): string {
        return 'asset_' . sha1($key);
    }

    // Session lifecycle.

    /**
     * Create a session for $owneruserid and return its previewId. Enforces the
     * per-user session cap and the global byte ceiling by LRU-evicting first.
     *
     * @param int $owneruserid
     * @return string The server-minted previewId (UUID).
     */
    public static function create_session(int $owneruserid): string {
        $base = self::ensure_base_dir();
        self::evict_for_new_session($owneruserid);

        do {
            $previewid = \core\uuid::generate();
        } while (is_dir($base . '/' . $previewid));

        $dir = $base . '/' . $previewid;
        self::make_dir($dir);
        self::make_dir($dir . '/assets');
        self::make_dir($dir . '/revisions');
        self::write_json($dir . '/meta.json', [
            'owneruserid' => $owneruserid,
            'createdat' => time(),
            'assetbytes' => 0,
            'documentbytes' => 0,
        ]);
        self::write_json($dir . '/assets/index.json', []);
        touch($dir . '/access');
        return $previewid;
    }

    /**
     * Load a session by previewId without any auth or TTL check (internal).
     *
     * @param string $previewid
     * @return preview_session|null
     */
    private static function load(string $previewid): ?preview_session {
        if (!preg_match(serving::UUID_RE, $previewid)) {
            return null;
        }
        $dir = self::base_dir_path() . '/' . $previewid;
        if (!is_dir($dir)) {
            return null;
        }
        $meta = self::read_json($dir . '/meta.json');
        if (!is_array($meta) || !isset($meta['owneruserid'])) {
            return null;
        }
        $revision = self::read_current($dir);
        return new preview_session(
            $previewid,
            (int) $meta['owneruserid'],
            $dir,
            $revision,
            (int) ($meta['assetbytes'] ?? 0),
            (int) ($meta['documentbytes'] ?? 0)
        );
    }

    /**
     * Owner-scoped lookup for the management API: 404 when missing/expired, 403
     * when owned by someone else, otherwise the session (TTL clock touched).
     *
     * @param string $previewid
     * @param int $owneruserid
     * @return array{status:int,session:preview_session|null}
     */
    public static function get_owned(string $previewid, int $owneruserid): array {
        $session = self::load($previewid);
        if ($session === null) {
            return ['status' => 404, 'session' => null];
        }
        if (self::is_expired($session->dir())) {
            self::delete_session($previewid);
            return ['status' => 404, 'session' => null];
        }
        if ($session->owneruserid !== $owneruserid) {
            return ['status' => 403, 'session' => null];
        }
        self::touch_access($session->dir());
        return ['status' => 200, 'session' => $session];
    }

    /**
     * Lookup for the authless serving route: touches the idle-TTL clock, and
     * opportunistically reaps the session when it has already expired.
     *
     * @param string $previewid
     * @return preview_session|null
     */
    public static function get_for_serving(string $previewid): ?preview_session {
        $session = self::load($previewid);
        if ($session === null) {
            return null;
        }
        if (self::is_expired($session->dir())) {
            self::delete_session($previewid);
            return null;
        }
        self::touch_access($session->dir());
        return $session;
    }

    /**
     * Delete a session and its files.
     *
     * @param string $previewid
     * @return bool True when a session directory existed and was removed.
     */
    public static function delete_session(string $previewid): bool {
        if (!preg_match(serving::UUID_RE, $previewid)) {
            return false;
        }
        $dir = self::base_dir_path() . '/' . $previewid;
        if (!is_dir($dir)) {
            return false;
        }
        remove_dir($dir);
        return true;
    }

    /**
     * Reap every idle-expired session. Called by the scheduled cleanup task.
     *
     * @return int Number of sessions swept.
     */
    public static function sweep_expired(): int {
        $swept = 0;
        foreach (self::enumerate() as $entry) {
            if (self::is_expired($entry->dir)) {
                self::delete_session($entry->previewid);
                $swept++;
            }
        }
        return $swept;
    }

    // Assets (layer 2).

    /**
     * Store uploaded assets. Keys are immutable: an existing key is reported in
     * alreadyStored and its bytes are NOT replaced (a replaced author file gets
     * a new key because its content hash changed). Per-entry failures land in
     * rejected with a reason. Runs under the per-session lock so the index and
     * meta stay consistent under concurrent uploads.
     *
     * @param preview_session $session
     * @param array $entries Upload entries, each ['key'=>string,'declaredsize'=>int,'bytes'=>string].
     * @return array{stored:string[],alreadyStored:string[],rejected:array<int,array{key:string,reason:string}>}
     */
    public static function store_assets(preview_session $session, array $entries): array {
        $stored = [];
        $alreadystored = [];
        $rejected = [];

        $lock = self::acquire_lock($session->previewid);
        try {
            $dir = $session->dir();
            $meta = self::read_json($dir . '/meta.json');
            $index = self::read_json($dir . '/assets/index.json');
            $assetbytes = (int) ($meta['assetbytes'] ?? 0);
            $documentbytes = (int) ($meta['documentbytes'] ?? 0);

            foreach ($entries as $entry) {
                $key = $entry['key'] ?? '';
                if (!preg_match(self::ASSET_KEY_RE, $key)) {
                    $rejected[] = ['key' => $key, 'reason' => 'invalid-key'];
                    continue;
                }
                if (array_key_exists($key, $index)) {
                    $alreadystored[] = $key;
                    continue;
                }
                $len = strlen($entry['bytes']);
                if ($entry['declaredsize'] !== $len) {
                    $rejected[] = ['key' => $key, 'reason' => 'size-mismatch'];
                    continue;
                }
                if ($len > self::limitof('maxassetbytes')) {
                    $rejected[] = ['key' => $key, 'reason' => 'asset-too-large'];
                    continue;
                }
                if ($documentbytes + $assetbytes + $len > self::limitof('maxbytespersession')) {
                    $rejected[] = ['key' => $key, 'reason' => 'session-budget-exceeded'];
                    continue;
                }
                if (!self::evict_others_for_budget($session->previewid, $assetbytes + $documentbytes, $len)) {
                    $rejected[] = ['key' => $key, 'reason' => 'global-budget-exceeded'];
                    continue;
                }
                // The key is indexed (and its bytes counted) ONLY once the write
                // durably succeeds: a failed or short write must never leave a
                // dangling index entry that a later revision would treat as a
                // present asset (and then 404 at serve time). The @ mirrors the
                // store's other file ops and keeps a warning off the JSON response.
                $written = @file_put_contents($dir . '/assets/' . self::asset_basename($key), $entry['bytes'], LOCK_EX);
                if ($written === false || $written !== $len) {
                    $rejected[] = ['key' => $key, 'reason' => 'write-failed'];
                    continue;
                }
                $index[$key] = $len;
                $assetbytes += $len;
                $stored[] = $key;
            }

            $meta['assetbytes'] = $assetbytes;
            self::write_json($dir . '/meta.json', $meta);
            self::write_json($dir . '/assets/index.json', $index);
        } finally {
            $lock->release();
        }

        return ['stored' => $stored, 'alreadyStored' => $alreadystored, 'rejected' => $rejected];
    }

    // Revisions (layer 3 publication).

    /**
     * Publish a revision. Validation order per the contract: revision check (409)
     * → path normalization (400) → asset existence (422) → fixed-resource
     * existence (422) → file-count / byte budgets (413). The new document set is
     * staged and then published by an atomic pointer swap, so a concurrent GET
     * observes revision N or N+1 in full, never a mixture, and a failed apply
     * leaves the active revision completely intact.
     *
     * @param preview_session $session
     * @param array $meta Keys: baserevision(int), nextrevision(int), deletes(string[]), assetrefs(map), fixedrefs(map).
     * @param array $bufferedwrites Buffered writes, each ['path'=>string,'bytes'=>string].
     * @return array Result: {revision,active} | {status,currentrevision|reason|missing|resources|message}
     */
    public static function apply_revision(preview_session $session, array $meta, array $bufferedwrites): array {
        $lock = self::acquire_lock($session->previewid);
        try {
            $dir = $session->dir();
            $active = self::read_current($dir);

            // 1. Revision ordering.
            if ($meta['baserevision'] !== $active || $meta['nextrevision'] !== $active + 1) {
                return ['status' => 409, 'currentrevision' => $active];
            }
            $next = $meta['nextrevision'];

            // 2. Normalize every client-supplied path.
            $writes = [];
            foreach ($bufferedwrites as $write) {
                $normalized = serving::normalize_content_path($write['path']);
                if ($normalized === null) {
                    return ['status' => 400, 'message' => 'Unsafe path in writes: ' . $write['path']];
                }
                $writes[$normalized] = $write['bytes'];
            }
            $deletes = [];
            foreach ($meta['deletes'] as $rawpath) {
                $normalized = serving::normalize_content_path($rawpath);
                if ($normalized === null) {
                    return ['status' => 400, 'message' => 'Unsafe path in deletes: ' . $rawpath];
                }
                $deletes[] = $normalized;
            }
            $assetrefs = [];
            foreach ($meta['assetrefs'] as $rawpath => $key) {
                $normalized = serving::normalize_content_path($rawpath);
                if ($normalized === null) {
                    return ['status' => 400, 'message' => 'Unsafe path in assetRefs: ' . $rawpath];
                }
                $assetrefs[$normalized] = $key;
            }
            $fixedrefs = [];
            foreach ($meta['fixedrefs'] as $rawpath => $id) {
                $normalized = serving::normalize_content_path($rawpath);
                if ($normalized === null) {
                    return ['status' => 400, 'message' => 'Unsafe path in fixedRefs: ' . $rawpath];
                }
                $fixedrefs[$normalized] = $id;
            }

            // 3. Every referenced asset must exist in the session store.
            $index = self::read_json($dir . '/assets/index.json');
            $missing = [];
            foreach (array_unique(array_values($assetrefs)) as $key) {
                if (!array_key_exists($key, $index)) {
                    $missing[] = $key;
                }
            }
            if (!empty($missing)) {
                return ['status' => 422, 'reason' => 'missing-assets', 'missing' => array_values($missing)];
            }

            // 4. Every referenced fixed resource must be manifest-listed.
            $unknown = [];
            foreach (array_unique(array_values($fixedrefs)) as $id) {
                if (!fixed_resources::has_resource($id)) {
                    $unknown[] = $id;
                }
            }
            if (!empty($unknown)) {
                return ['status' => 422, 'reason' => 'unknown-fixed-resources', 'resources' => array_values($unknown)];
            }

            // 5. Budgets, computed on the post-delta document set (deletes then writes).
            $prevdocs = ($active > 0) ? self::read_revision_documents($dir, $active) : [];
            $newdocs = $prevdocs;
            foreach ($deletes as $del) {
                unset($newdocs[$del]);
            }
            foreach ($writes as $path => $bytes) {
                $newdocs[$path] = strlen($bytes);
            }
            $documentbytes = array_sum($newdocs);
            $filecount = count($newdocs) + count($assetrefs) + count($fixedrefs);
            $metadata = self::read_json($dir . '/meta.json');
            $assetbytes = (int) ($metadata['assetbytes'] ?? 0);
            $priordocumentbytes = (int) ($metadata['documentbytes'] ?? 0);
            if ($filecount > self::limitof('maxfilespersession')) {
                return ['status' => 413, 'message' => 'Too many files (max ' . self::limitof('maxfilespersession') . ')'];
            }
            if ($documentbytes + $assetbytes > self::limitof('maxbytespersession')) {
                return ['status' => 413, 'message' => 'Session over byte budget'];
            }
            $bytedelta = $documentbytes - $priordocumentbytes;
            $priorbytes = $assetbytes + $priordocumentbytes;
            if ($bytedelta > 0 && !self::evict_others_for_budget($session->previewid, $priorbytes, $bytedelta)) {
                return ['status' => 413, 'message' => 'Preview storage budget exceeded'];
            }

            // 6. Stage the full document set for the new revision, then publish
            // it with an atomic pointer swap. A write failure while staging aborts
            // BEFORE the swap, so the active revision is left completely intact.
            if (!self::publish_revision($dir, $active, $next, $newdocs, $writes, $assetrefs, $fixedrefs)) {
                return ['status' => 500, 'message' => 'Failed to persist the preview revision'];
            }
            $metadata['documentbytes'] = $documentbytes;
            self::write_json($dir . '/meta.json', $metadata);

            return ['revision' => $next, 'active' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * Materialize the new revision's document set in revisions/{next} (copying
     * unchanged documents forward from revisions/{active}, writing changed ones
     * from the buffered payload), then flip the `current` pointer atomically and
     * prune superseded revisions.
     *
     * @param string $dir Session directory.
     * @param int $active Currently active revision.
     * @param int $next New revision number.
     * @param array $newdocs Full document set, path => size.
     * @param array $writes Changed documents, path => bytes.
     * @param array $assetrefs Full asset-ref map, served path => assetKey.
     * @param array $fixedrefs Full fixed-ref map, served path => fixedResourceId.
     * @return bool True on success; false when a staging write/copy failed (the
     *              staged revision is discarded and the `current` pointer is left
     *              untouched, so the active revision stays intact).
     */
    private static function publish_revision(
        string $dir,
        int $active,
        int $next,
        array $newdocs,
        array $writes,
        array $assetrefs,
        array $fixedrefs
    ): bool {
        $revdir = $dir . '/revisions/' . $next;
        // Remove any stale artifact from a previously failed attempt at $next.
        if (is_dir($revdir)) {
            remove_dir($revdir);
        }
        self::make_dir($revdir);

        // Materialize the full document set. A failed or short write (disk full,
        // read-only volume, a collision) must abort the publish BEFORE the pointer
        // swap — otherwise the revision would go live with a truncated or missing
        // document that serves as a blank 404, silently corrupting the preview.
        foreach ($newdocs as $path => $size) {
            $target = $revdir . '/' . self::doc_basename($path);
            if (array_key_exists($path, $writes)) {
                $bytes = $writes[$path];
                $written = @file_put_contents($target, $bytes, LOCK_EX);
                if ($written === false || $written !== strlen($bytes)) {
                    self::discard_staged_revision($revdir);
                    return false;
                }
            } else {
                $source = $dir . '/revisions/' . $active . '/' . self::doc_basename($path);
                if (!@copy($source, $target)) {
                    self::discard_staged_revision($revdir);
                    return false;
                }
            }
        }
        self::write_json($revdir . '/revision.json', [
            'revision' => $next,
            'documents' => $newdocs,
            'assetrefs' => $assetrefs,
            'fixedrefs' => $fixedrefs,
        ]);

        // Atomic pointer swap: a GET reads `current` once and serves from the
        // immutable revision directory it names.
        self::write_pointer($dir . '/current', (string) $next);

        // Keep only the active and the just-superseded revisions so an in-flight
        // GET that read the previous pointer can still complete.
        foreach (self::list_revision_numbers($dir) as $number) {
            if ($number < $next - 1) {
                remove_dir($dir . '/revisions/' . $number);
            }
        }
        return true;
    }

    /**
     * Discard a staged (not-yet-published) revision directory after a write
     * failure. The `current` pointer is never touched here, so the active
     * revision is unaffected and no partial revision can be observed.
     *
     * @param string $revdir The staged revision directory.
     */
    private static function discard_staged_revision(string $revdir): void {
        if (is_dir($revdir)) {
            remove_dir($revdir);
        }
    }

    // Eviction (per-user cap + global ceiling).

    /**
     * Enforce the per-user session cap and the global byte ceiling before a new
     * session is created, LRU-evicting whole sessions as needed.
     *
     * @param int $owneruserid
     */
    private static function evict_for_new_session(int $owneruserid): void {
        $sessions = self::enumerate();

        // Per-user cap: drop the owner's least-recently-accessed session(s).
        $owned = array_values(array_filter($sessions, function ($s) use ($owneruserid) {
            return $s->owneruserid === $owneruserid;
        }));
        while (count($owned) >= self::limitof('maxsessionsperuser')) {
            $lru = self::lru_of($owned);
            self::delete_session($lru->previewid);
            $owned = array_values(array_filter($owned, function ($s) use ($lru) {
                return $s->previewid !== $lru->previewid;
            }));
            $sessions = array_values(array_filter($sessions, function ($s) use ($lru) {
                return $s->previewid !== $lru->previewid;
            }));
        }

        // Global ceiling: evict the least-recently-accessed session(s) overall.
        $total = array_sum(array_map(function ($s) {
            return $s->bytes;
        }, $sessions));
        while ($total > self::limitof('globalmaxbytes') && !empty($sessions)) {
            $lru = self::lru_of($sessions);
            self::delete_session($lru->previewid);
            $total -= $lru->bytes;
            $sessions = array_values(array_filter($sessions, function ($s) use ($lru) {
                return $s->previewid !== $lru->previewid;
            }));
        }
    }

    /**
     * Evict other sessions (never $currentid) in LRU order until $incoming bytes
     * fits under the global ceiling. Returns false when it cannot fit even after
     * evicting every other session.
     *
     * @param string $currentid The session that must be preserved.
     * @param int $currentbytes Bytes the current session already holds.
     * @param int $incoming Additional bytes to accommodate.
     * @return bool
     */
    private static function evict_others_for_budget(string $currentid, int $currentbytes, int $incoming): bool {
        $others = array_values(array_filter(self::enumerate(), function ($s) use ($currentid) {
            return $s->previewid !== $currentid;
        }));
        $othersbytes = array_sum(array_map(function ($s) {
            return $s->bytes;
        }, $others));
        while ($currentbytes + $othersbytes + $incoming > self::limitof('globalmaxbytes')) {
            if (empty($others)) {
                return false;
            }
            $lru = self::lru_of($others);
            self::delete_session($lru->previewid);
            $othersbytes -= $lru->bytes;
            $others = array_values(array_filter($others, function ($s) use ($lru) {
                return $s->previewid !== $lru->previewid;
            }));
        }
        return true;
    }

    /**
     * The session with the oldest access time among $candidates.
     *
     * @param array $candidates Session stdClass records to choose the LRU from.
     * @return \stdClass
     */
    private static function lru_of(array $candidates): \stdClass {
        $lru = null;
        foreach ($candidates as $candidate) {
            if ($lru === null || $candidate->lastaccess < $lru->lastaccess) {
                $lru = $candidate;
            }
        }
        return $lru;
    }

    /**
     * Enumerate every stored session (diagnostics for eviction and the sweeper).
     *
     * @return array<int,\stdClass> Objects with {previewid, dir, owneruserid, lastaccess, bytes}.
     */
    private static function enumerate(): array {
        $base = self::base_dir_path();
        if (!is_dir($base)) {
            return [];
        }
        $result = [];
        foreach (scandir($base) as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match(serving::UUID_RE, $entry)) {
                continue;
            }
            $dir = $base . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            $meta = self::read_json($dir . '/meta.json');
            if (!is_array($meta) || !isset($meta['owneruserid'])) {
                continue;
            }
            $accessfile = $dir . '/access';
            $item = new \stdClass();
            $item->previewid = $entry;
            $item->dir = $dir;
            $item->owneruserid = (int) $meta['owneruserid'];
            $item->lastaccess = is_file($accessfile) ? (int) filemtime($accessfile) : 0;
            $item->bytes = (int) ($meta['assetbytes'] ?? 0) + (int) ($meta['documentbytes'] ?? 0);
            $result[] = $item;
        }
        return $result;
    }

    // Filesystem helpers.

    /**
     * Storage-root path (no creation): the test override or the tempdir subtree.
     *
     * @return string
     */
    private static function base_dir_path(): string {
        global $CFG;
        if (self::$rootoverride !== null) {
            return self::$rootoverride;
        }
        return $CFG->tempdir . '/mod_exelearning/preview-sessions';
    }

    /**
     * Ensure the storage root exists and return it.
     *
     * @return string
     */
    private static function ensure_base_dir(): string {
        if (self::$rootoverride !== null) {
            self::make_dir(self::$rootoverride);
            return self::$rootoverride;
        }
        return make_temp_directory('mod_exelearning/preview-sessions');
    }

    /**
     * Create a directory (recursively) if it does not already exist.
     *
     * @param string $dir
     */
    private static function make_dir(string $dir): void {
        global $CFG;
        if (!is_dir($dir)) {
            mkdir($dir, $CFG->directorypermissions ?? 0777, true);
        }
    }

    /**
     * Whether the session at $dir has exceeded the idle TTL.
     *
     * @param string $dir
     * @return bool
     */
    private static function is_expired(string $dir): bool {
        $accessfile = $dir . '/access';
        if (!is_file($accessfile)) {
            return true;
        }
        return (time() - (int) filemtime($accessfile)) > self::limitof('ttl');
    }

    /**
     * Touch the idle-TTL clock for the session at $dir.
     *
     * @param string $dir
     */
    private static function touch_access(string $dir): void {
        touch($dir . '/access');
    }

    /**
     * Read the active revision number from the `current` pointer (0 when absent).
     *
     * @param string $dir
     * @return int
     */
    private static function read_current(string $dir): int {
        $pointer = $dir . '/current';
        if (!is_file($pointer)) {
            return 0;
        }
        return (int) trim((string) @file_get_contents($pointer));
    }

    /**
     * Read a revision's document map (path → size).
     *
     * @param string $dir
     * @param int $revision
     * @return array<string,int>
     */
    private static function read_revision_documents(string $dir, int $revision): array {
        $parsed = self::read_json($dir . '/revisions/' . $revision . '/revision.json');
        return (is_array($parsed) && isset($parsed['documents']) && is_array($parsed['documents']))
            ? $parsed['documents'] : [];
    }

    /**
     * List every published revision number under a session directory.
     *
     * @param string $dir
     * @return int[]
     */
    private static function list_revision_numbers(string $dir): array {
        $revisions = $dir . '/revisions';
        if (!is_dir($revisions)) {
            return [];
        }
        $numbers = [];
        foreach (scandir($revisions) as $entry) {
            if (ctype_digit($entry)) {
                $numbers[] = (int) $entry;
            }
        }
        return $numbers;
    }

    /**
     * Read and JSON-decode a file (associative), or [] when unreadable.
     *
     * @param string $path
     * @return array
     */
    private static function read_json(string $path): array {
        $json = @file_get_contents($path);
        if ($json === false) {
            return [];
        }
        $parsed = json_decode($json, true);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * JSON-encode and write a file (exclusive lock).
     *
     * @param string $path
     * @param array $data
     */
    private static function write_json(string $path, array $data): void {
        file_put_contents($path, json_encode($data), LOCK_EX);
    }

    /**
     * Write the `current` pointer atomically (write-temp then rename).
     *
     * @param string $path
     * @param string $value
     */
    private static function write_pointer(string $path, string $value): void {
        $tmp = $path . '.tmp.' . random_string(8);
        file_put_contents($tmp, $value, LOCK_EX);
        rename($tmp, $path);
    }

    /**
     * Acquire the per-session mutation lock.
     *
     * @param string $previewid
     * @return \core\lock\lock
     * @throws \moodle_exception When the lock cannot be acquired.
     */
    private static function acquire_lock(string $previewid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_TYPE);
        $lock = $factory->get_lock('preview_' . $previewid, self::LOCK_TIMEOUT);
        if ($lock === false) {
            throw new \moodle_exception('previewsessionbusy', 'mod_exelearning');
        }
        return $lock;
    }
}
