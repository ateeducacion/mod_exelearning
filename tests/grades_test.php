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

namespace mod_exelearning;

use advanced_testcase;
use grade_item;
use grade_grade;
use mod_exelearning\local\attempts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the grade category selector (DEC-19-01) and the per-iDevice
 * gradebook model with no overall column (DEC-25-01, supersedes DEC-19-02).
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::exelearning_apply_grade_category
 * @covers     ::exelearning_recalculate_user_grades
 * @covers     \mod_exelearning\grades\grade_sync
 * @covers     \mod_exelearning\grades\grade_recalculator
 * @covers     \mod_exelearning\grades\grade_item_manager
 */
final class grades_test extends advanced_testcase {
    /**
     * Helper: create a course + exelearning instance with the given overrides.
     *
     * @param array $record extra fields for the generator
     * @return \stdClass the exelearning instance row
     */
    protected function create_activity(array $record = []): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');

        return $generator->create_instance(array_merge(['course' => $course->id], $record));
    }

    /**
     * Helper: fetch a grade_item for an instance by itemnumber.
     *
     * @param \stdClass $instance the exelearning instance row
     * @param int $itemnumber 0=overall, >0=iDevice
     * @return grade_item|false
     */
    protected function fetch_item(\stdClass $instance, int $itemnumber) {
        return grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => 'exelearning',
            'iteminstance' => $instance->id,
            'itemnumber'   => $itemnumber,
            'courseid'     => $instance->course,
        ]);
    }

    /**
     * Toggling "Graded activity" off and back on republishes the stored grades.
     *
     * DEC-13-07 keeps exelearning_attempt when the switch goes off precisely so that
     * "reactivar gradeenabled re-detecta y recalcula desde el historial". grade_sync::sync()
     * only does the re-detection half: it recreates the gradebook columns, empty. The
     * republish-from-history call in exelearning_update_instance() has to be reached for
     * the recompute half, and it was gated on grademodel/grademethod alone.
     *
     * Without gradeenabled in that condition, step 3 below leaves the column at NULL and
     * the teacher sees an empty gradebook for learners who had already been graded.
     */
    public function test_gradeenabled_toggled_back_on_republishes_from_history(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'     => $course->id,
            'grademodel' => EXELEARNING_GRADEMODEL_OVERALL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // A graded attempt, recorded the way a learner produces one.
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $item = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber'
        );
        $first = reset($item);
        \mod_exelearning\local\track::ingest($instance, $course, $cm, $student->id, [
            'session'    => 'sessToggle',
            'cmi'        => [
                'cmi.core.score.raw'      => '80',
                'cmi.core.score.max'      => '100',
                'cmi.core.lesson_status'  => 'completed',
            ],
            'itemscores' => [
                $first->objectid => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
            ],
        ], false);
        $this->assertEqualsWithDelta(80.0, $this->published_overall($instance, $student->id), 0.0001);
        $attempts = $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id]);
        $this->assertGreaterThan(0, $attempts);

        // 1. Switch grading off: the column goes, the history stays.
        $this->assertTrue(exelearning_update_instance($this->update_payload($instance, [
            'gradeenabled' => 0,
        ])));
        $this->assertFalse($this->fetch_item($instance, 0));
        $this->assertSame(
            $attempts,
            $DB->count_records('exelearning_attempt', ['exelearningid' => $instance->id])
        );

        // 2. Switch it back on: the column must come back WITH the stored grade in it,
        // not empty.
        $this->assertTrue(exelearning_update_instance($this->update_payload($instance, [
            'gradeenabled' => 1,
        ])));
        $this->assertNotFalse($this->fetch_item($instance, 0));
        $this->assertEqualsWithDelta(80.0, $this->published_overall($instance, $student->id), 0.0001);
    }

    /**
     * Work done while the activity was NOT graded never becomes a mark, in either grade
     * model (DEC-124-03).
     *
     * The OFF -> ON test above covers recovering history recorded while the activity WAS
     * graded. This is the other interval, and DEC-126-01 makes it trivial: with
     * grading off nothing is recorded at all, so there is nothing that could later be
     * converted into a mark. This test is the guarantee itself, kept because the
     * guarantee is what matters to a teacher, not the mechanism that delivers it.
     *
     * @param int $grademodel The grade model to exercise.
     * @dataProvider ungraded_interval_models_provider
     */
    public function test_work_done_while_ungraded_never_becomes_a_grade(int $grademodel): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'       => $course->id,
            'grademodel'   => $grademodel,
            'gradeenabled' => 0,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        // The learner works through the whole activity while it is a plain resource.
        \mod_exelearning\local\track::ingest($instance, $course, $cm, $student->id, [
            'session'    => 'sessUngraded',
            'cmi'        => [
                'cmi.core.score.raw'     => '95',
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => [
                'ide-a' => ['scorepct' => 95.0, 'weighted' => 100.0, 'title' => 'a'],
            ],
        ], false);

        // Nothing was recorded. An activity that is not graded has no tracking table.
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id,
            'userid'        => $student->id,
        ]));

        // The teacher now turns the activity into a graded one.
        $data = $this->update_payload($instance, ['gradeenabled' => 1]);
        $this->assertTrue(exelearning_update_instance($data));
        $instance = $DB->get_record('exelearning', ['id' => $instance->id]);

        // The columns exist again, and every one of them is empty: nothing the learner
        // did while the activity was ungraded has been converted into a mark.
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertNotSame([], $grades->items, 'Re-enabling grading must recreate the gradebook columns');
        foreach ($grades->items as $itemnumber => $item) {
            $this->assertNull(
                $item->grades[$student->id]->grade ?? null,
                "Item {$itemnumber} must have no grade derived from the ungraded interval"
            );
        }

        // And there is still no attempt: enabling grading does not resurrect the past,
        // it starts recording from now.
        $this->assertFalse($DB->record_exists('exelearning_attempt', [
            'exelearningid' => $instance->id,
            'userid'        => $student->id,
        ]));
    }

    /**
     * Both grade models must obey the same rule about the ungraded interval.
     *
     * @return array<string,array{int}>
     */
    public static function ungraded_interval_models_provider(): array {
        return [
            'overall' => [EXELEARNING_GRADEMODEL_OVERALL],
            'peritem' => [EXELEARNING_GRADEMODEL_PERITEM],
        ];
    }

    /**
     * A programmatic caller that changes the grade model without passing gradeenabled
     * still gets the grades republished.
     *
     * exelearning_update_grades() reads $exelearning->gradeenabled and returns early
     * when it is empty, so an update payload missing the field looked to it exactly like
     * an ungraded activity: the condition fired, the call was made, and it did nothing.
     * The recreated OVERALL column stayed empty on an activity whose stored gradeenabled
     * was 1 the whole time.
     *
     * The form is not affected — it posts the whole instance — but the migration tool and
     * any other server-side caller build the payload by hand, and DEC-124-01 makes the
     * fallback explicit for exactly them.
     */
    public function test_update_omitting_gradeenabled_still_republishes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'     => $course->id,
            'grademodel' => EXELEARNING_GRADEMODEL_PERITEM,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);

        $rows = $DB->get_records(
            'exelearning_grade_item',
            ['exelearningid' => $instance->id, 'deleted' => 0],
            'itemnumber'
        );
        $first = reset($rows);
        \mod_exelearning\local\track::ingest($instance, $course, $cm, $student->id, [
            'session'    => 'sessProg',
            'cmi'        => ['cmi.core.score.raw' => '80', 'cmi.core.score.max' => '100'],
            'itemscores' => [
                $first->objectid => ['scorepct' => 80.0, 'weighted' => 100.0, 'title' => 'a'],
            ],
        ], false);

        // PERITEM -> OVERALL, built by hand and deliberately missing gradeenabled.
        $data = $this->update_payload($instance, ['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        unset($data->gradeenabled);
        $this->assertTrue(exelearning_update_instance($data));

        // The activity was graded throughout, so the new overall column must carry the
        // grade recomputed from the attempt history, not sit empty.
        $this->assertEquals(1, (int) $DB->get_field('exelearning', 'gradeenabled', ['id' => $instance->id]));
        $this->assertEqualsWithDelta(80.0, $this->published_overall($instance, $student->id), 0.0001);
    }

    /**
     * Helper: the published overall grade (itemnumber 0), or null when unset.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @return float|null
     */
    protected function published_overall(\stdClass $instance, int $userid): ?float {
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $userid);
        if (!isset($grades->items[0])) {
            return null;
        }
        $grade = $grades->items[0]->grades[$userid] ?? null;
        return ($grade === null || $grade->grade === null) ? null : (float) $grade->grade;
    }

    /**
     * Helper: build the $data object used to call exelearning_update_instance().
     *
     * @param \stdClass $instance the existing exelearning instance row
     * @param array $overrides fields to override on the update payload
     * @return \stdClass
     */
    protected function update_payload(\stdClass $instance, array $overrides): \stdClass {
        $cm = get_coursemodule_from_instance('exelearning', $instance->id);
        $data = (object) (array) $instance;
        $data->instance = $instance->id;
        $data->coursemodule = $cm->id;
        foreach ($overrides as $field => $value) {
            $data->{$field} = $value;
        }
        return $data;
    }

    /**
     * The configured grade category is applied to every grade item of the
     * activity (overall + per-iDevice), since grade_update() ignores categoryid
     * and the placement is done with grade_item::set_parent() (DEC-19-01).
     */
    public function test_gradecat_places_all_items_in_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'     => $course->id,
            'grademodel' => EXELEARNING_GRADEMODEL_PERITEM,
            'gradecat'   => $category->id,
        ]);

        // The two gradable iDevices (1, 2) must sit under the category. PERITEM
        // has no overall item (DEC-25-01).
        foreach ([1, 2] as $itemnumber) {
            $item = $this->fetch_item($instance, $itemnumber);
            $this->assertInstanceOf(grade_item::class, $item);
            $this->assertEquals(
                (int) $category->id,
                (int) $item->categoryid,
                "Grade item {$itemnumber} should be under the chosen grade category"
            );
        }
    }

    /**
     * Changing the grade category on update moves every grade item to the new
     * category (grade_item::set_parent() runs again on each sync).
     */
    public function test_gradecat_moves_items_on_update(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cat1 = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        $cat2 = $this->getDataGenerator()->create_grade_category(['courseid' => $course->id]);
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance(['course' => $course->id, 'gradecat' => $cat1->id]);

        $this->assertEquals((int) $cat1->id, (int) $this->fetch_item($instance, 1)->categoryid);

        $data = $this->update_payload($instance, ['gradecat' => $cat2->id]);
        $this->assertTrue(exelearning_update_instance($data));

        foreach ([1, 2] as $itemnumber) {
            $this->assertEquals(
                (int) $cat2->id,
                (int) $this->fetch_item($instance, $itemnumber)->categoryid,
                "Grade item {$itemnumber} should have moved to the new category"
            );
        }
    }

    /**
     * In PERITEM there is no overall grade item at all (DEC-25-01): the root cause
     * of the DEC-19-02 problem (a hidden item that aggregates and blanks the
     * student's total) is gone. The per-iDevice grades are published, included in
     * aggregation, and the student's course total is computed from them.
     */
    public function test_peritem_has_no_overall_grade_item(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Per-iDevice (1, 2) attempts so recalc writes each grade.
        attempts::record_item($instance->id, $student->id, 1, 1, 7, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 2, 9, 10, 'completed', 's1');

        exelearning_recalculate_user_grades($instance, $student->id);

        // No overall grade item exists in PERITEM.
        $this->assertFalse($this->fetch_item($instance, 0));

        // The per-iDevice grade is published (grade_get_grades forces a regrade).
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $grades->items[1]->grades[$student->id]->grade, 0.0001);

        // The per-iDevice grade is included in aggregation (not excluded): there is
        // no hidden overall to blank the student's total anymore (DEC-25-01).
        $peritemgrade = grade_grade::fetch([
            'itemid' => $this->fetch_item($instance, 1)->id,
            'userid' => $student->id,
        ]);
        $this->assertInstanceOf(grade_grade::class, $peritemgrade);
        $this->assertFalse((bool) $peritemgrade->is_excluded());
    }

    /**
     * In OVERALL mode the overall is the single visible grade and must never be
     * excluded from aggregation.
     */
    public function test_overall_model_overall_grade_not_excluded(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);

        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        attempts::record_item($instance->id, $student->id, 1, 0, 8, 10, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);

        $overallgrade = grade_grade::fetch([
            'itemid' => $this->fetch_item($instance, 0)->id,
            'userid' => $student->id,
        ]);
        $this->assertInstanceOf(grade_grade::class, $overallgrade);
        $this->assertFalse(
            $overallgrade->is_excluded(),
            'The overall grade is the visible grade in OVERALL mode; never excluded'
        );
    }

    /**
     * Switching the grading model (PERITEM<->OVERALL) must re-publish the grades
     * from the stored attempt history. exelearning_sync_grade_items() deletes and
     * recreates the gradebook columns empty on a model switch, so without the
     * republish every student's grade vanished from the gradebook until they
     * resubmitted, even though exelearning_attempt still held the scores (B2,
     * DEC-34-01).
     *
     * @covers ::exelearning_update_grades
     */
    public function test_grademodel_switch_republishes_grades(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // A real submission records both the overall (item 0) and per-iDevice
        // attempts (track::ingest does this regardless of the model).
        attempts::record_item($instance->id, $student->id, 1, 0, 80, 100, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 1, 7, 10, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 1, 2, 9, 10, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);

        // PERITEM: per-iDevice grades are published, no overall column.
        $before = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $before->items[1]->grades[$student->id]->grade, 0.0001);
        $this->assertFalse($this->fetch_item($instance, 0));

        // Switch to OVERALL through the settings-form update path.
        $data = $this->update_payload($instance, ['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        $this->assertTrue(exelearning_update_instance($data));

        // The overall column now exists and its grade is republished from the
        // stored attempts (80/100) instead of vanishing.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 0));
        $after = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(80.0, (float) $after->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * Changing the aggregation method (grademethod) must re-aggregate the already
     * published grades from the attempt history, not leave them computed with the
     * old method (B2, DEC-34-01).
     *
     * @covers ::exelearning_update_grades
     */
    public function test_grademethod_change_republishes_overall(): void {
        $instance = $this->create_activity([
            'grademodel'  => EXELEARNING_GRADEMODEL_OVERALL,
            'grademethod' => attempts::GRADE_HIGHEST,
        ]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        // Two overall attempts: 90 then 50.
        attempts::record_item($instance->id, $student->id, 1, 0, 90, 100, 'completed', 's1');
        attempts::record_item($instance->id, $student->id, 2, 0, 50, 100, 'completed', 's2');
        exelearning_recalculate_user_grades($instance, $student->id);

        $before = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(90.0, (float) $before->items[0]->grades[$student->id]->grade, 0.0001);

        // Switch HIGHEST -> AVERAGE: the published grade must re-aggregate to 70.
        $data = $this->update_payload($instance, ['grademethod' => attempts::GRADE_AVERAGE]);
        $this->assertTrue(exelearning_update_instance($data));

        $after = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(70.0, (float) $after->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * exelearning_update_grades() — the second half of the gradebook contract that
     * core's grade_update_mod_grades() requires — re-publishes every attempting
     * user's grade from the attempt history when called for all users (B2b,
     * DEC-34-01). Without it, core grade reset/grab/unlock silently dropped grades.
     *
     * @covers ::exelearning_update_grades
     */
    public function test_update_grades_republishes_after_core_clears_grades(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_OVERALL]);
        $course = get_course($instance->course);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        attempts::record_item($instance->id, $student->id, 1, 0, 60, 100, 'completed', 's1');
        exelearning_recalculate_user_grades($instance, $student->id);
        $published = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(60.0, (float) $published->items[0]->grades[$student->id]->grade, 0.0001);

        // Simulate a core grade refresh that wiped the published grade.
        grade_update(
            'mod/exelearning',
            $instance->course,
            'mod',
            'exelearning',
            $instance->id,
            0,
            (object) ['userid' => $student->id, 'rawgrade' => null]
        );
        $cleared = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertNull($cleared->items[0]->grades[$student->id]->grade);

        // The function core now finds republishes from the attempt history.
        exelearning_update_grades($instance, 0);
        $restored = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertEqualsWithDelta(60.0, (float) $restored->items[0]->grades[$student->id]->grade, 0.0001);
    }

    /**
     * Core's grade_update_mod_grades() calls exelearning_grade_item_update($instance)
     * unconditionally before exelearning_update_grades() on every regrade. In PERITEM
     * there is no overall column (DEC-25-01), so neither the direct call nor the core
     * regrade path may create a phantom overall (itemnumber 0) that would inflate the
     * course total (B2b follow-up, DEC-34-01).
     *
     * @covers ::exelearning_grade_item_update
     */
    public function test_core_regrade_creates_no_overall_in_peritem(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $this->assertFalse($this->fetch_item($instance, 0));

        // The primitive core invokes first.
        exelearning_grade_item_update($instance);
        $this->assertFalse(
            $this->fetch_item($instance, 0),
            'grade_item_update must not create a phantom overall column in PERITEM'
        );

        // The full core regrade path (grade_item_update + update_grades).
        $modinstance = (object) (array) $instance;
        $modinstance->modname = 'exelearning';
        grade_update_mod_grades($modinstance, 0);
        $this->assertFalse(
            $this->fetch_item($instance, 0),
            'A core regrade must not create a phantom overall column in PERITEM'
        );
        // The per-iDevice columns are untouched.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
    }

    /**
     * Resetting the course gradebook must not spawn phantom grade items. Looping
     * 0..MAX(itemnumber) and calling grade_update(['reset']) blindly inserted a
     * bare unnamed column for every itemnumber without a live grade item — in
     * PERITEM the overall (0) never exists, so a reset created a phantom overall
     * column that inflated the course total (B3, DEC-34-01).
     *
     * @covers ::exelearning_reset_gradebook
     */
    public function test_reset_gradebook_creates_no_phantom_items(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        // PERITEM: per-iDevice columns exist (1, 2), the overall (0) does not.
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
        $this->assertFalse($this->fetch_item($instance, 0));

        exelearning_reset_gradebook($instance->course);

        // No phantom overall column was created; the real per-iDevice items remain.
        $this->assertFalse(
            $this->fetch_item($instance, 0),
            'Course reset must not create a phantom overall grade item in PERITEM'
        );
        $this->assertInstanceOf(grade_item::class, $this->fetch_item($instance, 1));
    }

    /**
     * Helper: capture the published per-item grades for a set of users.
     *
     * @param \stdClass $instance the exelearning instance row
     * @param int[] $userids users whose grades to read
     * @param int[] $itemnumbers grade item numbers to read
     * @return array<int,array<int,float|null>> userid => itemnumber => grade
     */
    protected function capture_grades(\stdClass $instance, array $userids, array $itemnumbers): array {
        $captured = [];
        foreach ($userids as $uid) {
            $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $uid);
            foreach ($itemnumbers as $itemnumber) {
                $grade = $grades->items[$itemnumber]->grades[$uid]->grade ?? null;
                $captured[$uid][$itemnumber] = ($grade === null) ? null : (float) $grade;
            }
        }
        return $captured;
    }

    /**
     * The batched bulk recalculation (exelearning_update_grades($instance, 0),
     * one query + one grade_update() per item) must publish exactly the same
     * grades as the per-user path (exelearning_recalculate_user_grades()), for
     * every supported aggregation method. The per-user path is the reference.
     *
     * @covers ::exelearning_update_grades
     * @covers ::exelearning_recalculate_grades_for_users
     * @covers \mod_exelearning\local\attempts::fetch_scaled_by_user_item
     * @covers \mod_exelearning\local\attempts::aggregate_values
     */
    public function test_bulk_update_grades_matches_per_user_recalculation(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);
        $course = get_course($instance->course);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $userids = [$student1->id, $student2->id];

        // Two attempts per user on each gradable iDevice (1, 2) plus the overall
        // (0), so every aggregation method has more than one value to choose from.
        attempts::record_item($instance->id, $student1->id, 1, 1, 7, 10, 'completed', 's1a');
        attempts::record_item($instance->id, $student1->id, 2, 1, 9, 10, 'completed', 's1b');
        attempts::record_item($instance->id, $student1->id, 1, 2, 5, 10, 'completed', 's1a');
        attempts::record_item($instance->id, $student1->id, 2, 2, 8, 10, 'completed', 's1b');
        attempts::record_item($instance->id, $student1->id, 1, 0, 60, 100, 'completed', 's1a');
        attempts::record_item($instance->id, $student1->id, 2, 0, 90, 100, 'completed', 's1b');

        attempts::record_item($instance->id, $student2->id, 1, 1, 10, 10, 'completed', 's2a');
        attempts::record_item($instance->id, $student2->id, 2, 1, 4, 10, 'completed', 's2b');
        attempts::record_item($instance->id, $student2->id, 1, 2, 6, 10, 'completed', 's2a');
        attempts::record_item($instance->id, $student2->id, 2, 2, 3, 10, 'completed', 's2b');
        attempts::record_item($instance->id, $student2->id, 1, 0, 50, 100, 'completed', 's2a');
        attempts::record_item($instance->id, $student2->id, 2, 0, 70, 100, 'completed', 's2b');

        // PERITEM publishes the per-iDevice columns (1, 2); the overall (0) does not exist.
        $itemnumbers = [1, 2];

        foreach ([attempts::GRADE_HIGHEST, attempts::GRADE_AVERAGE] as $grademethod) {
            // The grademethod lives on the instance row; recalc reads it from there.
            $this->bump_grademethod($instance, $grademethod);

            // Bulk path (the one being optimised).
            exelearning_update_grades($instance, 0);
            $bulk = $this->capture_grades($instance, $userids, $itemnumbers);

            // Per-user reference path.
            foreach ($userids as $uid) {
                exelearning_recalculate_user_grades($instance, $uid);
            }
            $peruser = $this->capture_grades($instance, $userids, $itemnumbers);

            foreach ($userids as $uid) {
                foreach ($itemnumbers as $itemnumber) {
                    $this->assertEqualsWithDelta(
                        $peruser[$uid][$itemnumber],
                        $bulk[$uid][$itemnumber],
                        0.001,
                        "Bulk grade for user {$uid} item {$itemnumber} (method {$grademethod})"
                            . ' must match the per-user recalculation'
                    );
                }
            }
        }
    }

    /**
     * The bulk path on a fresh instance with no attempts must not throw and must
     * publish nothing (no users to iterate, no grade_update calls).
     *
     * @covers ::exelearning_update_grades
     * @covers ::exelearning_recalculate_grades_for_users
     */
    public function test_bulk_update_grades_no_attempts_is_noop(): void {
        $instance = $this->create_activity(['grademodel' => EXELEARNING_GRADEMODEL_PERITEM]);

        exelearning_update_grades($instance, 0);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $instance->course, 'student');
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $student->id);
        $this->assertNull($grades->items[1]->grades[$student->id]->grade);
    }

    /**
     * Helper: persist a new grademethod on the instance row and reflect it on the
     * in-memory record so subsequent recalcs read the new aggregation.
     *
     * @param \stdClass $instance the exelearning instance row (mutated in place)
     * @param int $grademethod one of the attempts::GRADE_* constants
     */
    protected function bump_grademethod(\stdClass $instance, int $grademethod): void {
        global $DB;
        $instance->grademethod = $grademethod;
        $DB->set_field('exelearning', 'grademethod', $grademethod, ['id' => $instance->id]);
    }
}
