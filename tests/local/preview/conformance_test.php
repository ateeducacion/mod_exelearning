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
 * Replays the shared eXeLearning preview serving contract v2 conformance vectors
 * (tests/fixtures/preview-contract/vectors.json, vendored verbatim from core)
 * against this plugin's store + serving helpers, so the protocol semantics stay
 * aligned with core and every other host. This is the Moodle port of the
 * reference consumer src/routes/preview-session.conformance.spec.ts.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\serving
 * @covers     \mod_exelearning\local\preview\session_store
 * @covers     \mod_exelearning\local\preview\preview_session
 * @covers     \mod_exelearning\local\preview\fixed_resources
 */
final class conformance_test extends advanced_testcase {
    /** @var int The session owner (management requests run authenticated). */
    private const OWNER = 7;

    /**
     * Replay every vector step, in order, against one session.
     */
    public function test_conformance_vectors(): void {
        $this->resetAfterTest();

        $vectors = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/preview-contract/vectors.json'),
            true
        );
        $this->assertSame(2, $vectors['protocolVersion']);

        // Harness step 1: materialize the fixed-resource distribution root and
        // point the resolver at it.
        $dist = make_request_directory();
        $resources = [];
        foreach ($vectors['fixedResources'] as $id => $entry) {
            $abs = $dist . '/' . $entry['path'];
            if (!is_dir(dirname($abs))) {
                mkdir(dirname($abs), 0777, true);
            }
            file_put_contents($abs, $entry['content']);
            $resources[$id] = ['path' => $entry['path'], 'size' => strlen($entry['content'])];
        }
        if (!is_dir($dist . '/bundles')) {
            mkdir($dist . '/bundles', 0777, true);
        }
        file_put_contents(
            $dist . '/bundles/preview-fixed-resources.json',
            json_encode(['schemaVersion' => 1, 'buildVersion' => 'v-test', 'resources' => $resources])
        );
        fixed_resources::configure($dist, $dist . '/bundles/preview-fixed-resources.json');

        session_store::set_root_for_testing(make_request_directory());

        $previewid = '';
        foreach ($vectors['steps'] as $step) {
            $previewid = $this->run_step($step, $previewid);
        }

        session_store::reset_root_for_testing();
        fixed_resources::reset();
    }

    /**
     * Execute one vector step and return the (possibly newly minted) previewId.
     *
     * @param array $step
     * @param string $previewid
     * @return string
     */
    private function run_step(array $step, string $previewid): string {
        $path = str_replace('{previewId}', $previewid, $step['request']['path']);
        $context = $step['id'];

        if (strpos($step['request']['path'], '/api/') === 0) {
            $response = $this->run_management($step, $previewid, $path);
        } else {
            $response = $this->run_serving($step, $previewid, $path);
        }

        $this->assertSame($step['expect']['status'], $response['status'], "status for {$context}");
        $this->assert_headers($step['expect']['headers'] ?? [], $response['headers'] ?? [], $context);

        if (isset($step['expect']['bodyText'])) {
            $this->assertSame($step['expect']['bodyText'], $response['body'], "bodyText for {$context}");
        } else if (isset($step['expect']['body'])) {
            $this->assert_subset($step['expect']['body'], $response['bodyjson'], $context);
            if (isset($response['bodyjson']['previewId'])) {
                $previewid = $response['bodyjson']['previewId'];
            }
        }
        return $previewid;
    }

    /**
     * Dispatch a management step to the serving helpers (authenticated owner).
     *
     * @param array $step
     * @param string $previewid
     * @param string $path
     * @return array{status:int,headers:array,bodyjson:array}
     */
    private function run_management(array $step, string $previewid, string $path): array {
        $body = $step['request']['body'] ?? [];
        if ($step['request']['method'] === 'POST' && $path === '/api/preview-session') {
            $result = serving::create_session_response(self::OWNER);
            return ['status' => $result['status'], 'headers' => [], 'bodyjson' => $result['body']];
        }

        $session = session_store::get_owned($previewid, self::OWNER)['session'];

        if (($body['kind'] ?? '') === 'assets') {
            $assets = [];
            $files = [];
            foreach ($body['entries'] as $entry) {
                $assets[] = ['key' => $entry['key'], 'size' => strlen($entry['content'])];
                $files[] = $entry['content'];
            }
            $result = serving::handle_assets_upload($session, json_encode($assets), $files);
            return ['status' => $result['status'], 'headers' => [], 'bodyjson' => $result['body']];
        }

        if (($body['kind'] ?? '') === 'revision') {
            $meta = $body['meta'];
            $files = [];
            $writes = [];
            foreach ($body['writes'] ?? [] as $write) {
                $writes[] = $write['path'];
                $files[] = $write['content'];
            }
            $meta['writes'] = $writes;
            $result = serving::handle_revision_upload($session, json_encode($meta), $files);
            return ['status' => $result['status'], 'headers' => [], 'bodyjson' => $result['body']];
        }

        // DELETE.
        session_store::delete_session($previewid);
        return ['status' => 200, 'headers' => [], 'bodyjson' => ['success' => true]];
    }

    /**
     * Dispatch a serving step through the authless serve path (no credentials).
     *
     * @param array $step
     * @param string $previewid
     * @param string $path
     * @return array{status:int,headers:array,body:string}
     */
    private function run_serving(array $step, string $previewid, string $path): array {
        $prefix = '/preview/' . $previewid . '/';
        $relpath = (strpos($path, $prefix) === 0) ? substr($path, strlen($prefix)) : '';

        $session = session_store::get_for_serving($previewid);
        if ($session === null) {
            return serving::not_found();
        }
        $headers = $step['request']['headers'] ?? [];
        return serving::serve($session, $relpath, [
            'ifnonematch' => $headers['If-None-Match'] ?? null,
            'range' => $headers['Range'] ?? null,
        ]);
    }

    /**
     * Assert header expectations (names case-insensitive): a string is exact; an
     * object matcher is startsWith / contains / absent.
     *
     * @param array $expected
     * @param array $actual
     * @param string $context
     */
    private function assert_headers(array $expected, array $actual, string $context): void {
        $lower = [];
        foreach ($actual as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        foreach ($expected as $name => $matcher) {
            $key = strtolower($name);
            $value = $lower[$key] ?? null;
            if (is_array($matcher)) {
                if (!empty($matcher['absent'])) {
                    $this->assertArrayNotHasKey($key, $lower, "header {$name} absent for {$context}");
                } else if (isset($matcher['startsWith'])) {
                    $this->assertStringStartsWith($matcher['startsWith'], (string) $value, "header {$name} for {$context}");
                } else if (isset($matcher['contains'])) {
                    $this->assertStringContainsString($matcher['contains'], (string) $value, "header {$name} for {$context}");
                }
            } else {
                $this->assertSame($matcher, $value, "header {$name} for {$context}");
            }
        }
    }

    /**
     * Recursive subset match: lists must match exactly, maps may carry extra keys.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $context
     */
    private function assert_subset($expected, $actual, string $context): void {
        if (is_array($expected) && array_is_list($expected)) {
            $this->assertEquals($expected, $actual, "list at {$context}");
            return;
        }
        if (is_array($expected)) {
            $this->assertIsArray($actual, "object at {$context}");
            foreach ($expected as $key => $value) {
                $this->assert_subset($value, $actual[$key] ?? null, "{$context}.{$key}");
            }
            return;
        }
        $this->assertSame($expected, $actual, "value at {$context}");
    }
}
