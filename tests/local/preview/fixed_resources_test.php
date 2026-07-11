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
 * Unit tests for the fixed-resource manifest resolver (contract v2, layer 1).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\fixed_resources
 */
final class fixed_resources_test extends advanced_testcase {
    /** @var string Throwaway distribution root. */
    private $root;

    /**
     * Materialize a throwaway distribution root with two fixed resources and a
     * manifest, and point the resolver at it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->root = make_request_directory();
        mkdir($this->root . '/libs/jquery', 0777, true);
        mkdir($this->root . '/bundles', 0777, true);
        file_put_contents($this->root . '/libs/jquery/jquery.min.js', 'window.jQuery=function(){};');
        $manifest = [
            'schemaVersion' => 1,
            'buildVersion' => 'v-test',
            'resources' => [
                'libs/jquery/jquery.min.js' => ['path' => 'libs/jquery/jquery.min.js', 'size' => 27],
                'ghost' => ['path' => 'libs/missing-file.js', 'size' => 0],
                'escape' => ['path' => '../outside.txt', 'size' => 0],
            ],
        ];
        file_put_contents($this->root . '/bundles/preview-fixed-resources.json', json_encode($manifest));
        fixed_resources::configure($this->root, $this->root . '/bundles/preview-fixed-resources.json');
    }

    /**
     * Reset the resolver cache/override after each test.
     */
    protected function tearDown(): void {
        fixed_resources::reset();
        parent::tearDown();
    }

    /**
     * A manifest-listed id resolves to its bytes; membership is exact-match.
     */
    public function test_known_resource_resolves(): void {
        $this->assertTrue(fixed_resources::has_resource('libs/jquery/jquery.min.js'));
        $resource = fixed_resources::get_resource('libs/jquery/jquery.min.js');
        $this->assertNotNull($resource);
        $this->assertSame('window.jQuery=function(){};', $resource->bytes);
        $this->assertSame(27, $resource->size);
        $this->assertContains('libs/jquery/jquery.min.js', fixed_resources::get_manifest_ids());
    }

    /**
     * An id absent from the manifest is a lookup miss, never a filesystem probe.
     */
    public function test_unknown_id_is_a_miss(): void {
        $this->assertFalse(fixed_resources::has_resource('not/in/manifest.js'));
        $this->assertNull(fixed_resources::get_resource('not/in/manifest.js'));
    }

    /**
     * A listed id whose file is absent on disk resolves to null (not a fatal).
     */
    public function test_listed_but_missing_file_is_null(): void {
        $this->assertTrue(fixed_resources::has_resource('ghost'));
        $this->assertNull(fixed_resources::get_resource('ghost'));
    }

    /**
     * A manifest path escaping the distribution root is rejected by containment,
     * even when the target exists.
     */
    public function test_path_escaping_root_is_rejected(): void {
        file_put_contents(dirname($this->root) . '/outside.txt', 'secret');
        $this->assertTrue(fixed_resources::has_resource('escape'));
        $this->assertNull(fixed_resources::get_resource('escape'));
    }

    /**
     * With no manifest present the fixed layer is disabled gracefully: every
     * lookup misses, so a revision's fixedRefs get a 422 and the client demotes.
     */
    public function test_absent_manifest_disables_layer(): void {
        fixed_resources::configure($this->root, $this->root . '/bundles/does-not-exist.json');
        $this->assertFalse(fixed_resources::has_resource('libs/jquery/jquery.min.js'));
        $this->assertSame([], fixed_resources::get_manifest_ids());
        $this->assertNull(fixed_resources::get_resource('libs/jquery/jquery.min.js'));
    }

    /**
     * A manifest with the wrong schema version is treated as absent.
     */
    public function test_wrong_schema_version_is_ignored(): void {
        $bad = ['schemaVersion' => 99, 'resources' => ['x' => ['path' => 'libs/jquery/jquery.min.js']]];
        file_put_contents($this->root . '/bundles/preview-fixed-resources.json', json_encode($bad));
        fixed_resources::configure($this->root, $this->root . '/bundles/preview-fixed-resources.json');
        $this->assertFalse(fixed_resources::has_resource('x'));
    }
}
