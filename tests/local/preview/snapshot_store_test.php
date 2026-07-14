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
 * Tests for complete opaque preview snapshots.
 *
 * @package    mod_exelearning
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\snapshot_store
 */
final class snapshot_store_test extends \advanced_testcase {
    /** @var string Test storage root. */
    private string $root;

    /** @var list<string> Temporary ZIP files. */
    private array $zipfiles = [];

    protected function setUp(): void {
        parent::setUp();
        $this->root = make_request_directory() . '/preview';
    }

    protected function tearDown(): void {
        foreach ($this->zipfiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Complete snapshots are replaced atomically and remain owner scoped.
     */
    public function test_create_replace_serve_and_delete(): void {
        $store = new snapshot_store($this->root);
        $id = $store->replace(7, 42, $this->zip(['index.html' => 'first']));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame('first', $store->get($id, 'index.html')['content']);

        $store->replace(7, 42, $this->zip(['index.html' => 'second', 'app.js' => 'run()']), $id);
        $this->assertSame('second', $store->get($id, 'index.html')['content']);
        $this->assertSame('application/javascript; charset=utf-8', $store->get($id, 'app.js')['mimetype']);

        $this->expectException(\UnexpectedValueException::class);
        $store->delete(8, 42, $id);
    }

    /**
     * Traversal, missing entry points, and private metadata are not served.
     */
    public function test_rejects_unsafe_and_incomplete_snapshots(): void {
        $store = new snapshot_store($this->root);
        try {
            $store->replace(7, 42, $this->zip(['../index.html' => 'escape']));
            $this->fail('Traversal archive was accepted');
        } catch (\invalid_parameter_exception) {
            $this->assertTrue(true);
        }

        try {
            $store->replace(7, 42, $this->zip(['other.html' => 'missing']));
            $this->fail('Snapshot without index.html was accepted');
        } catch (\invalid_parameter_exception) {
            $this->assertTrue(true);
        }

        $id = $store->replace(7, 42, $this->zip(['index.html' => 'ok']));
        $this->assertNull($store->get($id, '%2e%2e/index.html'));
        $this->assertNull($store->get($id, '.metadata.json'));
    }

    /**
     * Scriptable MIME types receive a sandbox CSP and hardened headers.
     */
    public function test_security_response_policy(): void {
        $this->assertTrue(snapshot_store::is_scriptable('text/html; charset=utf-8'));
        $this->assertTrue(snapshot_store::is_scriptable('image/svg+xml'));
        $this->assertFalse(snapshot_store::is_scriptable('text/css'));
        $this->assertStringStartsWith('sandbox allow-scripts', snapshot_store::content_security_policy());
        $this->assertSame('nosniff', snapshot_store::response_headers()['X-Content-Type-Options']);
    }

    /**
     * Idle capabilities are removed before serving.
     */
    public function test_expiry(): void {
        $now = 1000;
        $store = new snapshot_store($this->root, static function () use (&$now): int {
            return $now;
        });
        $id = $store->replace(7, 42, $this->zip(['index.html' => 'ok']));
        $now = 2801;
        $this->assertNull($store->get($id, 'index.html'));
        $this->assertDirectoryDoesNotExist($this->root . '/' . $id);
    }

    /**
     * Build a temporary ZIP fixture.
     *
     * @param array<string,string> $files Archive contents.
     * @return string ZIP pathname.
     */
    private function zip(array $files): string {
        $path = tempnam(sys_get_temp_dir(), 'exe-preview-');
        $this->assertIsString($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
        }
        $zip->close();
        $this->zipfiles[] = $path;
        return $path;
    }
}
