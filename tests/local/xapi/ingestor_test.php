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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Integration tests for the xAPI ingestor (DEC-85-01): statements feed the existing
 * grade pipeline, the client is never trusted, and ingestion is idempotent.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\xapi\ingestor
 */
final class ingestor_test extends \advanced_testcase {
    /**
     * course + exelearning instance + enrolled student + resolved cm/course.
     *
     * @param array $record extra generator fields (e.g. grademodel, maxattempt, gradeenabled)
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass} [instance, student, course, cm]
     */
    private function create_activity(array $record = []): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(array_merge(['course' => $course->id], $record));

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        return [$instance, $student, $course, $cm];
    }

    /**
     * The first registered (non-deleted) gradable item of the instance.
     *
     * @param \stdClass $instance
     * @return array{0: int, 1: string} [itemnumber, objectid]
     */
    private function first_item(\stdClass $instance): array {
        global $DB;
        $row = $DB->get_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ], 'itemnumber ASC', 'itemnumber, objectid', 0, 1);
        $row = reset($row);
        return [(int) $row->itemnumber, (string) $row->objectid];
    }

    /**
     * Builds an answered statement carrying the iDevice id in the stable extension.
     *
     * @param string $objectid
     * @param float $scaled
     * @param string|null $id Statement id (auto-generated UUID when null).
     * @param float|null $weight Effective relative weight for the new upstream contract.
     * @param int|null $ideviceorder Deterministic package-global order.
     * @return array
     */
    private function answered(
        string $objectid,
        float $scaled,
        ?string $id = null,
        ?float $weight = null,
        ?int $ideviceorder = null
    ): array {
        $extensions = [statement_normalizer::EXT_IDEVICE_ID => $objectid];
        if ($weight !== null && $ideviceorder !== null) {
            $extensions[statement_normalizer::EXT_WEIGHT] = $weight;
            $extensions[statement_normalizer::EXT_IDEVICE_ORDER] = $ideviceorder;
        }
        return [
            'id'   => $id ?? \core\uuid::generate(),
            'actor' => ['account' => ['homePage' => 'https://x', 'name' => 'anonymous']],
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/answered'],
            'object' => ['id' => 'https://exelearning.net/xapi/abc/idevice/' . $objectid],
            'result' => ['score' => ['scaled' => $scaled, 'raw' => $scaled * 10, 'min' => 0, 'max' => 10]],
            'context' => ['extensions' => $extensions],
        ];
    }

    /**
     * All registered gradable items in stable itemnumber order.
     *
     * @param \stdClass $instance
     * @return array<int, \stdClass>
     */
    private function items(\stdClass $instance): array {
        global $DB;
        return array_values($DB->get_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ], 'itemnumber ASC', 'itemnumber, objectid'));
    }

    /**
     * Builds a package statement.
     *
     * @param string $verb passed|failed|completed
     * @param float $scaled
     * @return array
     */
    private function package(string $verb, float $scaled): array {
        return [
            'id'   => \core\uuid::generate(),
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/' . $verb],
            'object' => ['id' => 'https://exelearning.net/xapi/abc'],
            'result' => ['score' => ['scaled' => $scaled, 'raw' => $scaled * 100, 'min' => 0, 'max' => 100]],
        ];
    }

    /**
     * Reads an attempt row for a user/item.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param int $itemnumber
     * @return \stdClass|false
     */
    private function attempt(\stdClass $instance, int $userid, int $itemnumber) {
        global $DB;
        return $DB->get_record('exelearning_attempt', [
            'exelearningid' => $instance->id,
            'userid'        => $userid,
            'itemnumber'    => $itemnumber,
        ]);
    }

    public function test_answered_grades_the_matching_item(): void {
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 0.8), 'reg1', false);

        $this->assertTrue($result['ok']);
        $this->assertSame('answered', $result['verb']);
        // Parity with the SCORM path: scorepct 80 of grademax 100 → rawscore 80.
        $attempt = $this->attempt($instance, $student->id, $itemnumber);
        $this->assertNotFalse($attempt);
        $this->assertEqualsWithDelta(80.0, (float) $attempt->rawscore, 0.0001);
        // Attributed to the authenticated user, never to the (anonymous) statement actor.
        $this->assertEquals($student->id, $attempt->userid);
    }

    public function test_unknown_objectid_is_rejected(): void {
        [$instance, $student, $course, $cm] = $this->create_activity();
        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered('does-not-exist', 0.8), 'reg1', false);
        $this->assertFalse($result['ok']);
        $this->assertSame('unknownobjectid', $result['error']);
        $this->assertFalse($this->attempt($instance, $student->id, $this->first_item($instance)[0]));
    }

    public function test_package_passed_sets_overall_and_publishes_in_overall_mode(): void {
        // EXELEARNING_GRADEMODEL_OVERALL = 0.
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->package('passed', 0.9), 'reg1', false);

        $this->assertTrue($result['ok']);
        $overall = $this->attempt($instance, $student->id, 0);
        $this->assertNotFalse($overall);
        $this->assertEqualsWithDelta(90.0, (float) $overall->rawscore, 0.0001);
        $this->assertSame('passed', $overall->status);
        // In OVERALL mode the aggregated overall is published to the gradebook.
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(90.0, (float) $grades->items[0]->grades[$student->id]->grade, 0.0001);
    }

    public function test_multipage_answered_state_reconstructs_overall_instead_of_package_score(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        // Page 1: A=100 at weight 25. The new contract makes an incomplete overall
        // durable immediately, even before its package lifecycle statement arrives.
        $first = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'multipage-reg',
            false
        );
        $this->assertEqualsWithDelta(100.0, $first['rawscore'], 0.0001);
        $this->assertSame('incomplete', $this->attempt($instance, $student->id, 0)->status);

        // Page 2 starts with a fresh emitter state and reports package finalScore=40.
        // Moodle must retain A and reconstruct (100*25 + 40*75)/100 = 55.
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 0.4, null, 75, 2),
            'multipage-reg',
            false
        );
        $package = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->package('passed', 0.4),
            'multipage-reg',
            false
        );

        $this->assertEqualsWithDelta(55.0, $package['rawscore'], 0.0001);
        $this->assertEqualsWithDelta(55.0, (float) $this->attempt($instance, $student->id, 0)->rawscore, 0.0001);
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(55.0, (float) $grades->items[0]->grades[$student->id]->grade, 0.0001);
    }

    public function test_reanswer_replaces_the_current_idevice_contribution(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 0.4, null, 20, 1),
            'reanswer-reg',
            false
        );
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 0.8, null, 80, 2),
            'reanswer-reg',
            false
        );
        $latest = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 20, 1),
            'reanswer-reg',
            false
        );

        $this->assertEqualsWithDelta(84.0, $latest['rawscore'], 0.0001);
        $this->assertSame(2, $DB->count_records_select(
            'exelearning_attempt',
            'exelearningid = ? AND userid = ? AND itemnumber > 0',
            [$instance->id, $student->id]
        ));
        $firstrow = $this->attempt($instance, $student->id, (int) $items[0]->itemnumber);
        $this->assertEqualsWithDelta(20.0, (float) $firstrow->xapiweight, 0.0001);
        $this->assertSame(1, (int) $firstrow->xapiorder);
    }

    public function test_duplicate_statement_id_is_not_reapplied(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);
        $statement = $this->answered($objectid, 0.8, \core\uuid::generate());

        $first = ingestor::ingest($instance, $course, $cm, $student->id, $statement, 'reg1', false);
        $second = ingestor::ingest($instance, $course, $cm, $student->id, $statement, 'reg1', false);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']));
        // Exactly one audit row and one attempt row survive.
        $this->assertEquals(1, $DB->count_records('exelearning_tracking_events', ['exelearningid' => $instance->id]));
        $this->assertEquals(1, $DB->count_records('exelearning_attempt', [
            'exelearningid' => $instance->id,
            'userid'        => $student->id,
            'itemnumber'    => $itemnumber,
        ]));
    }

    public function test_maxattempt_cap_is_enforced(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['maxattempt' => 1]);
        [, $objectid] = $this->first_item($instance);

        // Attempt 1 (registration "rega").
        $a = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 0.5), 'rega', false);
        $this->assertTrue($a['ok']);
        // A fresh page-load (registration "regb") would open attempt 2, over the cap.
        $b = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 0.7), 'regb', false);
        $this->assertFalse($b['ok']);
        $this->assertSame('maxattemptsreached', $b['error']);
    }

    public function test_grading_disabled_is_a_noop(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity(['gradeenabled' => 0]);
        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered('whatever', 0.8), 'reg1', false);
        $this->assertTrue($result['ok']);
        $this->assertTrue(!empty($result['noop']));
        $this->assertEquals(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));
    }

    public function test_preview_does_not_grade(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        [, $objectid] = $this->first_item($instance);
        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 0.8), 'reg1', true);
        $this->assertTrue($result['ok']);
        $this->assertSame('preview', $result['mode']);
        $this->assertEquals(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));
        $this->assertEquals(0, $DB->count_records('exelearning_tracking_events', ['exelearningid' => $instance->id]));
    }

    public function test_answered_publishes_the_per_item_grade_in_peritem_mode(): void {
        // Default grademodel is PERITEM (1): the answered grade is published to the
        // matching gradebook column (the SCORM apply_item_scores path, reused unchanged).
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 0.8), 'reg1', false);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey($itemnumber, $result['peritem']);
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(
            80.0,
            (float) $grades->items[$itemnumber]->grades[$student->id]->grade,
            0.0001
        );
    }

    public function test_long_registration_is_bounded_and_does_not_overflow(): void {
        // A crafted statement with no host registration and an over-long context
        // registration must NOT throw a dml_write_exception on the char(40) columns: the
        // normaliser bounds the token to 40 chars before it is used/stored.
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);

        $statement = $this->answered($objectid, 0.6);
        $statement['context']['registration'] = str_repeat('Z', 120);
        $result = ingestor::ingest($instance, $course, $cm, $student->id, $statement, '', false);

        $this->assertTrue($result['ok']);
        $attempt = $this->attempt($instance, $student->id, $itemnumber);
        $this->assertNotFalse($attempt);
        $this->assertLessThanOrEqual(40, strlen((string) $attempt->sessiontoken));
    }

    public function test_lifecycle_statement_is_audited_without_grading(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        $statement = [
            'id'     => \core\uuid::generate(),
            'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/initialized'],
            'object' => ['id' => 'https://exelearning.net/xapi/abc'],
        ];

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $statement, 'reg1', false);

        $this->assertTrue($result['ok']);
        $this->assertTrue(!empty($result['lifecycle']));
        // Recorded for idempotency/traceability, but it drives no grade.
        $this->assertEquals(1, $DB->count_records(
            'exelearning_tracking_events',
            ['exelearningid' => $instance->id, 'verb' => 'initialized']
        ));
        $this->assertEquals(0, $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]));
    }

    public function test_answered_only_attempt_has_no_overall_row(): void {
        // DEC-85-01 edge: the authoritative overall (itemnumber=0) comes from the package
        // passed/failed/completed statement, emitted right after the answered ones. An
        // answered-only flow (the terminal package statement never arrives — e.g. the tab
        // closes first) writes per-iDevice rows but NO overall row, so the participation
        // summary and status/passgrade completion reflect only package-bearing attempts.
        // Pinned so a future change to this intentional behaviour is noticed.
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 1.0), 'reg1', false);

        $this->assertTrue($result['ok']);
        $this->assertNotFalse($this->attempt($instance, $student->id, $itemnumber)); // Per-iDevice row written.
        $this->assertFalse($this->attempt($instance, $student->id, 0));              // Overall row absent.
    }
}
