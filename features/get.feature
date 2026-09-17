Feature: Get a redirect
  As a site administrator
  I want to look up a single redirect
  So that I can inspect its destination and status

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Smoke test: get shows the redirect's fields.
  Scenario: Get a redirect by its source path
    Given there is a published post with a slug of "get-destination"
    And there is a redirect from "/get-source" to "/get-destination"

    When I run `wp legacy-redirector get /get-source`
    Then STDOUT should contain:
      """
      /get-source
      """
    And STDOUT should contain:
      """
      /get-destination
      """
    And STDOUT should contain:
      """
      enabled
      """

  # Contract test: a missing redirect is an error, not empty output.
  Scenario: Get a redirect that does not exist
    When I try `wp legacy-redirector get /no-such-redirect`
    Then the return code should not be 0
