Feature: Delete a redirect
  As a site administrator
  I want to delete a redirect
  So that obsolete redirects stop firing

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: delete removes the redirect entirely.
  Scenario: Delete a redirect by its source path
    Given there is a published post with a slug of "delete-destination"
    And there is a redirect from "/delete-source" to "/delete-destination"

    When I run `wp wpcom-legacy-redirector delete /delete-source --yes`
    Then STDOUT should contain:
      """
      Success: Deleted redirect: /delete-source
      """

    When I try `wp wpcom-legacy-redirector get /delete-source`
    Then the return code should not be 0
