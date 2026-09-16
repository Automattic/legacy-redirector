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
      Cache-Control: max-age=86400
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

  # End-to-end test: a unicode source stored in its decoded form still fires
  # for the percent-encoded path a browser actually requests.
  Scenario: A unicode redirect fires for the percent-encoded request
    Given there is a published post with a slug of "unicode-http-destination"
    And there is a redirect from "/привет-мир" to "/unicode-http-destination"

    When I request the front-end path "/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82-%D0%BC%D0%B8%D1%80"
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
      /unicode-http-destination
      """

  # End-to-end test: the same, for an astral-plane source.
  Scenario: An emoji redirect fires for the percent-encoded request
    Given there is a published post with a slug of "emoji-http-destination"
    And there is a redirect from "/party-🎉" to "/emoji-http-destination"

    When I request the front-end path "/party-%F0%9F%8E%89"
    Then STDOUT should contain:
      """
      301 Moved Permanently
      """
    And STDOUT should contain:
      """
      /emoji-http-destination
      """
