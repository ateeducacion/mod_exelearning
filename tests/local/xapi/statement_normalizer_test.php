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

namespace mod_exelearning\local\xapi;

/**
 * Unit tests for the pure xAPI statement validator/normaliser (DEC-0-18/DEC-85-01).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\xapi\statement_normalizer
 */
final class statement_normalizer_test extends \advanced_testcase {
    /** @var string A syntactically valid UUID for statement ids. */
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    /**
     * Builds an answered statement for an iDevice id, carried in the stable extension.
     *
     * @param string $ideviceid The objectid (raw iDevice id).
     * @param float $scaled The scaled score (0..1).
     * @param array $overrides Extra top-level overrides.
     * @return array
     */
    private function answered(string $ideviceid, float $scaled, array $overrides = []): array {
        return $overrides + [
            'id'   => self::UUID,
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/answered'],
            'object' => [
                'id' => 'https://exelearning.net/xapi/abc/idevice/' . $ideviceid,
                'definition' => ['name' => ['en' => 'Question 1']],
            ],
            'result' => ['score' => ['scaled' => $scaled, 'raw' => $scaled * 10, 'min' => 0, 'max' => 10]],
            'context' => ['extensions' => [statement_normalizer::EXT_IDEVICE_ID => $ideviceid]],
        ];
    }

    /**
     * Builds a package statement (completed/passed/failed).
     *
     * @param string $verb The verb key.
     * @param float $scaled The scaled score (0..1).
     * @return array
     */
    private function package(string $verb, float $scaled): array {
        return [
            'id'   => self::UUID,
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/' . $verb],
            'object' => ['id' => 'https://exelearning.net/xapi/abc'],
            'result' => ['score' => ['scaled' => $scaled, 'raw' => $scaled * 100, 'min' => 0, 'max' => 100]],
        ];
    }

    public function test_answered_is_normalised_to_itemscores(): void {
        $out = statement_normalizer::normalize($this->answered('ide-1', 0.7));
        $this->assertTrue($out['ok']);
        $this->assertSame('answered', $out['verb']);
        $this->assertSame('ide-1', $out['objectid']);
        $this->assertEqualsWithDelta(0.7, $out['scaled'], 0.0001);
        $this->assertEqualsWithDelta(70.0, $out['itemscores']['ide-1']['scorepct'], 0.0001);
    }

    public function test_objectid_falls_back_to_object_id_suffix(): void {
        $statement = $this->answered('ignored', 0.5);
        unset($statement['context']['extensions']);
        $statement['object']['id'] = 'https://host/path/idevice/ide-from-suffix';
        $out = statement_normalizer::normalize($statement);
        $this->assertTrue($out['ok']);
        $this->assertSame('ide-from-suffix', $out['objectid']);
    }

    public function test_answered_without_resolvable_objectid_is_rejected(): void {
        $statement = $this->answered('x', 0.5);
        unset($statement['context']['extensions']);
        $statement['object']['id'] = 'https://host/no-idevice-segment';
        $out = statement_normalizer::normalize($statement);
        $this->assertFalse($out['ok']);
        $this->assertSame('objectidmissing', $out['error']);
    }

    /**
     * A scaled score outside the eXeLearning [0,1] domain is rejected.
     *
     * @dataProvider out_of_range_scaled_provider
     * @param float $scaled
     */
    public function test_scaled_out_of_domain_is_rejected(float $scaled): void {
        $out = statement_normalizer::normalize($this->answered('ide-1', $scaled));
        $this->assertFalse($out['ok']);
        $this->assertSame('scoreoutofrange', $out['error']);
    }

    /**
     * Out-of-domain scaled scores for {@see test_scaled_out_of_domain_is_rejected}.
     *
     * @return array<string, array{float}>
     */
    public static function out_of_range_scaled_provider(): array {
        return ['above one' => [1.5], 'negative' => [-0.1]];
    }

    public function test_raw_outside_min_max_is_rejected(): void {
        $statement = $this->answered('ide-1', 0.5);
        $statement['result']['score'] = ['scaled' => 0.5, 'raw' => 99, 'min' => 0, 'max' => 10];
        $out = statement_normalizer::normalize($statement);
        $this->assertFalse($out['ok']);
        $this->assertSame('scoreoutofrange', $out['error']);
    }

    public function test_unknown_verb_is_ignored_not_errored(): void {
        $statement = $this->answered('ide-1', 0.5);
        $statement['verb']['id'] = 'http://adlnet.gov/expapi/verbs/experienced';
        $out = statement_normalizer::normalize($statement);
        $this->assertTrue($out['ok']);
        $this->assertTrue($out['ignored']);
    }

    public function test_lifecycle_verbs_are_accepted_without_a_score(): void {
        $out = statement_normalizer::normalize([
            'id'   => self::UUID,
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/initialized'],
            'object' => ['id' => 'https://exelearning.net/xapi/abc'],
        ]);
        $this->assertTrue($out['ok']);
        $this->assertTrue($out['lifecycle']);
        $this->assertSame('initialized', $out['verb']);
    }

    public function test_package_passed_failed_completed(): void {
        $passed = statement_normalizer::normalize($this->package('passed', 0.8));
        $this->assertSame('passed', $passed['status']);
        $this->assertTrue($passed['success']);
        $this->assertEqualsWithDelta(80.0, $passed['overallpct'], 0.0001);

        $failed = statement_normalizer::normalize($this->package('failed', 0.2));
        $this->assertSame('failed', $failed['status']);

        $completed = statement_normalizer::normalize($this->package('completed', 1.0));
        $this->assertSame('completed', $completed['status']);
    }

    public function test_non_uuid_id_is_rejected(): void {
        $statement = $this->answered('ide-1', 0.5);
        $statement['id'] = 'not-a-uuid';
        $out = statement_normalizer::normalize($statement);
        $this->assertFalse($out['ok']);
        $this->assertSame('invalidstatementid', $out['error']);
    }

    public function test_null_outside_extensions_is_rejected_but_allowed_inside(): void {
        $bad = $this->answered('ide-1', 0.5);
        $bad['object']['definition']['description'] = null;
        $this->assertFalse(statement_normalizer::normalize($bad)['ok']);

        // A null INSIDE an extensions map is permitted by the spec (and accepted).
        $good = $this->answered('ide-1', 0.5);
        $good['context']['extensions']['https://x/optional'] = null;
        $this->assertTrue(statement_normalizer::normalize($good)['ok']);
    }

    public function test_version_is_validated_permissively(): void {
        foreach (['1.0.3', '2.0.0', '1.0.0'] as $version) {
            $statement = $this->answered('ide-1', 0.5, ['version' => $version]);
            $out = statement_normalizer::normalize($statement);
            $this->assertTrue($out['ok'], "version $version should be accepted");
        }
    }

    public function test_missing_verb_is_rejected(): void {
        $out = statement_normalizer::normalize(['id' => self::UUID, 'object' => ['id' => 'https://x']]);
        $this->assertFalse($out['ok']);
        $this->assertSame('invalidstatement', $out['error']);
    }

    public function test_registration_is_sanitised_and_bounded(): void {
        // A clean, short token passes through unchanged.
        $ok = $this->answered('ide-1', 0.5);
        $ok['context']['registration'] = 'abc-123_XYZ';
        $this->assertSame('abc-123_XYZ', statement_normalizer::normalize($ok)['registration']);

        // A long token carrying foreign characters is stripped to PARAM_ALPHANUMEXT and
        // capped to the char(40) column width, so it can never overflow the DB.
        $bad = $this->answered('ide-1', 0.5);
        $bad['context']['registration'] = str_repeat('a', 20) . '/<script>!' . str_repeat('b', 40);
        $reg = statement_normalizer::normalize($bad)['registration'];
        $this->assertLessThanOrEqual(40, strlen($reg));
        $this->assertMatchesRegularExpression('~^[A-Za-z0-9_-]+$~', $reg);
    }

    public function test_non_string_registration_is_dropped(): void {
        // A crafted array registration must not become the literal "Array" token; it is
        // simply dropped (the host-injected registration is the authoritative one anyway).
        $statement = $this->answered('ide-1', 0.5);
        $statement['context']['registration'] = ['a', 'b'];
        $out = statement_normalizer::normalize($statement);
        $this->assertTrue($out['ok']);
        $this->assertSame('', $out['registration']);
    }

    public function test_nil_uuid_is_rejected(): void {
        // The nil UUID would pin the idempotency key to a constant and silently drop every
        // later statement; it must be rejected like any other non-UUID id.
        $statement = $this->answered('ide-1', 0.5);
        $statement['id'] = '00000000-0000-0000-0000-000000000000';
        $out = statement_normalizer::normalize($statement);
        $this->assertFalse($out['ok']);
        $this->assertSame('invalidstatementid', $out['error']);
    }

    public function test_success_is_read_from_result_not_score(): void {
        // The success flag lives at result.success (NOT result.score.success): a passed
        // verb whose result.success is explicitly false must surface false.
        $statement = $this->package('passed', 0.9);
        $statement['result']['success'] = false;
        $this->assertFalse(statement_normalizer::normalize($statement)['success']);

        // Absent result.success falls back to the verb (passed => true).
        $this->assertTrue(statement_normalizer::normalize($this->package('passed', 0.9))['success']);
    }
}
