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

namespace mod_exelearning\local\scorm;

use advanced_testcase;

/**
 * The vendored SCORM 1.2 runtime must stay a complete, stamped, unmodified copy
 * of what eXeLearning ships inside its own packages.
 *
 * This plugin installs these two files into every package it extracts, so they
 * decide the marks. A copy that drifts from an eXeLearning release — a dropped
 * layer, a local patch, a missing stamp — grades differently from the editor
 * that produced the content, and nothing else in the plugin would notice.
 * These assertions are cheap and they are the only thing standing between this
 * folder and the four-layer subset it used to carry.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class scorm_runtime_test extends advanced_testcase {
    /** @var string Directory holding the vendored runtime pair. */
    private const ASSETS = __DIR__ . '/../../../assets/scorm';

    /**
     * Read one vendored runtime file.
     *
     * @param string $name File name inside assets/scorm/.
     * @return string File contents.
     */
    private function asset(string $name): string {
        $path = self::ASSETS . '/' . $name;
        $this->assertFileExists($path, "assets/scorm/$name is missing; the plugin cannot grade without it");
        return (string) file_get_contents($path);
    }

    /**
     * Both files of the pair are present. Half a runtime is worse than none:
     * the wrapper and SCOFunctions.js are written against each other.
     */
    public function test_the_runtime_pair_is_complete(): void {
        $this->assertNotSame('', $this->asset('SCORM_API_wrapper.js'));
        $this->assertNotSame('', $this->asset('SCOFunctions.js'));
    }

    /**
     * All five layers are present, in upstream's load order.
     *
     * The plugin used to ship four of them, deliberately dropping the activity
     * registry. That subset matched no eXeLearning release and had to be
     * assembled and patched by hand on every update.
     */
    public function test_all_five_runtime_layers_are_present_in_order(): void {
        $source = $this->asset('SCOFunctions.js');
        $layers = [
            'exe-scorm12-client.js',
            'exe-scorm12-activities.js',
            'exe-scorm12-policy.js',
            'exe-scorm12-lifecycle.js',
            'exe-scorm12-adapter.js',
        ];

        $previous = -1;
        foreach ($layers as $layer) {
            $position = strpos($source, "/* ==== $layer ==== */");
            $this->assertNotFalse($position, "the $layer layer is missing from the vendored runtime");
            $this->assertGreaterThan($previous, $position, "the $layer layer is out of upstream's load order");
            $previous = $position;
        }
    }

    /**
     * The copy says which eXeLearning release it came from, both as a header
     * line and as a value the runtime exposes. Without it, "is this copy up to
     * date?" has no answer.
     */
    public function test_the_runtime_declares_the_exelearning_version_it_came_from(): void {
        $source = $this->asset('SCOFunctions.js');

        $this->assertMatchesRegularExpression(
            '/^ \* eXeLearning-SCORM12-Runtime: (?!unknown\s*$).+$/m',
            $source,
            'the vendored runtime carries no eXeLearning version stamp, or an unknown one'
        );
        $this->assertMatchesRegularExpression(
            '/ns\.runtimeVersion = "(?!unknown")[^"]+";/',
            $source,
            'the vendored runtime does not expose exeScorm12.runtimeVersion'
        );
    }

    /**
     * No local edits. Upstream marks its own changes; ours would be invisible,
     * so the marker itself is what is banned.
     */
    public function test_the_runtime_carries_no_local_patch(): void {
        $source = $this->asset('SCOFunctions.js');

        $this->assertStringNotContainsString(
            'MOODLE LOCAL CHANGE',
            $source,
            'the vendored runtime has been patched locally; fix it upstream instead'
        );
    }

    /**
     * The wrapper is the unmodified upstream pipwerks library, as
     * thirdpartylibs.xml declares.
     */
    public function test_the_wrapper_is_the_upstream_pipwerks_library(): void {
        $source = $this->asset('SCORM_API_wrapper.js');

        $this->assertStringContainsString('pipwerks', $source);
        $this->assertStringNotContainsString('MOODLE LOCAL CHANGE', $source);
    }
}
