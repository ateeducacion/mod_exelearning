@mod @mod_exelearning
Feature: mod_exelearning grades reach the course gradebook
  In order to grade students on eXeLearning content
  As a teacher
  I need per-iDevice scores to appear in the gradebook

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

  # Adapted from mod/h5pactivity h5pactivity_grade_settings.feature (its
  # "user-grades table" gradebook assertion). The default grade model is per-iDevice
  # (DEC-0-08), so apply_item_scores() publishes one grade_update per gradable
  # iDevice; seeding both iDevices of the multi-page fixture at the same value makes
  # that value appear in the grader report regardless of column order. This is the
  # only behat case that crosses from the attempts report into Moodle's gradebook.
  Scenario: Seeded iDevice scores appear in the grader report
    Given the following "activities" exist:
      | activity    | name            | course | idnumber | packagefilepath                                |
      | exelearning | Multi-page unit | C1     | exemp    | research/fixtures/elpx/multipage-gradable.elpx |
    And the following eXeLearning SCORM scores exist:
      | activity | user     | sessiontoken | objectid           | score |
      | exemp    | student1 | gb-attempt   | idevice-tf-0001    | 80    |
      | exemp    | student1 | gb-attempt   | idevice-guess-0002 | 80    |
    # Direct URL navigation to the grader report (core page resolver) keeps this
    # non-@javascript: the menu step "I navigate to ... in the course gradebook"
    # requires the JS driver, which the rest of this suite avoids.
    When I am on the "Course 1" "grades > Grader report > View" page logged in as "teacher1"
    Then I should see "Student One"
    And I should see "80.00"
