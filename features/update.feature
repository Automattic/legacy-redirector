Feature: Update a redirect
  As a site administrator
  I want to change a redirect's destination
  So that I can fix or repoint redirects without recreating them

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: update changes the stored destination.
  Scenario: Update a redirect's destination
    Given there is a published post with a slug of "update-destination-one"
    And there is a published post with a slug of "update-destination-two"
    And there is a redirect from "/update-source" to "/update-destination-one"

    When I run `wp wpcom-legacy-redirector update /update-source --to=/update-destination-two`
    Then STDOUT should contain:
      """
      Success: Updated redirect: /update-source
      """

    When I run `wp wpcom-legacy-redirector get /update-source`
    Then STDOUT should contain:
      """
      /update-destination-two
      """
