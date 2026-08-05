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

// NOTE: behat step files live outside the mod_exelearning namespace by Moodle
// convention (the class name maps to the @mod_exelearning step container).

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Steps to exercise mod_exelearning tracking and report behaviour in Behat.
 *
 * The non-JS score seeding step uses the same server-side objectid routing as
 * track.php, keeping CI deterministic while PHPUnit covers the DEC-5-01 collision
 * and browser-level bridge checks remain in the e2e/manual lane.
 *
 * @package    mod_exelearning
 * @category   test
 * @copyright  2026 ATE (Área de Tecnología Educativa)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_exelearning extends behat_base {
    /** @var string DOM id of the package iframe rendered by view.php. */
    const IFRAME_ID = 'exelearningobject';

    /**
     * Seeds SCORM scores through the same objectid routing used by track.php.
     *
     * Required columns: activity, user, sessiontoken, objectid, score.
     *
     * @Given /^the following eXeLearning SCORM scores exist:$/
     * @param TableNode $table Scores keyed by activity idnumber and username.
     */
    public function the_following_exelearning_scorm_scores_exist(TableNode $table): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/exelearning/lib.php');

        $required = ['activity', 'user', 'sessiontoken', 'objectid', 'score'];
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'exelearning'], MUST_EXIST);

        foreach ($table->getHash() as $row) {
            foreach ($required as $column) {
                if (!array_key_exists($column, $row) || $row[$column] === '') {
                    throw new \coding_exception('Missing required eXeLearning score column: ' . $column);
                }
            }
            if (!is_numeric($row['score'])) {
                throw new \coding_exception('eXeLearning SCORM score must be numeric.');
            }

            $cm = $DB->get_record('course_modules', [
                'module'   => $moduleid,
                'idnumber' => $row['activity'],
            ], '*', MUST_EXIST);
            $exe = $DB->get_record('exelearning', ['id' => $cm->instance], '*', MUST_EXIST);
            $user = $DB->get_record('user', ['username' => $row['user']], '*', MUST_EXIST);
            $sessiontoken = (string) $row['sessiontoken'];
            $attempt = \mod_exelearning\local\attempts::resolve_attempt_number(
                (int) $exe->id,
                (int) $user->id,
                $sessiontoken
            );

            \mod_exelearning\local\track::apply_item_scores(
                $exe,
                (int) $user->id,
                $attempt,
                [
                    (string) $row['objectid'] => [
                        'scorepct' => (float) $row['score'],
                        'weighted' => 100.0,
                        'title'    => (string) $row['objectid'],
                    ],
                ],
                $sessiontoken
            );
        }
    }

    /**
     * Waits until the package iframe has loaded and exposes gradable iDevice nodes.
     *
     * @Given /^I wait until the eXeLearning package iframe has loaded$/
     */
    public function i_wait_until_the_exelearning_package_iframe_has_loaded(): void {
        $condition = "(function(){var f=document.getElementById('" . self::IFRAME_ID . "');"
            . "return !!(f && f.contentDocument && "
            . "f.contentDocument.querySelectorAll('.idevice_node').length);})()";
        if (!$this->getSession()->wait(10 * 1000, $condition)) {
            throw new ExpectationException(
                'The eXeLearning package iframe did not load any iDevice nodes.',
                $this->getSession()
            );
        }
    }

    /**
     * Reports a SCORM score for the gradable iDevice of the currently loaded page.
     *
     * Builds the producer's cmi.suspend_data line for that iDevice at its real
     * page-local index N and hands it to the shim through window.API, then commits.
     *
     * @When /^I report a SCORM score of "(?P<score_string>\d+)" for the gradable iDevice on the current eXeLearning page$/
     * @param string $score Score percentage to report.
     */
    public function i_report_a_scorm_score_for_the_gradable_idevice(string $score): void {
        $pct = (int) $score;
        $frameid = self::IFRAME_ID;
        $js = <<<JS
(function(){
  var f = document.getElementById('{$frameid}');
  var doc = f.contentDocument;
  var nodes = doc.querySelectorAll('.idevice_node');
  var n = 0, title = '';
  for (var i = 0; i < nodes.length; i++) {
    var t = nodes[i].getAttribute('data-idevice-type');
    if (t && t !== 'text') {
      n = i + 1;
      var h = nodes[i].querySelector('.box-title');
      title = h ? h.textContent : '';
      break;
    }
  }
  if (!n) { return; }
  var line = n + '. "' + title + '"; Score: {$pct}%; Weight: 100%';
  window.API.LMSSetValue('cmi.suspend_data', line);
  window.API.LMSSetValue('cmi.core.score.raw', '{$pct}');
  window.API.LMSSetValue('cmi.core.score.max', '100');
  window.API.LMSSetValue('cmi.core.lesson_status', 'completed');
  window.API.LMSCommit();
})();
JS;
        $this->execute_script($js);
    }

    /**
     * Navigates the package iframe to another page of the package (full navigation).
     *
     * @When /^I switch the eXeLearning iframe to the package page "(?P<page_string>[^"]*)"$/
     * @param string $page Package-relative path, e.g. "html/page-2.html".
     */
    public function i_switch_the_exelearning_iframe_to_the_package_page(string $page): void {
        $page = addslashes($page);
        $js = "(function(){var f=document.getElementById('" . self::IFRAME_ID . "');"
            . "f.src=f.src.replace(/(content\\/\\d+\\/).*$/, '\$1{$page}');})();";
        $this->execute_script($js);
        $this->i_wait_until_the_exelearning_package_iframe_has_loaded();
    }
}
