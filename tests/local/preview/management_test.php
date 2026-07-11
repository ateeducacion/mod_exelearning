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
 * Unit tests for the management-endpoint helpers (create/assets/revision wire
 * validation and status mapping) the authenticated endpoint delegates to.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\preview\serving
 */
final class management_test extends advanced_testcase {
    /** @var string A valid assetKey. */
    private const PHOTO_KEY = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000@9c41d2e8a1b03f57';

    /** @var int Owner id. */
    private $userid = 555;

    /** @var string Current previewId. */
    private $previewid;

    /**
     * Isolate the store and create one session.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        session_store::set_root_for_testing(make_request_directory());
        $this->previewid = session_store::create_session($this->userid);
    }

    /**
     * Reset store overrides.
     */
    protected function tearDown(): void {
        session_store::reset_root_for_testing();
        session_store::reset_limits_for_testing();
        parent::tearDown();
    }

    /**
     * A fresh handle for the session.
     *
     * @return preview_session
     */
    private function session(): preview_session {
        return session_store::get_owned($this->previewid, $this->userid)['session'];
    }

    /**
     * create-session advertises protocol 2, revision 0 and the limits block.
     */
    public function test_create_session_response(): void {
        session_store::set_root_for_testing(make_request_directory());
        $result = serving::create_session_response($this->userid);
        $this->assertSame(201, $result['status']);
        $this->assertSame(2, $result['body']['protocolVersion']);
        $this->assertSame(0, $result['body']['revision']);
        $this->assertMatchesRegularExpression(serving::UUID_RE, $result['body']['previewId']);
        $this->assertArrayHasKey('maxBytesPerSession', $result['body']['limits']);
    }

    /**
     * A well-formed asset upload stores the bytes (accepting a JSON-string field).
     */
    public function test_assets_upload_happy(): void {
        $assets = json_encode([['key' => self::PHOTO_KEY, 'size' => 3]]);
        $result = serving::handle_assets_upload($this->session(), $assets, ['abc']);
        $this->assertSame(200, $result['status']);
        $this->assertSame([self::PHOTO_KEY], $result['body']['stored']);
    }

    /**
     * A pre-decoded assets field (multipart pre-parse) is accepted too.
     */
    public function test_assets_upload_accepts_predecoded_field(): void {
        $result = serving::handle_assets_upload($this->session(), [['key' => self::PHOTO_KEY, 'size' => 1]], ['z']);
        $this->assertSame(200, $result['status']);
        $this->assertSame([self::PHOTO_KEY], $result['body']['stored']);
    }

    /**
     * Malformed / misaligned asset requests are 400.
     */
    public function test_assets_upload_bad_requests(): void {
        $this->assertSame(400, serving::handle_assets_upload($this->session(), 'not json', [])['status']);
        $this->assertSame(400, serving::handle_assets_upload($this->session(), json_encode(['x']), ['a'])['status']);
        $badentry = json_encode([['key' => self::PHOTO_KEY, 'size' => 'notanint']]);
        $this->assertSame(400, serving::handle_assets_upload($this->session(), $badentry, ['a'])['status']);
        $aligned = json_encode([['key' => self::PHOTO_KEY, 'size' => 1]]);
        $this->assertSame(400, serving::handle_assets_upload($this->session(), $aligned, ['a', 'b'])['status']);
    }

    /**
     * The byte budget is enforced on DECLARED sizes before buffering and again
     * on ACTUAL bytes while buffering (413 either way).
     */
    public function test_assets_upload_two_stage_budget(): void {
        session_store::set_limits_for_testing(['maxbytespersession' => 5]);

        // Declared sizes exceed the budget: rejected before any buffering.
        $declared = json_encode([['key' => self::PHOTO_KEY, 'size' => 100]]);
        $this->assertSame(413, serving::handle_assets_upload($this->session(), $declared, ['x'])['status']);

        // Under-reported size, real bytes overflow: rejected while buffering.
        $underreported = json_encode([['key' => self::PHOTO_KEY, 'size' => 1]]);
        $this->assertSame(413, serving::handle_assets_upload($this->session(), $underreported, ['0123456789'])['status']);
    }

    /**
     * A well-formed revision publishes and returns {revision, active}.
     */
    public function test_revision_upload_happy(): void {
        $meta = json_encode([
            'baseRevision' => 0,
            'nextRevision' => 1,
            'writes' => ['index.html'],
            'deletes' => [],
            'assetRefs' => [],
            'fixedRefs' => [],
        ]);
        $result = serving::handle_revision_upload($this->session(), $meta, ['<h1>hi</h1>']);
        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['body']['revision']);
        $this->assertTrue($result['body']['active']);
    }

    /**
     * Malformed revision payloads are 400 across each validated field.
     */
    public function test_revision_upload_bad_requests(): void {
        $this->assertSame(400, serving::handle_revision_upload($this->session(), '[]', [])['status']);
        $noints = json_encode(['baseRevision' => 'a', 'nextRevision' => 1]);
        $this->assertSame(400, serving::handle_revision_upload($this->session(), $noints, [])['status']);
        $badwrites = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'writes' => [1, 2]]);
        $this->assertSame(400, serving::handle_revision_upload($this->session(), $badwrites, ['a', 'b'])['status']);
        $baddeletes = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'deletes' => [5]]);
        $this->assertSame(400, serving::handle_revision_upload($this->session(), $baddeletes, [])['status']);
        $badrefs = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'assetRefs' => ['p' => 7]]);
        $this->assertSame(400, serving::handle_revision_upload($this->session(), $badrefs, [])['status']);
        $misaligned = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html']]);
        $this->assertSame(400, serving::handle_revision_upload($this->session(), $misaligned, [])['status']);
    }

    /**
     * The document amplification guard rejects an oversized revision (413).
     */
    public function test_revision_upload_byte_budget(): void {
        session_store::set_limits_for_testing(['maxbytespersession' => 4]);
        $meta = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html']]);
        $this->assertSame(413, serving::handle_revision_upload($this->session(), $meta, ['too many bytes'])['status']);
    }

    /**
     * A stale revision maps to the wire 409 body.
     */
    public function test_revision_upload_conflict_mapping(): void {
        $first = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html']]);
        serving::handle_revision_upload($this->session(), $first, ['A']);
        $stale = json_encode(['baseRevision' => 0, 'nextRevision' => 1, 'writes' => ['a.html']]);
        $result = serving::handle_revision_upload($this->session(), $stale, ['B']);
        $this->assertSame(409, $result['status']);
        $this->assertSame('revision-conflict', $result['body']['reason']);
        $this->assertSame(1, $result['body']['currentRevision']);
    }

    /**
     * Missing-asset and unknown-fixed rejections map to their wire 422 bodies.
     */
    public function test_revision_upload_422_mapping(): void {
        $ghost = '99999999-9999-4999-8999-999999999999@deadbeef';
        $missing = json_encode([
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'assetRefs' => ['res/x.png' => $ghost],
        ]);
        $result = serving::handle_revision_upload($this->session(), $missing, []);
        $this->assertSame(422, $result['status']);
        $this->assertSame('missing-assets', $result['body']['reason']);
        $this->assertSame([$ghost], $result['body']['missing']);

        $unknownfixed = json_encode([
            'baseRevision' => 0, 'nextRevision' => 1, 'writes' => [],
            'fixedRefs' => ['libs/x.js' => 'libs/x.js'],
        ]);
        $result = serving::handle_revision_upload($this->session(), $unknownfixed, []);
        $this->assertSame(422, $result['status']);
        $this->assertSame('unknown-fixed-resources', $result['body']['reason']);
        $this->assertSame(['libs/x.js'], $result['body']['resources']);
    }
}
