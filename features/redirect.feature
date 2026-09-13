Feature: Front-end redirects
  As a site visitor
  I want legacy URLs to redirect me to their new destination
  So that old links keep working

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin
    # The plugin only redirects 404s; with plain permalinks an unmatched
    # path is not a 404, so pretty permalinks are required.
    And I run `wp rewrite structure /%postname%/ --hard`

  # End-to-end test: a stored redirect issues a real HTTP 301 response,
  # identified via the X-Redirect-By header.
  Scenario: A redirect issues an HTTP 301 to its destination
    Given there is a published post with a slug of "http-destination"
    And there is a redirect from "/http-source" to "/http-destination"

    When I request the front-end path "/http-source"
    Then STDOUT should contain:
      """
      301 Moved Permanently
      """
    And STDOUT should contain:
      """
      X-Redirect-By: WPCOM Legacy Redirector
      """
    And STDOUT should contain:
      """
      /http-destination
      """

  # End-to-end test: disabling a redirect stops it firing on the front end.
  Scenario: A disabled redirect does not redirect
    Given there is a published post with a slug of "http-destination"
    And there is a redirect from "/http-disabled-source" to "/http-destination"

    When I run `wp wpcom-legacy-redirector disable /http-disabled-source`
    And I request the front-end path "/http-disabled-source"
    Then STDOUT should contain:
      """
      404 Not Found
      """
