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
use ZipArchive;

/**
 * Tests for the opaque preview snapshot store and its archive inspector.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\snapshot_store
 * @covers     \mod_exelearning\local\preview\zip_inspector
 */
final class snapshot_store_test extends advanced_testcase {
    /** @var string Scratch storage root. */
    private $root;

    /** @var int Authoring user. */
    private $userid = 42;

    /** @var int Course module the snapshots belong to. */
    private $cmid = 7;

    /**
     * Point the store at a scratch directory.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->root = make_request_directory();
        snapshot_store::set_root_for_testing($this->root);
    }

    /**
     * Drop the overrides.
     */
    protected function tearDown(): void {
        snapshot_store::reset_root_for_testing();
        snapshot_store::reset_limits_for_testing();
        parent::tearDown();
    }

    /**
     * Build a ZIP from a path => contents map.
     *
     * @param array $entries Path => contents.
     * @return string Pathname of the archive.
     */
    private function zip(array $entries): string {
        $path = make_request_directory() . '/snapshot.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    /**
     * A snapshot round-trips: stored under a capability and served back.
     */
    public function test_replace_stores_the_snapshot(): void {
        $result = snapshot_store::replace($this->userid, $this->cmid, $this->zip([
            'index.html' => 'hello',
            'assets/app.js' => 'run()',
        ]));

        $this->assertArrayHasKey('previewid', $result);
        $dir = snapshot_store::get_content_dir($result['previewid']);
        $this->assertNotNull($dir);
        $this->assertSame('hello', file_get_contents($dir . '/index.html'));
        $this->assertSame('run()', file_get_contents($dir . '/assets/app.js'));
    }

    /**
     * Replacing swaps the tree wholesale: files gone from the new ZIP disappear.
     */
    public function test_replace_is_a_whole_tree_swap(): void {
        $first = snapshot_store::replace($this->userid, $this->cmid, $this->zip([
            'index.html' => 'first',
            'stale.html' => 'gone next time',
        ]));
        $id = $first['previewid'];

        $second = snapshot_store::replace(
            $this->userid,
            $this->cmid,
            $this->zip(['index.html' => 'second']),
            $id
        );

        $this->assertSame($id, $second['previewid']);
        $dir = snapshot_store::get_content_dir($id);
        $this->assertSame('second', file_get_contents($dir . '/index.html'));
        $this->assertFileDoesNotExist($dir . '/stale.html');
    }

    /**
     * A snapshot belongs to one user and one activity.
     */
    public function test_replace_refuses_another_owner_or_module(): void {
        $id = snapshot_store::replace($this->userid, $this->cmid, $this->zip(['index.html' => 'ok']))['previewid'];

        $otheruser = snapshot_store::replace($this->userid + 1, $this->cmid, $this->zip(['index.html' => 'no']), $id);
        $othercm = snapshot_store::replace($this->userid, $this->cmid + 1, $this->zip(['index.html' => 'no']), $id);

        $this->assertSame('previewforbidden', $otheruser['error']);
        $this->assertSame('previewforbidden', $othercm['error']);
        $this->assertSame('ok', file_get_contents(snapshot_store::get_content_dir($id) . '/index.html'));
    }

    /**
     * Replacing an unknown capability is refused rather than silently created.
     */
    public function test_replace_refuses_an_unknown_capability(): void {
        $result = snapshot_store::replace(
            $this->userid,
            $this->cmid,
            $this->zip(['index.html' => 'ok']),
            'ffffffff-ffff-4fff-bfff-ffffffffffff'
        );

        $this->assertSame('missingpreview', $result['error']);
    }

    /**
     * Delete is owner-scoped and makes the capability unresolvable.
     */
    public function test_delete_is_owner_scoped(): void {
        $id = snapshot_store::replace($this->userid, $this->cmid, $this->zip(['index.html' => 'ok']))['previewid'];

        $this->assertFalse(snapshot_store::delete($id, $this->userid + 1, $this->cmid));
        $this->assertNotNull(snapshot_store::get_content_dir($id));

        $this->assertTrue(snapshot_store::delete($id, $this->userid, $this->cmid));
        $this->assertNull(snapshot_store::get_content_dir($id));
    }

    /**
     * An idle snapshot expires, and the sweep reclaims it.
     */
    public function test_idle_snapshots_expire(): void {
        $id = snapshot_store::replace($this->userid, $this->cmid, $this->zip(['index.html' => 'ok']))['previewid'];
        touch($this->root . '/' . $id . '/access', time() - snapshot_store::TTL_SECONDS - 60);

        $this->assertNull(snapshot_store::get_content_dir($id));
        $this->assertSame(1, snapshot_store::sweep_expired());
        $this->assertDirectoryDoesNotExist($this->root . '/' . $id);
    }

    /**
     * Serving a snapshot pushes its expiry back, so an in-use preview survives.
     */
    public function test_serving_refreshes_the_idle_clock(): void {
        $id = snapshot_store::replace($this->userid, $this->cmid, $this->zip(['index.html' => 'ok']))['previewid'];
        $access = $this->root . '/' . $id . '/access';
        touch($access, time() - snapshot_store::TTL_SECONDS + 120);

        $this->assertNotNull(snapshot_store::get_content_dir($id));
        $this->assertGreaterThan(time() - 5, filemtime($access));
    }

    /**
     * An archive without index.html is not a preview.
     */
    public function test_archive_must_carry_an_index(): void {
        $result = snapshot_store::replace($this->userid, $this->cmid, $this->zip(['page.html' => 'orphan']));

        $this->assertSame('previewmissingindex', $result['error']);
    }

    /**
     * Traversal escapes are refused before anything is written.
     */
    public function test_archive_paths_cannot_escape(): void {
        $result = snapshot_store::replace($this->userid, $this->cmid, $this->zip([
            'index.html' => 'ok',
            '../escape.html' => 'nope',
        ]));

        $this->assertSame('invalidpreviewpath', $result['error']);
        $this->assertFileDoesNotExist(dirname($this->root) . '/escape.html');
    }

    /**
     * The entry-count and total-size guards both fail closed.
     */
    public function test_limits_are_enforced(): void {
        snapshot_store::set_limits_for_testing(['maxfiles' => 1, 'maxbytes' => 1073741824]);
        $toomany = snapshot_store::replace($this->userid, $this->cmid, $this->zip([
            'index.html' => 'a',
            'b.html' => 'b',
        ]));
        $this->assertSame('previewtoomanyfiles', $toomany['error']);

        snapshot_store::set_limits_for_testing(['maxfiles' => 10000, 'maxbytes' => 8]);
        $toobig = snapshot_store::replace($this->userid, $this->cmid, $this->zip([
            'index.html' => str_repeat('x', 64),
        ]));
        $this->assertSame('previewtoolarge', $toobig['error']);
    }

    /**
     * A rejected upload leaves no staging directory behind.
     */
    public function test_a_rejected_upload_leaves_nothing_behind(): void {
        snapshot_store::replace($this->userid, $this->cmid, $this->zip(['page.html' => 'no index']));

        $leftovers = array_filter(scandir($this->root), function ($entry) {
            return strpos($entry, '.staging-') === 0;
        });
        $this->assertSame([], array_values($leftovers));
    }

    /**
     * A non-positive configured limit falls back to the default: the guard
     * cannot be switched off from the admin page.
     */
    public function test_limits_cannot_be_disabled(): void {
        set_config('previewmaxbytes', 0, 'mod_exelearning');
        set_config('previewmaxfiles', -1, 'mod_exelearning');

        $limits = snapshot_store::limits();

        $this->assertSame(snapshot_store::DEFAULT_MAX_BYTES, $limits['maxbytes']);
        $this->assertSame(snapshot_store::DEFAULT_MAX_FILES, $limits['maxfiles']);
    }
}
