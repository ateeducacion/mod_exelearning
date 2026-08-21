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
use mod_exelearning\local\track;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/exelearning/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Data-driven grading matrix: for a given package roster + itemscores map, does the
 * plugin publish the right PERITEM columns AND the right OVERALL grade?
 *
 * Each scenario is run twice — once with grademodel = PERITEM, once with OVERALL —
 * against a synthetic ELPX built on the fly (no binary fixture), so the roster size
 * and the objectids are chosen per scenario.
 *
 * Every expected value in the provider is computed BY HAND (the arithmetic is in the
 * comment next to it) and never derived from the code under test.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_exelearning\local\track
 */
final class grading_matrix_test extends advanced_testcase {
    /** @var float Assertion tolerance for grade comparisons. */
    private const DELTA = 0.0001;

    /**
     * Builds a content.xml manifest from a list of [pageid, deviceid] rows.
     *
     * Same shape as package_test::build_content_xml(), reduced to what the matrix
     * needs: every listed iDevice is gradable (isScorm = 1) and of a scored type, so
     * the roster is exactly the list passed in, in this order — which is the order
     * grade_sync assigns itemnumbers 1..N in.
     *
     * @param array $roster List of [pageid, objectid] pairs, in document order.
     * @return string
     */
    private function build_content_xml(array $roster): string {
        $nav = "<odeNavStructure>\n";
        $lastpage = null;
        foreach ($roster as $i => [$pageid, $deviceid]) {
            if ($pageid !== $lastpage) {
                $nav .= "<odePageId>{$pageid}</odePageId>\n<pageName>Page {$pageid}</pageName>\n";
                $lastpage = $pageid;
            }
            $nav .= "<odePageId>{$pageid}</odePageId>\n";
            $nav .= "<odeIdeviceId>{$deviceid}</odeIdeviceId>\n";
            $nav .= "<odeIdeviceTypeName>trueorfalse</odeIdeviceTypeName>\n";
            $nav .= '<jsonProperties>{"isScorm":1}</jsonProperties>' . "\n";
            $nav .= "<question>Q{$i} on {$pageid}?</question><answer>true</answer>\n";
        }
        $nav .= "</odeNavStructure>\n";

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0">' . "\n"
            . $nav
            . "</ode>\n";
    }

    /**
     * Writes the manifest into a real .elpx zip on disk and returns its absolute path.
     *
     * The generator's `packagefilepath` takes a path (it uploads it into a draft
     * area), so unlike package_test we need the zip on disk rather than as a
     * stored_file.
     *
     * @param array $roster List of [pageid, objectid] pairs.
     * @return string Absolute path to the built .elpx.
     */
    private function make_package_path(array $roster): string {
        $tmp = make_request_directory();
        file_put_contents($tmp . '/content.xml', $this->build_content_xml($roster));
        // The package_manager rejects an archive with no index.html at the root ("corrupt
        // or empty .elpx"), so the synthetic package carries a minimal one.
        file_put_contents($tmp . '/index.html', "<!doctype html><html><body>matrix</body></html>\n");

        $packer = get_file_packer('application/zip');
        $zippath = make_request_directory() . '/matrix.elpx';
        $packer->archive_to_pathname([
            'content.xml' => $tmp . '/content.xml',
            'index.html'  => $tmp . '/index.html',
        ], $zippath);

        return $zippath;
    }

    /**
     * Course + exelearning instance backed by the synthetic package + enrolled student.
     *
     * Mirrors track_test::create_activity_with_student(), with the roster-built
     * package and the grading model injected.
     *
     * @param array $roster List of [pageid, objectid] pairs.
     * @param int $grademodel EXELEARNING_GRADEMODEL_PERITEM | _OVERALL.
     * @return array{0: \stdClass, 1: \stdClass} [instance, student]
     */
    private function create_activity_with_student(array $roster, int $grademodel): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_exelearning_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_exelearning');
        $instance = $generator->create_instance([
            'course'           => $course->id,
            'packagefilepath'  => $this->make_package_path($roster),
            'grademodel'       => $grademodel,
        ]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        return [$instance, $student];
    }

    /**
     * Loads the course and cm records for an instance (ingest() needs both).
     *
     * Same helper as track_test::course_and_cm().
     *
     * @param \stdClass $instance
     * @return array{0: \stdClass, 1: \stdClass} [course, cm]
     */
    private function course_and_cm(\stdClass $instance): array {
        global $DB;
        $cm = get_coursemodule_from_instance('exelearning', $instance->id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        return [$course, $cm];
    }

    /**
     * Maps every registered (non-deleted) objectid to its itemnumber.
     *
     * @param \stdClass $instance
     * @return array<string,int>
     */
    private function itemnumbers_by_objectid(\stdClass $instance): array {
        global $DB;
        $rows = $DB->get_records('exelearning_grade_item', [
            'exelearningid' => $instance->id,
            'deleted'       => 0,
        ], 'itemnumber ASC', 'id, itemnumber, objectid');
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->objectid] = (int) $row->itemnumber;
        }
        return $map;
    }

    /**
     * Published gradebook grade for a user on a given itemnumber (null when unset).
     *
     * Same helper as track_test::published_grade().
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param int $itemnumber
     * @return float|null
     */
    private function published_grade(\stdClass $instance, int $userid, int $itemnumber): ?float {
        $grades = grade_get_grades($instance->course, 'mod', 'exelearning', $instance->id, $userid);
        if (!isset($grades->items[$itemnumber]->grades[$userid])) {
            return null;
        }
        $grade = $grades->items[$itemnumber]->grades[$userid]->grade;
        return ($grade === null) ? null : (float) $grade;
    }

    /**
     * The overall (itemnumber=0) value recorded on the attempt row, as a 0..100 grade.
     *
     * In PERITEM mode the overall gradebook column does not exist (grade_sync deletes
     * it, DEC-25-01), but ingest() still records the attempt row — this reads it back.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @return float|null
     */
    private function overall_attempt_grade(\stdClass $instance, int $userid): ?float {
        global $DB;
        $scaled = $DB->get_field('exelearning_attempt', 'scaledscore', [
            'exelearningid' => $instance->id,
            'userid'        => $userid,
            'itemnumber'    => 0,
            'attempt'       => 1,
        ]);
        if ($scaled === false || $scaled === null) {
            return null;
        }
        return ((float) $scaled) * (float) $instance->grademax;
    }

    /**
     * Scenarios: roster, itemscores the tracker would POST, and the HAND-COMPUTED
     * expectations.
     *
     * `itemscores` values are [scorepct, weight] — `weight` is eXeLearning's
     * per-iDevice weight, posted as the `weighted` field.
     * `expectedperitem` maps objectid => expected gradebook column value; an objectid
     * mapped to null must stay ungraded.
     * `expectedoverall` is the expected overall grade (grademax is 100 throughout, so
     * a percentage equals a grade point).
     *
     * @return array<string,array>
     */
    public static function grading_scenarios(): array {
        return [
            // W1: weighted mean = (100*25 + 0*75) / (25+75) = 2500/100 = 25.
            'W1 two items w25/w75 scores 100/0' => [
                'roster' => [['p1', 'idev-w1-a'], ['p1', 'idev-w1-b']],
                'itemscores' => [
                    'idev-w1-a' => [100.0, 25.0],
                    'idev-w1-b' => [0.0, 75.0],
                ],
                'expectedperitem' => [
                    'idev-w1-a' => 100.0,
                    'idev-w1-b' => 0.0,
                ],
                'expectedoverall' => 25.0,
            ],

            // W2: weighted mean = (100*50 + 0*50) / (50+50) = 5000/100 = 50.
            'W2 two items w50/w50 scores 100/0' => [
                'roster' => [['p1', 'idev-w2-a'], ['p1', 'idev-w2-b']],
                'itemscores' => [
                    'idev-w2-a' => [100.0, 50.0],
                    'idev-w2-b' => [0.0, 50.0],
                ],
                'expectedperitem' => [
                    'idev-w2-a' => 100.0,
                    'idev-w2-b' => 0.0,
                ],
                'expectedoverall' => 50.0,
            ],

            // W3: weighted mean = (100*10 + 100*20 + 0*30 + 0*40) / (10+20+30+40)
            // = (1000 + 2000 + 0 + 0) / 100 = 3000/100 = 30.
            'W3 four items w10/20/30/40 scores 100/100/0/0' => [
                'roster' => [
                    ['p1', 'idev-w3-a'], ['p1', 'idev-w3-b'],
                    ['p2', 'idev-w3-c'], ['p2', 'idev-w3-d'],
                ],
                'itemscores' => [
                    'idev-w3-a' => [100.0, 10.0],
                    'idev-w3-b' => [100.0, 20.0],
                    'idev-w3-c' => [0.0, 30.0],
                    'idev-w3-d' => [0.0, 40.0],
                ],
                'expectedperitem' => [
                    'idev-w3-a' => 100.0,
                    'idev-w3-b' => 100.0,
                    'idev-w3-c' => 0.0,
                    'idev-w3-d' => 0.0,
                ],
                'expectedoverall' => 30.0,
            ],

            // W4: every weight is 0, so the weighted denominator is 0 and the rule
            // falls back to the UNWEIGHTED mean = (100 + 0) / 2 = 50.
            'W4 two items weight 0 both scores 100/0' => [
                'roster' => [['p1', 'idev-w4-a'], ['p1', 'idev-w4-b']],
                'itemscores' => [
                    'idev-w4-a' => [100.0, 0.0],
                    'idev-w4-b' => [0.0, 0.0],
                ],
                'expectedperitem' => [
                    'idev-w4-a' => 100.0,
                    'idev-w4-b' => 0.0,
                ],
                'expectedoverall' => 50.0,
            ],

            // W5: three items over two pages.
            // weighted mean = (50*20 + 100*30 + 0*50) / (20+30+50)
            // = (1000 + 3000 + 0) / 100 = 4000/100 = 40.
            'W5 three items two pages w20/30/50 scores 50/100/0' => [
                'roster' => [
                    ['p1', 'idev-w5-a'],
                    ['p2', 'idev-w5-b'], ['p2', 'idev-w5-c'],
                ],
                'itemscores' => [
                    'idev-w5-a' => [50.0, 20.0],
                    'idev-w5-b' => [100.0, 30.0],
                    'idev-w5-c' => [0.0, 50.0],
                ],
                'expectedperitem' => [
                    'idev-w5-a' => 50.0,
                    'idev-w5-b' => 100.0,
                    'idev-w5-c' => 0.0,
                ],
                'expectedoverall' => 40.0,
            ],

            // W6: PARTIAL report. The roster has 4 gradable iDevices but the learner
            // only reached 2 of them, so the tracker posts only those 2.
            // The denominator is built from the REPORTED items only (the "visited
            // pages" denominator), NOT from the roster:
            // weighted mean = (100*25 + 0*75) / (25+75) = 2500/100 = 25.
            // Had the roster been the denominator (the two silent items counting as
            // 0 with, say, weight 0 -> unweighted, or with their authored weights)
            // the overall would be lower. The two unreported iDevices must stay
            // ungraded (NULL), not 0.
            'W6 partial 2 of 4 reported w25 100 / w75 0' => [
                'roster' => [
                    ['p1', 'idev-w6-a'], ['p1', 'idev-w6-b'],
                    ['p2', 'idev-w6-c'], ['p2', 'idev-w6-d'],
                ],
                'itemscores' => [
                    'idev-w6-a' => [100.0, 25.0],
                    'idev-w6-b' => [0.0, 75.0],
                ],
                'expectedperitem' => [
                    'idev-w6-a' => 100.0,
                    'idev-w6-b' => 0.0,
                    'idev-w6-c' => null,
                    'idev-w6-d' => null,
                ],
                'expectedoverall' => 25.0,
            ],
        ];
    }

    /**
     * Runs one scenario in BOTH grading models and asserts the published grades.
     *
     * @param array $roster List of [pageid, objectid] pairs.
     * @param array $itemscores Map objectid => [scorepct, weight].
     * @param array $expectedperitem Map objectid => expected column value (null = ungraded).
     * @param float $expectedoverall Expected overall grade.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grading_scenarios')]
    public function test_grading_matrix(
        array $roster,
        array $itemscores,
        array $expectedperitem,
        float $expectedoverall
    ): void {
        $this->resetAfterTest();

        $name = $this->dataName();
        $payloaditems = [];
        foreach ($itemscores as $objectid => [$scorepct, $weight]) {
            $payloaditems[$objectid] = [
                'scorepct' => $scorepct,
                'weighted' => $weight,
                'title'    => $objectid,
            ];
        }

        $lines = ["\n=== SCENARIO {$name} ==="];

        // PERITEM.
        [$instance, $student] = $this->create_activity_with_student($roster, EXELEARNING_GRADEMODEL_PERITEM);
        [$course, $cm] = $this->course_and_cm($instance);
        $numbers = $this->itemnumbers_by_objectid($instance);

        // The roster must have registered exactly the iDevices the scenario declares.
        $this->assertSame(
            array_map(fn($r) => $r[1], $roster),
            array_keys($numbers),
            'roster registration mismatch (objectid => itemnumber order)'
        );

        // Set the client overall to the hand-computed value so the (correct)
        // DEC-6-01 divergence warning does not fire; the server recomputes anyway.
        $payload = [
            'session' => 'sessPeritem',
            'cmi' => [
                'cmi.core.score.raw'     => (string) $expectedoverall,
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => $payloaditems,
        ];
        $result = track::ingest($instance, $course, $cm, $student->id, $payload, false);
        $this->assertTrue($result['ok'], 'PERITEM ingest failed');

        $lines[] = 'PERITEM mode:';
        foreach ($expectedperitem as $objectid => $expected) {
            $itemnumber = $numbers[$objectid];
            $observed = $this->published_grade($instance, $student->id, $itemnumber);
            $lines[] = sprintf(
                '  item %d (%-12s) observed=%-8s expected=%-8s %s',
                $itemnumber,
                $objectid,
                $observed === null ? 'NULL' : rtrim(rtrim(number_format($observed, 4, '.', ''), '0'), '.'),
                $expected === null ? 'NULL' : rtrim(rtrim(number_format($expected, 4, '.', ''), '0'), '.'),
                ($expected === null)
                    ? ($observed === null ? 'OK' : 'MISMATCH')
                    : ($observed !== null && abs($observed - $expected) <= self::DELTA ? 'OK' : 'MISMATCH')
            );
            if ($expected === null) {
                $this->assertNull($observed, "PERITEM: {$objectid} must stay ungraded");
            } else {
                $this->assertNotNull($observed, "PERITEM: {$objectid} has no published grade");
                $this->assertEqualsWithDelta($expected, $observed, self::DELTA, "PERITEM: {$objectid}");
            }
        }

        // The itemnumber=0 row has no gradebook column in PERITEM (DEC-25-01) ...
        $overallcolumn = $this->published_grade($instance, $student->id, 0);
        $lines[] = sprintf('  itemnumber=0 column observed=%s expected=NULL', $overallcolumn === null ? 'NULL' : $overallcolumn);
        $this->assertNull($overallcolumn, 'PERITEM: the overall gradebook column must not exist');

        // ... but the itemnumber=0 ATTEMPT ROW still carries the overall.
        $overallattempt = $this->overall_attempt_grade($instance, $student->id);
        $lines[] = sprintf(
            '  itemnumber=0 attempt row observed=%s expected=%s %s',
            $overallattempt === null ? 'NULL' : rtrim(rtrim(number_format($overallattempt, 4, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($expectedoverall, 4, '.', ''), '0'), '.'),
            ($overallattempt !== null && abs($overallattempt - $expectedoverall) <= self::DELTA) ? 'OK' : 'MISMATCH'
        );
        $this->assertNotNull($overallattempt, 'PERITEM: no overall attempt row recorded');
        $this->assertEqualsWithDelta(
            $expectedoverall,
            $overallattempt,
            self::DELTA,
            'PERITEM: overall attempt row'
        );

        // OVERALL.
        [$instance2, $student2] = $this->create_activity_with_student($roster, EXELEARNING_GRADEMODEL_OVERALL);
        [$course2, $cm2] = $this->course_and_cm($instance2);

        $payload2 = [
            'session' => 'sessOverall',
            'cmi' => [
                'cmi.core.score.raw'     => (string) $expectedoverall,
                'cmi.core.score.max'     => '100',
                'cmi.core.lesson_status' => 'completed',
            ],
            'itemscores' => $payloaditems,
        ];
        $result2 = track::ingest($instance2, $course2, $cm2, $student2->id, $payload2, false);
        $this->assertTrue($result2['ok'], 'OVERALL ingest failed');

        $observedoverall = $this->published_grade($instance2, $student2->id, 0);
        $lines[] = 'OVERALL mode:';
        $lines[] = sprintf(
            '  itemnumber=0 column observed=%s expected=%s %s',
            $observedoverall === null ? 'NULL' : rtrim(rtrim(number_format($observedoverall, 4, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($expectedoverall, 4, '.', ''), '0'), '.'),
            ($observedoverall !== null && abs($observedoverall - $expectedoverall) <= self::DELTA) ? 'OK' : 'MISMATCH'
        );

        // Print observed vs expected for the whole scenario, then assert.
        fwrite(STDERR, implode("\n", $lines) . "\n");

        $this->assertNotNull($observedoverall, 'OVERALL: no overall grade published');
        $this->assertEqualsWithDelta(
            $expectedoverall,
            $observedoverall,
            self::DELTA,
            'OVERALL: itemnumber=0 gradebook column'
        );
    }
}
