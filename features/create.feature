Feature: Creating a redirect
  As a user
  I want to create a redirect
  So that specific requests are redirected

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Smoke test: basic redirect creation works via CLI.
  Scenario: Create a redirect to a path
    Given there is a published post with a slug of "bar"

    When I run `wp wpcom-legacy-redirector create /foo /bar`
    Then STDOUT should contain:
      """
      Success: Created redirect
      """
    And STDOUT should contain:
      """
      /foo -> /bar
      """

  # Contract test: duplicate sources are rejected with a non-zero exit code.
  Scenario: Creating a duplicate redirect fails
    Given there is a published post with a slug of "dupe-target"
    And there is a redirect from "/dupe-source" to "/dupe-target"

    When I try `wp wpcom-legacy-redirector create /dupe-source /dupe-target`
    Then STDERR should contain:
      """
      Error:
      """
