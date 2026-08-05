@mod @mod_exelearning
Feature: Manage a mod_exelearning activity in a course
  In order to maintain eXeLearning activities
  As a teacher
  I need to duplicate, delete and reconfigure them

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | exelearning | Evaluable unit | C1     | exe1     |

  # Adapted from mod/h5pactivity duplicate_delete_h5pactivity.feature. Runs
  # server-rendered (no @javascript): the course-page action-menu items are in the
  # DOM and the delete confirmation is a normal page, so the standard duplicate /
  # delete steps exercise the full backup/restore + grade-item lifecycle without
  # loading the package iframe. Asserting "Gradable iDevices detected:" on the copy
  # proves the package (and its detected iDevices) survived the duplication.
  Scenario: A teacher duplicates and deletes an eXeLearning activity
    Given I am on the "Evaluable unit" "exelearning activity" page logged in as teacher1
    And I should see "Gradable iDevices detected:"
    And I am on "Course 1" course homepage with editing mode on
    When I duplicate "Evaluable unit" activity
    Then I should see "Evaluable unit (copy)"
    And I am on the "Evaluable unit (copy)" "exelearning activity" page
    And I should see "Evaluable unit (copy)"
    And I should see "Gradable iDevices detected:"
    And I am on "Course 1" course homepage with editing mode on
    And I delete "Evaluable unit (copy)" activity
    And I should not see "Evaluable unit (copy)"
    And I am on the "Evaluable unit" "exelearning activity" page
    And I should see "Gradable iDevices detected:"

  # Adapted from mod/h5pactivity h5pactivity_grade_settings.feature: activity
  # settings are not stored by the generator, so set one on the edit form and
  # confirm it round-trips. Uses this plugin's own attempt-limit field (maxattempt,
  # DEC-0-07) by element id to stay independent of the visible label text.
  Scenario: Activity settings entered on the edit form are persisted
    Given I am on the "Evaluable unit" "exelearning activity editing" page logged in as teacher1
    And I set the field "id_maxattempt" to "3"
    And I press "Save and return to course"
    When I am on the "Evaluable unit" "exelearning activity editing" page
    Then the field "id_maxattempt" matches value "3"
