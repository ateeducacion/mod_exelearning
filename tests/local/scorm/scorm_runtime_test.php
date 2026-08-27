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

    /**
     * The vendored runtime defines the host entry point the injected bootstrap calls.
     *
     * scorm_injector's bootstrap opens the session through
     * `exeScorm12.session.open({ ownsLifecycle: false })` and only falls back to
     * `pipwerks.SCORM.init()` when that entry never appears. That fallback is a silent
     * failure with this runtime: pipwerks' connection opens, the runtime's own client
     * stays idle and refuses every write with 301 before it reaches the LMS. A copy
     * taken from a build that predates session.open() would put every activity on that
     * path without a single test noticing — so the file itself is checked (DEC-105-02).
     */
    public function test_the_runtime_defines_the_host_entry_point_the_bootstrap_calls(): void {
        $source = $this->asset('SCOFunctions.js');

        $this->assertMatchesRegularExpression(
            '/exeScorm12\.session\s*=\s*\{\s*open:\s*function\s*\(/',
            $source,
            'the vendored runtime does not define exeScorm12.session.open(); the bootstrap would fall back to '
                . 'pipwerks.SCORM.init() and every write would be refused silently'
        );
    }

    /**
     * Read the provenance file into key => value pairs.
     *
     * @return array<string,string> Everything declared in assets/scorm/SOURCE.
     */
    private function source_declaration(): array {
        $path = self::ASSETS . '/SOURCE';
        $this->assertFileExists($path, 'assets/scorm/SOURCE is missing; the runtime has no declared provenance');

        $declared = [];
        foreach (preg_split('~\r?\n~', (string) file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $declared[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $declared;
    }

    /**
     * The two files are byte-for-byte what the declared core commit produced.
     *
     * The banner checks above catch a copy that is the wrong SHAPE — a dropped layer, a
     * missing stamp. They cannot catch a copy that is the wrong CONTENT: an edit inside a
     * layer keeps every banner in place and the stamp still names a release. Only a digest
     * catches that, and only a digest tied to a specific commit says which build it is.
     */
    public function test_the_runtime_matches_the_digests_its_provenance_declares(): void {
        $declared = $this->source_declaration();

        foreach (['SCORM_API_wrapper.js', 'SCOFunctions.js'] as $name) {
            $this->assertArrayHasKey($name, $declared, "assets/scorm/SOURCE does not declare $name");
            $expected = $declared[$name];
            $this->assertMatchesRegularExpression('~^sha256:[0-9a-f]{64}$~', $expected, "$name has no sha256 in SOURCE");
            $actual = 'sha256:' . hash('sha256', $this->asset($name));
            $this->assertSame(
                $expected,
                $actual,
                "$name does not match the digest in assets/scorm/SOURCE. Either it was edited here — "
                    . 'which is what this test exists to stop — or it was updated without regenerating SOURCE.'
            );
        }
    }

    /**
     * The provenance names a single core commit, and the stamp inside the file agrees
     * with the version SOURCE declares.
     */
    public function test_the_provenance_names_the_commit_and_agrees_with_the_stamp(): void {
        $declared = $this->source_declaration();

        $this->assertArrayHasKey('core-commit', $declared);
        $this->assertMatchesRegularExpression(
            '~^[0-9a-f]{40}$~',
            $declared['core-commit'],
            'core-commit must be a full commit id: a branch name or a release string cannot identify a build'
        );
        $this->assertArrayHasKey('core-repo', $declared);

        $this->assertArrayHasKey('runtime-version', $declared);
        $this->assertStringContainsString(
            'eXeLearning-SCORM12-Runtime: ' . $declared['runtime-version'],
            $this->asset('SCOFunctions.js'),
            'the stamp inside the runtime disagrees with the version its provenance declares'
        );
    }
}
