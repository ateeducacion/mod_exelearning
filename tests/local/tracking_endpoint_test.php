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

namespace mod_exelearning\local;

use advanced_testcase;
use moodle_exception;

/**
 * Unit tests for the tracking endpoints' web contract (SEC-04).
 *
 * The security invariant under test: the session key reaches track.php in the
 * POST body and never in the query string, where access logs, reverse proxies
 * and diagnostic tooling would record it verbatim.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\tracking_endpoint
 */
final class tracking_endpoint_test extends advanced_testcase {
    /**
     * PHPUnit bootstraps a session that waives sesskey checks; the endpoints run
     * with real checks, so the tests must too.
     */
    protected function setUp(): void {
        parent::setUp();
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $USER->ignoresesskey = false;
    }

    public function test_a_body_carrying_the_current_sesskey_is_accepted(): void {
        tracking_endpoint::require_body_sesskey(['sesskey' => sesskey(), 'cmi' => []]);
        // Returning void without throwing is the behaviour under test.
        $this->assertTrue(true);
    }

    public function test_a_body_without_a_sesskey_is_rejected(): void {
        $this->expectException(moodle_exception::class);
        tracking_endpoint::require_body_sesskey(['cmi' => []]);
    }

    public function test_a_body_carrying_the_wrong_sesskey_is_rejected(): void {
        $this->expectException(moodle_exception::class);
        tracking_endpoint::require_body_sesskey(['sesskey' => 'not-the-session-key']);
    }

    public function test_an_empty_sesskey_is_rejected_rather_than_falling_back_to_the_url(): void {
        // An empty value makes confirm_sesskey() fall back to required_param('sesskey'),
        // reading the query string again: exactly the parameter this change removes.
        $this->expectException(moodle_exception::class);
        tracking_endpoint::require_body_sesskey(['sesskey' => '']);
    }

    public function test_a_non_string_sesskey_is_rejected(): void {
        $this->expectException(moodle_exception::class);
        tracking_endpoint::require_body_sesskey(['sesskey' => ['array' => 'value']]);
    }

    public function test_the_scorm_tracker_config_keeps_the_sesskey_out_of_the_url(): void {
        $config = tracking_endpoint::scorm_config(42, 'grading', 'sessiontoken');

        $this->assertStringContainsString('/mod/exelearning/track.php', $config['trackurl']);
        $this->assertStringNotContainsString('sesskey', $config['trackurl']);
        $this->assertSame(sesskey(), $config['sesskey']);
        $this->assertSame(42, $config['cmid']);
        $this->assertSame('sessiontoken', $config['session']);
    }

    public function test_the_scorm_tracker_config_carries_the_mode(): void {
        $config = tracking_endpoint::scorm_config(7, 'preview', 'tok');

        $this->assertStringContainsString('mode=preview', $config['trackurl']);
    }
}
