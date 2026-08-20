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
     * All registered (non-deleted) gradable items, ordered by itemnumber.
     *
     * @param \stdClass $instance
     * @return \stdClass[] Rows with itemnumber + objectid.
     */
    private function items(\stdClass $instance): array {
        global $DB;
        return array_values($DB->get_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ], 'itemnumber ASC', 'itemnumber, objectid'));
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
     * @return array
     */
    private function answered(string $objectid, float $scaled, ?string $id = null): array {
        return [
            'id'   => $id ?? \core\uuid::generate(),
            'actor' => ['account' => ['homePage' => 'https://x', 'name' => 'anonymous']],
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/answered'],
            'object' => ['id' => 'https://exelearning.net/xapi/abc/idevice/' . $objectid],
            'result' => ['score' => ['scaled' => $scaled, 'raw' => $scaled * 10, 'min' => 0, 'max' => 10]],
            'context' => ['extensions' => [statement_normalizer::EXT_IDEVICE_ID => $objectid]],
        ];
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

    public function test_answered_writes_a_server_derived_overall_row(): void {
        // DEC-122-01 reverses the old answered-only edge: multipage packages emit NO
        // package verdict (upstream ADR-2302-01), so every answered commit derives the
        // itemnumber=0 row server-side from the roster and this attempt's own rows.
        // With one of two registered items answered, the attempt is a running partial:
        // mean of the answered subset over the full roster is not yet computable as
        // terminal, so the row is 'incomplete' with the running mean of answered items.
        [$instance, $student, $course, $cm] = $this->create_activity();
        [$itemnumber, $objectid] = $this->first_item($instance);

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($objectid, 1.0), 'reg1', false);

        $this->assertTrue($result['ok']);
        $this->assertNotFalse($this->attempt($instance, $student->id, $itemnumber)); // Per-iDevice row written.
        $overall = $this->attempt($instance, $student->id, 0);
        $this->assertNotFalse($overall);
        $this->assertSame('incomplete', $overall->status);
        // Running mean of the ANSWERED items (100), visible to the participation
        // summary and the OVERALL grade model while the attempt is in flight —
        // SCORM-channel parity (it recomputes on every commit too).
        $this->assertEqualsWithDelta(100.0, (float) $overall->rawscore, 0.0001);
    }

    /**
     * Completing the roster turns the derived overall terminal: gradepass > 0 decides
     * passed/failed against the derived mean; the value is the unweighted mean of the
     * per-item scores (weights are unrecoverable on this channel by design).
     */
    public function test_completing_the_roster_derives_a_terminal_overall(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity(['gradepass' => 60]);
        $items = $this->items($instance);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        $mid = $this->attempt($instance, $student->id, 0);
        $this->assertSame('incomplete', $mid->status);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[1]->objectid, 0.4), 'r1', false);

        $overall = $this->attempt($instance, $student->id, 0);
        // Mean of 100 and 40 = 70 >= gradepass 60 -> passed.
        $this->assertEqualsWithDelta(70.0, (float) $overall->rawscore, 0.0001);
        $this->assertSame('passed', $overall->status);
    }

    /**
     * gradepass = 0 disables pass-based status: a complete roster reads 'completed'.
     * The server does not invent a pass policy (the emitter's single-page verdict uses
     * a fixed 50% threshold; that wording difference is deliberate and documented).
     */
    public function test_gradepass_zero_yields_completed(): void {
        [$instance, $student, $course, $cm] = $this->create_activity();
        $items = $this->items($instance);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 0.1), 'r1', false);
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[1]->objectid, 0.1), 'r1', false);

        $this->assertSame('completed', $this->attempt($instance, $student->id, 0)->status);
    }

    /**
     * A re-answer can flip passed -> failed, and attempt_completed fires exactly once
     * (on the first transition into a terminal status, DEC-68-01).
     */
    public function test_reanswer_flips_status_and_completed_fires_once(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['gradepass' => 60]);
        $items = $this->items($instance);

        $sink = $this->redirectEvents();
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[1]->objectid, 1.0), 'r1', false);
        $this->assertSame('passed', $this->attempt($instance, $student->id, 0)->status);

        // Re-answer the first item much worse: mean drops to 30 -> failed.
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 0.0), 'r1', false);
        $events = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_exelearning\event\attempt_completed
        );
        $sink->close();

        $this->assertSame('failed', $this->attempt($instance, $student->id, 0)->status);
        $this->assertCount(1, $events);
    }

    /**
     * attempt_started precedes attempt_completed even when the FIRST commit finishes
     * the whole attempt (a single-iDevice roster).
     */
    public function test_started_precedes_completed_on_single_item_roster(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        $items = $this->items($instance);
        $DB->set_field('exelearning_grade_item', 'deleted', 1, [
            'exelearningid' => $instance->id, 'itemnumber' => $items[1]->itemnumber,
        ]);

        $sink = $this->redirectEvents();
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        $names = array_values(array_map(
            fn($event) => $event->eventname,
            array_filter($sink->get_events(), fn($event) => strpos(get_class($event), 'mod_exelearning') === 0)
        ));
        $sink->close();

        $started = array_search('\\mod_exelearning\\event\\attempt_started', $names, true);
        $completed = array_search('\\mod_exelearning\\event\\attempt_completed', $names, true);
        $this->assertNotFalse($started, 'attempt_started was not emitted');
        $this->assertNotFalse($completed, 'attempt_completed was not emitted');
        $this->assertLessThan($completed, $started);
        $this->assertSame('completed', $this->attempt($instance, $student->id, 0)->status);
    }

    /**
     * A soft-deleted item neither blocks completeness nor pollutes the mean.
     */
    public function test_soft_deleted_item_is_ignored_by_the_derivation(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        $items = $this->items($instance);

        // Answer BOTH, then the teacher republishes without the second iDevice.
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[1]->objectid, 0.0), 'r1', false);
        $DB->set_field('exelearning_grade_item', 'deleted', 1, [
            'exelearningid' => $instance->id, 'itemnumber' => $items[1]->itemnumber,
        ]);

        // The next commit rederives over the live roster only: mean = 100, complete.
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);

        $overall = $this->attempt($instance, $student->id, 0);
        $this->assertEqualsWithDelta(100.0, (float) $overall->rawscore, 0.0001);
        $this->assertSame('completed', $overall->status);
    }

    /**
     * Roster drift: a republish that ADDS a gradable iDevice mid-attempt regresses the
     * derived status to 'incomplete' on the next commit — same hazard class the SCORM
     * channel already tolerates; pinned so the behaviour is deliberate, not accidental.
     */
    public function test_roster_growth_regresses_the_attempt_to_incomplete(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity();
        $items = $this->items($instance);
        $DB->set_field('exelearning_grade_item', 'deleted', 1, [
            'exelearningid' => $instance->id, 'itemnumber' => $items[1]->itemnumber,
        ]);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        $this->assertSame('completed', $this->attempt($instance, $student->id, 0)->status);

        // Republish restores the second gradable iDevice.
        $DB->set_field('exelearning_grade_item', 'deleted', 0, [
            'exelearningid' => $instance->id, 'itemnumber' => $items[1]->itemnumber,
        ]);
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);

        $this->assertSame('incomplete', $this->attempt($instance, $student->id, 0)->status);
    }

    /**
     * Single-page overlap is benign: the package verdict arriving after the completing
     * answered overwrites the derived row — the producer's weighted value wins.
     */
    public function test_package_verdict_overwrites_the_derived_overall(): void {
        [$instance, $student, $course, $cm] = $this->create_activity();
        $items = $this->items($instance);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 1.0), 'r1', false);
        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[1]->objectid, 0.0), 'r1', false);
        // Derived mean = 50; the producer's weighted verdict says 75.
        ingestor::ingest($instance, $course, $cm, $student->id, $this->package('passed', 0.75), 'r1', false);

        $overall = $this->attempt($instance, $student->id, 0);
        $this->assertEqualsWithDelta(75.0, (float) $overall->rawscore, 0.0001);
        $this->assertSame('passed', $overall->status);
    }

    /**
     * OVERALL grade model: the derived running mean is published to the gradebook on
     * every commit, then refined — an in-progress learner is never gradeless.
     */
    public function test_overall_model_publishes_the_running_derived_grade(): void {
        // EXELEARNING_GRADEMODEL_OVERALL = 0.
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        ingestor::ingest($instance, $course, $cm, $student->id, $this->answered($items[0]->objectid, 0.8), 'r1', false);

        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(80.0, (float) $grades->items[0]->grades[$student->id]->grade, 0.0001);
    }
}
