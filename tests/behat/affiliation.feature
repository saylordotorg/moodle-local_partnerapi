@local @local_partnerapi @javascript
Feature: Choose a visible partner affiliation
  In order to keep private cohorts out of self-service affiliation
  As a learner
  I should only be offered visible AFF- cohorts

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | learner1 | Partner   | Learner  | learner@example.com |
    And the following "cohorts" exist:
      | name            | idnumber    | visible |
      | Visible Partner | AFF-VISIBLE | 1       |
      | Hidden Partner  | AFF-HIDDEN  | 0       |

  Scenario: Hidden affiliation cohorts are absent from the chooser
    Given I log in as "learner1"
    When I visit "/local/partnerapi/affiliation.php"
    Then I should see "Visible Partner"
    And I should not see "Hidden Partner"
