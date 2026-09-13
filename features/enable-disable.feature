Feature: Enable and disable redirects
  As a site administrator
  I want to toggle redirects on and off
  So that I can pause a redirect without deleting it

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: disable and enable round-trip the redirect's status.
  Scenario: Disable and re-enable a redirect
    Given there is a published post with a slug of "toggle-destination"
    And there is a redirect from "/toggle-source" to "/toggle-destination"

    When I run `wp wpcom-legacy-redirector disable /toggle-source`
    Then STDOUT should contain:
      """
      Success: Disabled redirect: /toggle-source
      """

    When I run `wp wpcom-legacy-redirector get /toggle-source`
    Then STDOUT should contain:
      """
      disabled
      """

    When I run `wp wpcom-legacy-redirector enable /toggle-source`
    Then STDOUT should contain:
      """
      Success: Enabled redirect: /toggle-source
      """

    When I run `wp wpcom-legacy-redirector get /toggle-source`
    Then STDOUT should contain:
      """
      enabled
      """
