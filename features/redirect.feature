Feature: Front-end redirects
  As a site visitor
  I want legacy URLs to redirect me to their new destination
  So that old links keep working

  # The between-scenario reset leaves pretty permalinks on, which these
  # need: the plugin only redirects 404s, and with plain permalinks an
  # unmatched path is not a 404.
  Background:
    Given a WP installation with the Legacy Redirector plugin

  # End-to-end test: a stored redirect issues a real HTTP 301 response to its
  # destination, identified via the X-Redirect-By header.
  Scenario: A redirect issues an HTTP 301 to its destination
    Given there is a published post with a slug of "http-destination"
    And there is a redirect from "/http-source" to "/http-destination"

    When I request the front-end path "/http-source"
    Then the response should be a 301 redirect to "/http-destination"
    And STDOUT should contain:
      """
      Cache-Control: max-age=86400
      """

  # End-to-end test: the trailing slash is not part of the match, so one
  # stored redirect covers both spellings of the old link (GH #50).
  Scenario: A redirect fires for a request that adds a trailing slash
    Given there is a published post with a slug of "http-destination"
    And there is a redirect from "/http-slash-source" to "/http-destination"

    When I request the front-end path "/http-slash-source/"
    Then the response should be a 301 redirect to "/http-destination"

  # End-to-end test: disabling a redirect stops it firing on the front end.
  Scenario: A disabled redirect does not redirect
    Given there is a published post with a slug of "http-destination"
    And there is a redirect from "/http-disabled-source" to "/http-destination"

    When I run `wp legacy-redirector disable /http-disabled-source`
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
    Then the response should be a 301 redirect to "/unicode-http-destination"

  # End-to-end test: the same, for an astral-plane source.
  Scenario: An emoji redirect fires for the percent-encoded request
    Given there is a published post with a slug of "emoji-http-destination"
    And there is a redirect from "/party-🎉" to "/emoji-http-destination"

    When I request the front-end path "/party-%F0%9F%8E%89"
    Then the response should be a 301 redirect to "/emoji-http-destination"

  # End-to-end test: kses escapes a lone '&' as '&amp;' in whatever a user
  # without unfiltered_html saves, as everyone on VIP is. Neither the source
  # nor the destination may pick that up, however many times it is saved.
  Scenario: Ampersands survive every save by a user whose writes kses filters
    Given I run `wp user create behat-author behat-author@example.com --role=author`
    And I run `wp user add-cap behat-author manage_redirects`

    When I run `wp legacy-redirector create '/find?q=a&page=2' '/dest?a=1&b=2' --user=behat-author`
    And I run `wp legacy-redirector disable '/find?q=a&page=2' --user=behat-author`
    And I run `wp legacy-redirector enable '/find?q=a&page=2' --user=behat-author`
    And I request the front-end path "/find?q=a&page=2"
    Then the response should be a 301 redirect to "/dest?a=1&b=2"
