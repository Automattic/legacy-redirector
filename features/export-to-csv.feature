Feature: Export redirects to CSV
  As a site administrator
  I want to export redirects to a CSV file
  So that I can backup or migrate redirects

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: verifies file I/O and bulk export workflow.
  Scenario: Export redirects to a CSV file
    Given there is a published post with a slug of "export-destination"
    And there is a redirect from "/export-test-1" to "/export-destination"

    When I run `wp wpcom-legacy-redirector export-to-csv --csv=/tmp/exported-redirects.csv --overwrite`
    Then the return code should be 0

    When I run `wp eval 'echo file_get_contents( "/tmp/exported-redirects.csv" );'`
    Then STDOUT should contain:
      """
      /export-test-1
      """
    And STDOUT should contain:
      """
      /export-destination
      """
