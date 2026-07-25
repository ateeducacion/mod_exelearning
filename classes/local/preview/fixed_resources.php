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

use mod_exelearning\local\editor_paths;
use mod_exelearning\local\embedded_editor_source_resolver;

/**
 * Fixed-resource resolver for the editor preview (serving contract v2, layer 1).
 *
 * The eXeLearning build emits bundles/preview-fixed-resources.json inside the
 * static editor distribution (the same distribution this plugin installs into
 * moodledata or ships bundled). It enumerates every installation-immutable file
 * the preview serving route may resolve OUTSIDE a session — libraries, base
 * iDevice runtimes, base themes, PDF.js, the content CSS, the logo, global
 * fonts. This class is the ONLY path from a fixedResourceId to the filesystem:
 *
 * - ids resolve by EXACT map lookup — client input never becomes a path;
 * - only resources[id].path (server-controlled build output) reaches the
 *   filesystem, resolved under the distribution root with a strict containment
 *   check (which also rejects symlink escapes via realpath());
 * - unknown ids are a lookup miss, never a filesystem probe.
 *
 * When the manifest is absent (older editor build, or none installed) the fixed
 * layer is DISABLED gracefully: has_resource() is always false, so a revision
 * carrying fixedRefs gets a 422 unknown-fixed-resources and the editor client
 * demotes those paths to document writes and retries. The route never fatals.
 *
 * The distribution root and manifest path are resolved lazily and cached;
 * {@see self::configure()} (tests, or a relocated distribution) resets the cache.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixed_resources {
    /** @var string|null Distribution-root override (tests / relocated install). */
    private static $rootoverride = null;

    /** @var string|null Manifest-path override (tests / relocated install). */
    private static $manifestoverride = null;

    /** @var array<string,array{path:string,size:int}>|null Lazily loaded manifest cache. */
    private static $cache = null;

    /**
     * Point the resolver at an explicit distribution root and manifest path.
     *
     * @param string|null $root Distribution root every manifest path resolves under.
     * @param string|null $manifestpath Absolute path to preview-fixed-resources.json.
     */
    public static function configure(?string $root, ?string $manifestpath): void {
        self::$rootoverride = $root;
        self::$manifestoverride = $manifestpath;
        self::$cache = null;
    }

    /**
     * Restore the default (installed static editor) resolution and clear the cache.
     */
    public static function reset(): void {
        self::$rootoverride = null;
        self::$manifestoverride = null;
        self::$cache = null;
    }

    /**
     * The distribution root: the bundled static editor directory, or the test
     * override. Null when the release package ships no usable editor.
     *
     * @return string|null
     */
    private static function dist_root(): ?string {
        if (self::$rootoverride !== null) {
            return self::$rootoverride;
        }
        // Was get_active_dir() while an admin-installed copy in moodledata was
        // still a source; since DEC-0065 the bundle is the only one.
        return embedded_editor_source_resolver::get_editor_dir();
    }

    /**
     * Absolute path to the fixed-resource manifest, or null when unresolvable.
     *
     * @return string|null
     */
    private static function manifest_path(): ?string {
        if (self::$manifestoverride !== null) {
            return self::$manifestoverride;
        }
        $root = self::dist_root();
        if ($root === null) {
            return null;
        }
        return $root . '/bundles/preview-fixed-resources.json';
    }

    /**
     * Load and validate the manifest once. A missing or invalid manifest resolves
     * to an empty map (the fixed layer is disabled but the route keeps serving).
     *
     * @return array<string,array{path:string,size:int}>
     */
    private static function load(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $map = [];
        $manifestpath = self::manifest_path();
        if ($manifestpath !== null && is_file($manifestpath)) {
            $json = @file_get_contents($manifestpath);
            $parsed = ($json !== false) ? json_decode($json, true) : null;
            $valid = is_array($parsed)
                && ($parsed['schemaVersion'] ?? null) === 1
                && isset($parsed['resources'])
                && is_array($parsed['resources']);
            if ($valid) {
                foreach ($parsed['resources'] as $id => $entry) {
                    if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                        $map[(string) $id] = ['path' => $entry['path'], 'size' => (int) ($entry['size'] ?? 0)];
                    }
                }
            }
        }
        self::$cache = $map;
        return $map;
    }

    /**
     * Exact-id membership test (the apply_revision validation hook).
     *
     * @param string $id Fixed resource id.
     * @return bool True when the manifest lists this id.
     */
    public static function has_resource(string $id): bool {
        return array_key_exists($id, self::load());
    }

    /**
     * All ids the manifest declares (diagnostics / conformance tooling).
     *
     * @return string[]
     */
    public static function get_manifest_ids(): array {
        return array_keys(self::load());
    }

    /**
     * Resolve a fixed resource id to its bytes. Returns null for unknown ids,
     * manifest paths escaping the distribution root (path arithmetic or symlink
     * escape, caught by realpath + containment), and missing files. The only
     * client-supplied value here is the exact-match id; the path is manifest data.
     *
     * @param string $id Fixed resource id.
     * @return \stdClass|null Object with {bytes:string, size:int}, or null.
     */
    public static function get_resource(string $id): ?\stdClass {
        $map = self::load();
        if (!isset($map[$id])) {
            return null;
        }
        $root = self::dist_root();
        if ($root === null) {
            return null;
        }
        $realroot = realpath($root);
        if ($realroot === false) {
            return null;
        }
        // The realpath() call collapses '..' and follows symlinks; the containment check
        // then rejects anything (absolute paths, traversal, symlink escapes)
        // that resolved outside the distribution root.
        $resolved = realpath($realroot . '/' . $map[$id]['path']);
        if ($resolved === false || !editor_paths::is_within($resolved, $realroot)) {
            return null;
        }
        $bytes = @file_get_contents($resolved);
        if ($bytes === false) {
            return null;
        }
        $result = new \stdClass();
        $result->bytes = $bytes;
        $result->size = strlen($bytes);
        return $result;
    }
}
