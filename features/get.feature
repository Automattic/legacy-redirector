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

  # Contract test: get renders one item as `wp post get` does, so json is a
  # single object (not a one-element list) and csv is Field,Value rows.
  Scenario: Get a redirect as JSON and as CSV
    Given there is a published post with a slug of "get-destination"
    And there is a redirect from "/get-source" to "/get-destination"

    When I run `wp legacy-redirector get /get-source --format=json`
    Then STDOUT should be JSON containing:
      """
      {"from": "/get-source", "to": "/get-destination", "type": "url", "status": "enabled"}
      """

    When I run `wp legacy-redirector get /get-source --format=csv`
    Then STDOUT should match #^Field,Value\nID,\d+\nfrom,/get-source\nto,/get-destination\ntype,url\nstatus,enabled\nhash,[0-9a-f]{32}$#

  # Contract test: a missing redirect is an error, not empty output.
  Scenario: Get a redirect that does not exist
    When I try `wp legacy-redirector get /no-such-redirect`
    Then the return code should not be 0
