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

use advanced_testcase;

/**
 * Unit tests for the file-backed preview session store and its session handle.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\session_store
 * @covers     \mod_exelearning\local\preview\preview_session
 */
final class session_store_test extends advanced_testcase {
    /** @var string A valid photo assetKey. */
    private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

    /** @var string A valid clip assetKey. */
    private const CLIP_KEY = '12345678-90ab-4cde-8f01-234567890abc@00112233';

    /** @var int The owning user's id. */
    private $userid = 4242;

    /** @var string The current session's previewId. */
    private $previewid;

    /** @var string The store root for this test. */
    private $root;

    /**
     * Isolate the store under a throwaway root and create one session.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->root = make_request_directory();
        session_store::set_root_for_testing($this->root);
        $this->previewid = session_store::create_session($this->userid);
    }

    /**
     * Reset store overrides after each test.
     */
    protected function tearDown(): void {
        session_store::reset_root_for_testing();
        session_store::reset_limits_for_testing();
        session_store::reset_enumerate_count_for_testing();
        fixed_resources::reset();
        parent::tearDown();
    }

    /**
     * Reload a fresh session handle (statelessness: each request re-reads meta).
     *
     * @return preview_session
     */
    private function reload(): preview_session {
        $owned = session_store::get_owned($this->previewid, $this->userid);
        $this->assertSame(200, $owned['status']);
        return $owned['session'];
    }

    /**
     * Store assets by (key => bytes), computing declared sizes honestly.
     *
     * @param array $assets Map of assetKey => raw bytes.
     * @return array
     */
    private function store(array $assets): array {
        $entries = [];
        foreach ($assets as $key => $bytes) {
            $entries[] = ['key' => $key, 'declaredsize' => strlen($bytes), 'bytes' => $bytes];
        }
        return session_store::store_assets($this->reload(), $entries);
    }

    /**
     * Publish a revision from writes(path => bytes) and the given meta.
     *
     * @param int $base
     * @param int $next
     * @param array $writes Map of served path => document bytes.
     * @param array $assetrefs Map of served path => assetKey.
     * @param array $fixedrefs Map of served path => fixedResourceId.
     * @param string[] $deletes
     * @return array
     */
    private function publish(
        int $base,
        int $next,
        array $writes,
        array $assetrefs = [],
        array $fixedrefs = [],
        array $deletes = []
    ): array {
        $bufferedwrites = [];
        foreach ($writes as $path => $bytes) {
            $bufferedwrites[] = ['path' => $path, 'bytes' => $bytes];
        }
        return session_store::apply_revision($this->reload(), [
            'baserevision' => $base,
            'nextrevision' => $next,
            'deletes' => $deletes,
            'assetrefs' => $assetrefs,
            'fixedrefs' => $fixedrefs,
        ], $bufferedwrites);
    }

    /**
     * A fresh session has a UUID previewId, revision 0 and serves nothing.
     */
    public function test_create_session_starts_empty(): void {
        $this->assertMatchesRegularExpression(serving::UUID_RE, $this->previewid);
        $session = $this->reload();
        $this->assertSame(0, $session->revision);
        $this->assertNull($session->get_file('index.html'));
    }

    /**
     * Assets store once; a re-upload with different bytes is reported
     * alreadyStored and the original bytes keep serving (immutability).
     */
    public function test_asset_store_and_immutability(): void {
        $result = $this->store([self::PHOTO_KEY => 'PHOTO-BYTES-v1', self::CLIP_KEY => '0123456789']);
        $this->assertSame([self::PHOTO_KEY, self::CLIP_KEY], $result['stored']);
        $this->assertSame([], $result['alreadyStored']);
        $this->assertSame([], $result['rejected']);

        $reupload = $this->store([self::PHOTO_KEY => 'PHOTO-BYTES-v2-DIFFERENT']);
        $this->assertSame([], $reupload['stored']);
        $this->assertSame([self::PHOTO_KEY], $reupload['alreadyStored']);

        // Reference the asset from a revision and confirm the ORIGINAL bytes serve.
        $this->publish(0, 1, ['index.html' => '<p>ok</p>'], ['res/photo.png' => self::PHOTO_KEY]);
        $file = $this->reload()->get_file('res/photo.png');
        $this->assertSame('asset', $file->kind);
        $this->assertSame('PHOTO-BYTES-v1', $file->bytes);
        $this->assertSame(self::PHOTO_KEY, $file->etag);
        $this->assertSame('image/png', $file->contenttype);
    }

    /**
     * Per-entry asset rejections carry a reason and never store the bytes.
     */
    public function test_asset_rejections(): void {
        // Malformed key.
        $badkey = $this->store(['not-a-valid-key' => 'x']);
        $this->assertSame([['key' => 'not-a-valid-key', 'reason' => 'invalid-key']], $badkey['rejected']);

        // Declared size that disagrees with the actual bytes.
        $mismatch = session_store::store_assets($this->reload(), [
            ['key' => self::PHOTO_KEY, 'declaredsize' => 999, 'bytes' => 'short'],
        ]);
        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'size-mismatch']], $mismatch['rejected']);

        // Per-asset byte cap.
        session_store::set_limits_for_testing(['maxassetbytes' => 4]);
        $toobig = $this->store([self::CLIP_KEY => '0123456789']);
        $this->assertSame([['key' => self::CLIP_KEY, 'reason' => 'asset-too-large']], $toobig['rejected']);
    }

    /**
     * The per-session byte budget rejects an asset that would overflow it.
     */
    public function test_asset_session_budget(): void {
        session_store::set_limits_for_testing(['maxbytespersession' => 5]);
        $result = $this->store([self::PHOTO_KEY => '0123456789']);
        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'session-budget-exceeded']], $result['rejected']);
    }

    /**
     * A happy revision serves generated documents (no-store, HTML scriptable).
     */
    public function test_revision_publishes_documents(): void {
        $result = $this->publish(0, 1, [
            'index.html' => '<html><body>hi</body></html>',
            'img/inline.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ]);
        $this->assertTrue($result['active']);
        $this->assertSame(1, $result['revision']);

        $session = $this->reload();
        $this->assertSame(1, $session->revision);
        $index = $session->get_file('index.html');
        $this->assertSame('document', $index->kind);
        $this->assertSame('<html><body>hi</body></html>', $index->bytes);
        $this->assertTrue($index->scriptable);

        // An SVG document is scriptable too (the "open image in a new tab" hole).
        $this->assertTrue($session->get_file('img/inline.svg')->scriptable);
    }

    /**
     * A stale baseRevision conflicts (409) with the current revision.
     */
    public function test_revision_conflict(): void {
        $this->publish(0, 1, ['index.html' => 'v1']);
        $conflict = $this->publish(0, 1, ['index.html' => 'stale']);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame(1, $conflict['currentrevision']);
    }

    /**
     * A non-consecutive nextRevision also conflicts.
     */
    public function test_revision_nonconsecutive_conflict(): void {
        $conflict = $this->publish(0, 5, ['index.html' => 'x']);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame(0, $conflict['currentrevision']);
    }

    /**
     * An unsafe path anywhere in the revision rejects the whole revision (400).
     */
    public function test_revision_unsafe_path(): void {
        $result = $this->publish(0, 1, ['../escape.html' => 'x']);
        $this->assertSame(400, $result['status']);

        $refescape = $this->publish(0, 1, ['index.html' => 'x'], ['../a.png' => self::PHOTO_KEY]);
        $this->assertSame(400, $refescape['status']);
    }

    /**
     * A revision referencing an asset the store never received is 422.
     */
    public function test_revision_missing_assets(): void {
        $ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
        $result = $this->publish(0, 1, ['index.html' => 'x'], ['res/ghost.png' => $ghost]);
        $this->assertSame(422, $result['status']);
        $this->assertSame('missing-assets', $result['reason']);
        $this->assertSame([$ghost], $result['missing']);
    }

    /**
     * A revision referencing an unknown fixed resource is 422 (client demotes).
     */
    public function test_revision_unknown_fixed(): void {
        $result = $this->publish(0, 1, ['index.html' => 'x'], [], ['libs/x.js' => 'libs/x.js']);
        $this->assertSame(422, $result['status']);
        $this->assertSame('unknown-fixed-resources', $result['reason']);
        $this->assertSame(['libs/x.js'], $result['resources']);
    }

    /**
     * The per-session file-count budget rejects an oversized revision (413).
     */
    public function test_revision_file_count_budget(): void {
        session_store::set_limits_for_testing(['maxfilespersession' => 2]);
        $result = $this->publish(0, 1, ['a.html' => '1', 'b.html' => '2', 'c.html' => '3']);
        $this->assertSame(413, $result['status']);
    }

    /**
     * The per-session byte budget rejects a document-heavy revision (413).
     */
    public function test_revision_byte_budget(): void {
        session_store::set_limits_for_testing(['maxbytespersession' => 4]);
        $result = $this->publish(0, 1, ['a.html' => 'too-many-bytes']);
        $this->assertSame(413, $result['status']);
    }

    /**
     * Unchanged documents copy forward across revisions; deletes drop them.
     */
    public function test_incremental_revisions_copy_forward_and_delete(): void {
        $this->publish(0, 1, ['index.html' => 'INDEX-1', 'keep.html' => 'KEEP']);

        // Revision 2 rewrites index and leaves keep untouched (delta upload).
        $this->publish(1, 2, ['index.html' => 'INDEX-2']);
        $session = $this->reload();
        $this->assertSame('INDEX-2', $session->get_file('index.html')->bytes);
        $this->assertSame('KEEP', $session->get_file('keep.html')->bytes, 'unchanged doc copied forward');

        // Revision 3 deletes keep.
        $this->publish(2, 3, [], [], [], ['keep.html']);
        $session = $this->reload();
        $this->assertNull($session->get_file('keep.html'));
        $this->assertSame('INDEX-2', $session->get_file('index.html')->bytes);
    }

    /**
     * The fixed layer resolves through the installed manifest; get_file returns
     * the fixed bytes and marks the resource kind.
     */
    public function test_fixed_layer_resolution(): void {
        $dist = make_request_directory();
        mkdir($dist . '/libs', 0777, true);
        mkdir($dist . '/bundles', 0777, true);
        file_put_contents($dist . '/libs/jquery.js', 'JQ');
        file_put_contents($dist . '/bundles/preview-fixed-resources.json', json_encode([
            'schemaVersion' => 1,
            'resources' => ['libs/jquery.js' => ['path' => 'libs/jquery.js', 'size' => 2]],
        ]));
        fixed_resources::configure($dist, $dist . '/bundles/preview-fixed-resources.json');

        $this->publish(0, 1, ['index.html' => 'x'], [], ['vendor/jquery.js' => 'libs/jquery.js']);
        $file = $this->reload()->get_file('vendor/jquery.js');
        $this->assertSame('fixed', $file->kind);
        $this->assertSame('JQ', $file->bytes);
    }

    /**
     * get_file rejects traversal without touching the filesystem.
     */
    public function test_get_file_rejects_traversal(): void {
        $this->publish(0, 1, ['index.html' => 'x']);
        $this->assertNull($this->reload()->get_file('../../etc/passwd'));
        $this->assertNull($this->reload()->get_file('%2e%2e%2fsecret'));
    }

    /**
     * An idle-expired session serves nothing and is reaped on access.
     */
    public function test_idle_ttl_expiry(): void {
        touch($this->root . '/' . $this->previewid . '/access', time() - 2000);
        $this->assertNull(session_store::get_for_serving($this->previewid));
        // The opportunistic check deleted it.
        $this->assertSame(404, session_store::get_owned($this->previewid, $this->userid)['status']);
    }

    /**
     * The scheduled sweep reaps expired sessions and leaves fresh ones.
     */
    public function test_sweep_expired(): void {
        $fresh = session_store::create_session($this->userid);
        touch($this->root . '/' . $this->previewid . '/access', time() - 2000);
        $this->assertSame(1, session_store::sweep_expired());
        $this->assertNotNull(session_store::get_for_serving($fresh));
        $this->assertNull(session_store::get_for_serving($this->previewid));
    }

    /**
     * Creating past the per-user cap LRU-evicts the owner's oldest session.
     */
    public function test_per_user_session_cap(): void {
        session_store::set_limits_for_testing(['maxsessionsperuser' => 2]);
        $second = session_store::create_session($this->userid);
        // Make the first session the least-recently accessed, then create a third.
        touch($this->root . '/' . $this->previewid . '/access', time() - 100);
        touch($this->root . '/' . $second . '/access', time() - 50);
        $third = session_store::create_session($this->userid);

        $this->assertSame(404, session_store::get_owned($this->previewid, $this->userid)['status']);
        $this->assertSame(200, session_store::get_owned($second, $this->userid)['status']);
        $this->assertSame(200, session_store::get_owned($third, $this->userid)['status']);
    }

    /**
     * A session is owner-scoped: another user gets 403, not the session.
     */
    public function test_ownership_scoping(): void {
        $owned = session_store::get_owned($this->previewid, $this->userid + 1);
        $this->assertSame(403, $owned['status']);
        $this->assertNull($owned['session']);
    }

    /**
     * Deleting a session kills the capability URL; a second delete is a no-op.
     */
    public function test_delete_session(): void {
        $this->publish(0, 1, ['index.html' => 'x']);
        $this->assertTrue(session_store::delete_session($this->previewid));
        $this->assertNull(session_store::get_for_serving($this->previewid));
        $this->assertFalse(session_store::delete_session($this->previewid));
    }

    /**
     * A failed asset write is REJECTED (reason write-failed) and never indexed:
     * the key is not added to the index or the byte counter, is not reported in
     * stored, and a later revision referencing it 422s on missing-assets. Failure
     * is forced root-safely by pre-creating the asset's on-disk target as a
     * directory, so the byte write cannot succeed for any uid.
     */
    public function test_asset_write_failure_is_rejected_and_not_indexed(): void {
        $target = $this->root . '/' . $this->previewid . '/assets/'
            . session_store::asset_basename(self::PHOTO_KEY);
        mkdir($target, 0777, true);

        $result = $this->store([self::PHOTO_KEY => 'abc']);
        $this->assertSame([], $result['stored']);
        $this->assertSame([['key' => self::PHOTO_KEY, 'reason' => 'write-failed']], $result['rejected']);

        // Not indexed: a revision that references the key fails missing-assets.
        $missing = $this->publish(0, 1, ['index.html' => 'x'], ['res/photo.png' => self::PHOTO_KEY]);
        $this->assertSame(422, $missing['status']);
        $this->assertSame('missing-assets', $missing['reason']);
    }

    /**
     * When staging a revision cannot copy an unchanged document forward, the whole
     * publish aborts BEFORE the pointer swap: the store returns 500, the active
     * revision and its bytes are untouched, and the staged revision is discarded.
     * The copy is forced to fail root-safely by removing its source document.
     */
    public function test_revision_publish_aborts_on_doc_copy_failure(): void {
        $this->publish(0, 1, ['index.html' => 'I1', 'keep.html' => 'KEEP']);

        // Remove the carried-forward source so copy() fails while staging r2.
        $source = $this->root . '/' . $this->previewid . '/revisions/1/'
            . session_store::doc_basename('keep.html');
        unlink($source);

        $result = $this->publish(1, 2, ['index.html' => 'I2']);
        $this->assertSame(500, $result['status']);

        // No swap: still revision 1, still the original index bytes.
        $session = $this->reload();
        $this->assertSame(1, $session->revision);
        $this->assertSame('I1', $session->get_file('index.html')->bytes);
        // The staged revision directory was discarded.
        $this->assertDirectoryDoesNotExist($this->root . '/' . $this->previewid . '/revisions/2');
    }

    /**
     * When staging a revision cannot write a changed document, the publish aborts
     * BEFORE the pointer swap (500) and the active revision stays at 0. Failure is
     * forced root-safely by occupying the staged revision path with a plain file,
     * so both make_dir and the byte write fail; the make_dir warning is swallowed.
     */
    public function test_revision_publish_aborts_on_doc_write_failure(): void {
        $revdir = $this->root . '/' . $this->previewid . '/revisions/1';
        file_put_contents($revdir, 'blocker');

        $result = $this->suppress_warnings(function () {
            return $this->publish(0, 1, ['index.html' => 'x']);
        });
        $this->assertSame(500, $result['status']);
        $this->assertSame(0, $this->reload()->revision);
    }

    /**
     * When staging a revision cannot write revision.json, the publish aborts
     * BEFORE the pointer swap (500) and the active revision stays at 0 — a
     * revision must never go live with a missing manifest (every path would 404).
     * A zero-document revision makes revision.json the only write; occupying the
     * staged revision path with a plain file fails it root-safely.
     */
    public function test_revision_publish_aborts_on_manifest_write_failure(): void {
        $revdir = $this->root . '/' . $this->previewid . '/revisions/1';
        file_put_contents($revdir, 'blocker');

        $result = $this->suppress_warnings(function () {
            return $this->publish(0, 1, []);
        });
        $this->assertSame(500, $result['status']);
        $this->assertSame(0, $this->reload()->revision);
    }

    /**
     * serve() ignores a malformed / multi-range Range (a full 200, never 206 or
     * 416) but still 416s a syntactically valid unsatisfiable single range.
     */
    public function test_serve_ignores_malformed_range(): void {
        $this->store([self::PHOTO_KEY => '0123456789']);
        $this->publish(0, 1, ['index.html' => 'x'], ['media/clip.bin' => self::PHOTO_KEY]);
        $session = $this->reload();

        $ignored = serving::serve($session, 'media/clip.bin', ['range' => 'bytes=0-1,4-5']);
        $this->assertSame(200, $ignored['status']);
        $this->assertSame('0123456789', $ignored['body']);
        $this->assertArrayNotHasKey('Content-Range', $ignored['headers']);

        $unsatisfiable = serving::serve($session, 'media/clip.bin', ['range' => 'bytes=99-']);
        $this->assertSame(416, $unsatisfiable['status']);
    }

    /**
     * A multi-asset upload scans the store (enumerate/scandir) exactly ONCE for
     * the whole batch, not once per entry — the per-entry scan was O(entries ×
     * sessions) and would enumerate() five times for this five-asset batch.
     */
    public function test_store_assets_scans_the_store_once_per_batch(): void {
        $assets = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff' . sprintf('%04d', $i) . '@' . sprintf('%08d', $i);
            $assets[$key] = 'bytes-' . $i;
        }

        session_store::reset_enumerate_count_for_testing();
        $result = $this->store($assets);

        $this->assertCount(5, $result['stored']);
        $this->assertSame(1, session_store::enumerate_count_for_testing());
    }

    /**
     * Storing under a tight global ceiling still LRU-evicts other sessions from
     * the once-per-batch snapshot (behaviour preserved by the O(1)-per-entry
     * refactor): a second session's asset fits only after the older session is
     * evicted.
     */
    public function test_store_assets_evicts_lru_under_global_ceiling(): void {
        session_store::set_limits_for_testing(['globalmaxbytes' => 12]);

        // Session A holds 8 bytes and is made the least-recently-accessed.
        $this->store([self::PHOTO_KEY => '01234567']);
        touch($this->root . '/' . $this->previewid . '/access', time() - 100);

        // Session B stores 8 bytes: 8 (B) + 8 (A) = 16 > 12, so A must be evicted.
        $b = session_store::create_session($this->userid);
        $result = session_store::store_assets(
            session_store::get_owned($b, $this->userid)['session'],
            [['key' => self::CLIP_KEY, 'declaredsize' => 8, 'bytes' => '89abcdef']]
        );

        $this->assertSame([self::CLIP_KEY], $result['stored']);
        $this->assertSame(404, session_store::get_owned($this->previewid, $this->userid)['status']);
        $this->assertSame(200, session_store::get_owned($b, $this->userid)['status']);
    }

    /**
     * Run $fn with PHP warnings/notices swallowed. A forced filesystem failure
     * raises an unrelated make_dir warning that phpunit --fail-on-warning would
     * otherwise turn into a failure; the code under test handles the real error
     * through its checked return, not the warning.
     *
     * @param callable $fn
     * @return mixed
     */
    private function suppress_warnings(callable $fn) {
        set_error_handler(static function () {
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
