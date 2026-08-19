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
            $extensions[statement_normalizer::EXT_IDEVICE_WEIGHT] = $weight;
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
     * Builds an `initialized` statement carrying the page's evaluable-iDevice census.
     *
     * @param array $census List of [objectid, weight, order] triples.
     * @return array
     */
    private function initialized(array $census): array {
        $entries = [];
        foreach ($census as [$objectid, $weight, $order]) {
            $entries[] = [
                'idevice-id'     => $objectid,
                'idevice-weight' => $weight,
                'idevice-order'  => $order,
            ];
        }
        return [
            'id'   => \core\uuid::generate(),
            'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/initialized'],
            'object' => ['id' => 'https://exelearning.net/xapi/abc'],
            'context' => ['extensions' => [statement_normalizer::EXT_IDEVICE_CENSUS => $entries]],
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

    public function test_multipage_answered_state_reconstructs_overall_instead_of_package_score(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        // Page 1: A=100 at weight 25. B has not reported its weight yet, so the package
        // total is still unknown and no overall may be published from this alone.
        $first = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'multipage-reg',
            false
        );
        $this->assertTrue($first['ok']);
        $this->assertArrayNotHasKey('rawscore', $first);
        $this->assertFalse($this->attempt($instance, $student->id, 0));

        // Page 2 starts with a fresh emitter state and reports package finalScore=40.
        // Moodle must retain A and reconstruct (100*25 + 40*75)/100 = 55.
        $second = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 0.4, null, 75, 2),
            'multipage-reg',
            false
        );
        // With the whole package reported the overall becomes durable immediately,
        // before any lifecycle statement arrives, and the attempt is already terminal:
        // a multipage package never emits a package verdict at all (ADR-2302-01).
        $this->assertEqualsWithDelta(55.0, $second['rawscore'], 0.0001);
        $this->assertSame('completed', $this->attempt($instance, $student->id, 0)->status);

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

    public function test_partially_answered_attempt_does_not_inflate_the_overall(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        // Only the lightest iDevice is answered, and perfectly. Renormalising the weights
        // over the answered subset alone would hand it all 100 allocated points and
        // publish 100/100. eXeLearning registers the unanswered iDevice as a 0 of weight
        // 75, so the real package score is 25 — reconstruction must not run yet.
        $answered = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'partial-reg',
            false
        );

        $this->assertTrue($answered['ok']);
        $this->assertArrayNotHasKey('rawscore', $answered);
        $this->assertFalse($this->attempt($instance, $student->id, 0));

        // A partial attempt keeps the DEC-85-01 package-score fallback.
        $package = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->package('passed', 0.25),
            'partial-reg',
            false
        );

        $this->assertEqualsWithDelta(25.0, $package['rawscore'], 0.0001);
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(25.0, (float) $grades->items[0]->grades[$student->id]->grade, 0.0001);
    }

    public function test_fully_reported_attempt_becomes_terminal_without_a_package_statement(): void {
        // A multipage package emits NO package-level verdict (upstream ADR-2302-01), so a
        // complete reconstruction is the only signal Moodle gets that the attempt ended.
        // Without deriving the status here every multipage attempt would stay 'incomplete'
        // for ever: no completionstatusrequired, no attempt_completed.
        [$instance, $student, $course, $cm] = $this->create_activity([
            'grademodel' => 0,
            'gradepass'  => 50,
        ]);
        $items = $this->items($instance);

        $sink = $this->redirectEvents();
        $first = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'noverdict-reg',
            false
        );
        $this->assertArrayNotHasKey('status', $first);

        $second = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 1.0, null, 75, 2),
            'noverdict-reg',
            false
        );
        $completed = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_exelearning\event\attempt_completed
        );
        $sink->close();

        $this->assertSame('passed', $second['status']);
        $this->assertSame('passed', $this->attempt($instance, $student->id, 0)->status);
        $this->assertCount(1, $completed);
    }

    public function test_answered_after_a_terminal_package_statement_keeps_the_status(): void {
        [$instance, $student, $course, $cm] = $this->create_activity([
            'grademodel' => 0,
            'gradepass'  => 50,
        ]);
        $items = $this->items($instance);

        $sink = $this->redirectEvents();
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'terminal-reg',
            false
        );
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 1.0, null, 75, 2),
            'terminal-reg',
            false
        );
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->package('passed', 1.0),
            'terminal-reg',
            false
        );
        $this->assertSame('passed', $this->attempt($instance, $student->id, 0)->status);

        // A late re-answer is a score update, not a lifecycle transition. Downgrading the
        // overall row to 'incomplete' would revoke completionstatusrequired completion and
        // re-arm attempt_completed (DEC-68-01): the status stays terminal and the event
        // fires exactly once across the whole attempt.
        $reanswer = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 0.0, null, 25, 1),
            'terminal-reg',
            false
        );
        $events = array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \mod_exelearning\event\attempt_completed
        );
        $sink->close();

        $this->assertEqualsWithDelta(75.0, $reanswer['rawscore'], 0.0001);
        $this->assertSame('passed', $this->attempt($instance, $student->id, 0)->status);
        $this->assertCount(1, $events);
    }

    public function test_deleted_idevice_stops_weighing_on_the_reconstructed_overall(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 0.4, null, 20, 1),
            'deleted-reg',
            false
        );
        $both = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 0.8, null, 80, 2),
            'deleted-reg',
            false
        );
        $this->assertEqualsWithDelta(72.0, $both['rawscore'], 0.0001);

        // A re-upload drops the second iDevice. Its stale row must stop contributing:
        // the package is now a single evaluable iDevice worth the whole grade.
        $DB->set_field('exelearning_grade_item', 'deleted', 1, [
            'exelearningid' => $instance->id,
            'itemnumber'    => $items[1]->itemnumber,
        ]);

        $after = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 0.5, null, 20, 1),
            'deleted-reg',
            false
        );

        $this->assertEqualsWithDelta(50.0, $after['rawscore'], 0.0001);
    }

    public function test_reconstructed_overall_respects_the_activity_grademin(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0, 'grademin' => 40]);
        $items = $this->items($instance);

        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 0.0, null, 25, 1),
            'grademin-reg',
            false
        );
        $last = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[1]->objectid, 0.0, null, 75, 2),
            'grademin-reg',
            false
        );

        // The reconstructed 0 is below the configured minimum. Both overall paths clamp
        // to the instance grade range, so they cannot disagree on the same activity.
        $this->assertEqualsWithDelta(40.0, $last['rawscore'], 0.0001);
        $this->assertEqualsWithDelta(40.0, (float) $this->attempt($instance, $student->id, 0)->rawscore, 0.0001);
    }

    public function test_page_census_makes_a_partial_attempt_exact(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        // The page census lists every evaluable iDevice, answered or not, so the weights
        // normalise over the whole publication from the very first answer.
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->initialized([
                [$items[0]->objectid, 25, 1],
                [$items[1]->objectid, 75, 2],
            ]),
            'census-reg',
            false
        );

        $answered = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0, null, 25, 1),
            'census-reg',
            false
        );

        // Perfect on the weight-25 iDevice, the weight-75 one untouched: 25, not 100.
        $this->assertEqualsWithDelta(25.0, $answered['rawscore'], 0.0001);
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(25.0, (float) $grades->items[0]->grades[$student->id]->grade, 0.0001);

        // Gradable is not the same as finished: one iDevice is still unanswered.
        $this->assertSame('incomplete', $this->attempt($instance, $student->id, 0)->status);
    }

    /**
     * The wire shape is verified against statements captured from the SHIPPED emitter,
     * not against statements this test builds itself.
     *
     * Every other census test here constructs its own payload, so both sides of the
     * contract are written by the same hand and agree by construction. This one reads
     * `tests/fixtures/exelearning_emitter_statements.json`, produced by running
     * `public/app/common/xapi/exe_xapi.js` from the eXeLearning repository, so a change
     * to the emitted keys fails here instead of silently emptying the census in
     * production. The emitter first shipped these entries keyed by full extension IRIs,
     * which this parser skips entry by entry — the feature would have been dead on
     * arrival with no error anywhere. See ADR-2302-01 upstream.
     */
    public function test_real_emitter_statements_drive_the_reconstruction(): void {
        global $DB;
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        $captured = json_decode(
            file_get_contents(__DIR__ . '/../../fixtures/exelearning_emitter_statements.json'),
            true
        );
        $this->assertIsArray($captured);
        $this->assertCount(3, $captured);

        // Re-point the captured iDevice identifiers at this activity's grade items,
        // keeping every other byte the emitter produced untouched.
        $ext = 'https://exelearning.net/xapi/extensions/';
        $map = ['idevice-a' => $items[0]->objectid, 'idevice-b' => $items[1]->objectid];
        [$initialized, $answered, $terminated] = $captured;
        foreach ([&$initialized, &$terminated] as &$lifecycle) {
            foreach ($lifecycle['context']['extensions'][$ext . 'idevice-census'] as $i => $entry) {
                if (isset($map[$entry['idevice-id']])) {
                    $lifecycle['context']['extensions'][$ext . 'idevice-census'][$i]['idevice-id']
                        = $map[$entry['idevice-id']];
                }
            }
        }
        unset($lifecycle);
        $answered['context']['extensions'][$ext . 'idevice-id']
            = $map[$answered['context']['extensions'][$ext . 'idevice-id']];

        // The captured lifecycle statements really do carry the gradable iDevices,
        // including the one the learner never answers. That is the point of the census.
        $this->assertSame('http://adlnet.gov/expapi/verbs/initialized', $initialized['verb']['id']);
        $this->assertSame('http://adlnet.gov/expapi/verbs/terminated', $terminated['verb']['id']);
        $this->assertCount(2, $initialized['context']['extensions'][$ext . 'idevice-census']);
        // The page-unload copy is the complete one: it also holds an iDevice that
        // registered after the deferred flush had already gone out.
        $this->assertCount(3, $terminated['context']['extensions'][$ext . 'idevice-census']);

        ingestor::ingest($instance, $course, $cm, $student->id, $initialized, 'real-reg', false);

        // The census reached the grade items with the emitter's own weights and order.
        foreach ([[0, 25.0, 1], [1, 75.0, 2]] as [$index, $weight, $order]) {
            $stored = $DB->get_record('exelearning_grade_item', [
                'exelearningid' => $instance->id,
                'itemnumber'    => $items[$index]->itemnumber,
            ]);
            $this->assertEqualsWithDelta($weight, (float) $stored->xapiweight, 0.0001);
            $this->assertSame($order, (int) $stored->xapiorder);
        }

        $result = ingestor::ingest($instance, $course, $cm, $student->id, $answered, 'real-reg', false);

        // Perfect on the weight-25 iDevice with the weight-75 one untouched: 25, and
        // the multipage package emitted no verdict of its own for us to fall back on.
        $this->assertEqualsWithDelta(25.0, $result['rawscore'], 0.0001);
        $this->assertSame('incomplete', $this->attempt($instance, $student->id, 0)->status);

        // The page-unload census is ingested too, and re-learning the same package
        // metadata is idempotent rather than a second, conflicting truth.
        ingestor::ingest($instance, $course, $cm, $student->id, $terminated, 'real-reg', false);
        $stored = $DB->get_record('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'itemnumber'    => $items[0]->itemnumber,
        ]);
        $this->assertEqualsWithDelta(25.0, (float) $stored->xapiweight, 0.0001);
        $this->assertSame(1, (int) $stored->xapiorder);
    }

    public function test_census_is_package_metadata_reused_by_every_learner(): void {
        global $DB;
        [$instance, $first, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        // One learner's page visit teaches the package census...
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $first->id,
            $this->initialized([
                [$items[0]->objectid, 25, 1],
                [$items[1]->objectid, 75, 2],
            ]),
            'seed-reg',
            false
        );
        $stored = $DB->get_record('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'itemnumber'    => $items[1]->itemnumber,
        ]);
        $this->assertEqualsWithDelta(75.0, (float) $stored->xapiweight, 0.0001);
        $this->assertSame(2, (int) $stored->xapiorder);

        // ...and a second learner is graded exactly from their very first answer, without
        // ever emitting a census of their own.
        $second = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($second->id, $course->id, 'student');
        $answered = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $second->id,
            $this->answered($items[1]->objectid, 1.0, null, 75, 2),
            'second-reg',
            false
        );

        $this->assertEqualsWithDelta(75.0, $answered['rawscore'], 0.0001);
    }

    public function test_census_does_not_reconstruct_a_legacy_attempt(): void {
        [$instance, $student, $course, $cm] = $this->create_activity(['grademodel' => 0]);
        $items = $this->items($instance);

        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->initialized([
                [$items[0]->objectid, 25, 1],
                [$items[1]->objectid, 75, 2],
            ]),
            'legacy-reg',
            false
        );
        // A pre-contract answered statement carries no weight at all. Reconstructing it
        // would publish a package of zeroes over the score its package statement carries.
        ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->answered($items[0]->objectid, 1.0),
            'legacy-reg',
            false
        );
        $package = ingestor::ingest(
            $instance,
            $course,
            $cm,
            $student->id,
            $this->package('passed', 0.9),
            'legacy-reg',
            false
        );

        $this->assertEqualsWithDelta(90.0, $package['rawscore'], 0.0001);
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
