@mod @mod_exelearning
Feature: View a mod_exelearning activity and its attempts report
  In order to use eXeLearning content in a course
  As a teacher or a student
  I need to open the activity and, as a teacher, review attempts

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | exelearning | Evaluable unit | C1     | exe1     |

  # These scenarios only assert server-rendered text, so they intentionally run
  # WITHOUT @javascript: the activity view embeds the eXeLearning package in an
  # iframe plus a SCORM shim that issues XHRs, which under the JS driver leaves
  # pending AJAX/JS that Behat waits on forever (the package content does not
  # exist for a generator-created instance). The non-JS driver renders the PHP
  # output we check here and never loads the iframe.
  Scenario: A teacher opens the activity and sees the detected gradable iDevices
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    Then I should see "Evaluable unit"
    And I should see "Gradable iDevices detected:"
    And I should see "View attempts report"

  Scenario: A teacher opens the attempts report and sees the empty-state message
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    When I follow "View attempts report"
    Then I should see "Attempts report"
    And I should see "No attempts have been recorded yet."

  Scenario: A student opens the activity and does not see the teacher report link
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as student1
    Then I should see "Evaluable unit"
    And I should not see "View attempts report"

  Scenario: A teacher sees the participation summary line with no attempts yet
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    Then I should see "0 of 1 students have attempted this activity."

  # Multi-page detection (server-rendered, no @javascript): a package whose two
  # gradable iDevices live on different pages registers them as two distinct
  # columns. This is the parser/sync half of the RIE-007 / DEC-5-01 fix.
  Scenario: A multi-page package registers one column per gradable iDevice across pages
    Given the following "activities" exist:
      | activity    | name            | course | idnumber | packagefilepath                                |
      | exelearning | Multi-page unit | C1     | exemp    | research/fixtures/elpx/multipage-gradable.elpx |
    And I am on the "Multi-page unit" "exelearning activity" page logged in as teacher1
    Then I should see "Gradable iDevices detected:"
    And I should see "#1 trueorfalse"
    And I should see "#2 guess"

  # Browser-level bridge coverage for DEC-5-01 belongs in manual/Playwright e2e:
  # under moodle-plugin-ci the JS driver can enter the scenario with Moodle core JS
  # still pending before the first Background step. This deterministic Behat case
  # keeps the report contract covered while PHPUnit covers the objectid collision.
  Scenario: Objectid-routed scores on different pages appear in their report columns
    Given the following "activities" exist:
      | activity    | name            | course | idnumber | packagefilepath                                |
      | exelearning | Multi-page unit | C1     | exemp    | research/fixtures/elpx/multipage-gradable.elpx |
    And the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken      | objectid           | score |
      | exemp    | student1 | multipage-attempt | idevice-tf-0001    | 90    |
      | exemp    | student1 | multipage-attempt | idevice-guess-0002 | 30    |
    When I am on the "Multi-page unit" "exelearning activity" page logged in as teacher1
    And I follow "View attempts report"
    Then I should see "Page One"
    And I should see "Page Two"
    And I should see "90.00 / 100.00"
    And I should see "30.00 / 100.00"

  # Delete flow (DEC-0007 phase 2, server-rendered, no @javascript): the delete
  # link carries its own server-side sesskey in the URL. Following it renders the
  # confirmation page; pressing Continue exercises the capability + sesskey +
  # recalculation redirect path end to end.
  # The objectid is the trueorfalse iDevice of the default fixture
  # (actividad-evaluable.elpx, itemnumber 1), so the seeded score produces one
  # attempt row that can be deleted, returning the report to its empty state.
  Scenario: A teacher deletes an attempt and the report returns to the empty state
    Given the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken | objectid                        | score |
      | exe1     | student1 | del-session  | idevice-1779989968114-sevb8qqdy | 75    |
    And I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    When I follow "View attempts report"
    And I follow "Delete attempt"
    And I press "Continue"
    Then I should see "The attempt was deleted and the grade was recalculated."
    And I should see "No attempts have been recorded yet."

  # Separate-groups privacy boundary (report.php lines ~44-56, server-rendered,
  # no @javascript): groupmode 1 = SEPARATEGROUPS on the cm. The editingteacher
  # archetype HAS moodle/site:accessallgroups by default, so it is prohibited via
  # a permission override; the report then auto-selects the teacher's own group
  # (groups_get_activity_group($cm, true)) and must hide out-of-group attempts.
  Scenario: A teacher restricted to a separate group only sees their group's attempts
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student2 | Student   | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student2 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | teacher1 | GA    |
      | student1 | GA    |
      | student2 | GB    |
    And the following "permission overrides" exist:
      | capability                  | permission | role           | contextlevel | reference |
      | moodle/site:accessallgroups | Prohibit   | editingteacher | Course       | C1        |
    And the following "activities" exist:
      | activity    | name         | course | idnumber | groupmode |
      | exelearning | Grouped unit | C1     | exegrp   | 1         |
    And the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken | objectid                        | score |
      | exegrp   | student1 | grp-s1       | idevice-1779989968114-sevb8qqdy | 60    |
      | exegrp   | student2 | grp-s2       | idevice-1779989968114-sevb8qqdy | 40    |
    When I am on the "Grouped unit" "exelearning activity" page logged in as teacher1
    And I follow "View attempts report"
    Then I should see "Student One"
    And I should not see "Student Two"

  # DEC-0007 download: with at least one attempt recorded, the teacher report
  # offers the core dataformat selector (CSV/Excel/ODS/JSON). The empty-state
  # scenario above already asserts no selector appears with no attempts. The
  # objectid is the trueorfalse iDevice registered by the default fixture
  # (actividad-evaluable.elpx); seeding it gives the report a row to export.
  Scenario: The attempts report offers a data download selector when attempts exist
    Given the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken | objectid                        | score |
      | exe1     | student1 | dl-session   | idevice-1779989968114-sevb8qqdy | 80    |
    And I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    When I follow "View attempts report"
    Then I should see "Download report data as"

  # Teacher-layer selector (upstream exelearning#1772, server-rendered, no @javascript):
  # eXeLearning packages hide teacher-only content by default and expose a selector to
  # show it via the package's own ?exe-teacher=1 URL parameter. The plugin appends that
  # parameter to the iframe src whenever the per-activity "Show teacher layer selector"
  # setting (teachermodevisible) is on — for any viewer. The iframe src is server-rendered,
  # so the non-JS driver can assert on it directly.
  Scenario: A teacher sees the exe-teacher parameter when the setting is on
    Given the following "activities" exist:
      | activity    | name           | course | idnumber | teachermodevisible |
      | exelearning | Teacher reveal | C1     | exetr    | 1                  |
    And I am on the "Teacher reveal" "exelearning activity" page logged in as teacher1
    Then the "src" attribute of "iframe#exelearningobject" "css_element" should contain "exe-teacher=1"

  Scenario: A teacher does not see the exe-teacher parameter when the reveal is off
    Given the following "activities" exist:
      | activity    | name             | course | idnumber | teachermodevisible |
      | exelearning | Teacher noreveal | C1     | exetn    | 0                  |
    And I am on the "Teacher noreveal" "exelearning activity" page logged in as teacher1
    Then the "src" attribute of "iframe#exelearningobject" "css_element" should not contain "exe-teacher"

  Scenario: A student also sees the exe-teacher parameter when the setting is on
    Given the following "activities" exist:
      | activity    | name               | course | idnumber | teachermodevisible |
      | exelearning | Teacher student vw | C1     | exets    | 1                  |
    And I am on the "Teacher student vw" "exelearning activity" page logged in as student1
    Then the "src" attribute of "iframe#exelearningobject" "css_element" should contain "exe-teacher=1"
