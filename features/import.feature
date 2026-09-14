Feature: Import redirects from CSV
  As a site administrator
  I want to import redirects from a CSV file
  So that I can bulk create redirects

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: verifies file I/O and bulk import workflow.
  Scenario: Import redirects from a valid CSV file
    Given there is a published post with a slug of "destination-post"
    And a CSV file "redirects.csv" with content:
      """
      /old-page,/destination-post
      /another-old-page,/destination-post
      """

    When I run `wp wpcom-legacy-redirector import /tmp/redirects.csv --skip-validation`
    Then STDOUT should contain:
      """
      Processed 2 redirects.
      """
