Feature: Validate redirects
  As a site administrator
  I want to validate redirects
  So that I can find and fix broken redirect destinations

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Contract test: verifies batch processing works with real WordPress.
  Scenario: Validate batch mode with no issues
    Given there is a published post with a slug of "batch-destination"
    And there is a redirect from "/batch-test-1" to "/batch-destination"
    And there is a redirect from "/batch-test-2" to "/batch-destination"

    When I run `wp legacy-redirector validate`
    Then STDOUT should contain:
      """
      No issues found.
      """

  # Contract test: verifies WordPress post state integration.
  Scenario: Validate a redirect pointing to a trashed post
    Given there is a published post with a slug of "trashed-destination"
    And there is a redirect from "/validate-trashed" to "/trashed-destination"
    And the post "trashed-destination" is trashed

    When I run `wp legacy-redirector validate /validate-trashed`
    Then STDOUT should contain:
      """
      broken redirect
      """
