Feature: Import redirects from CSV
  As a site administrator
  I want to import redirects from a CSV file
  So that I can bulk create redirects

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Contract test: verifies file I/O and bulk import workflow.
  Scenario: Import redirects from a valid CSV file
    Given there is a published post with a slug of "destination-post"
    And a CSV file "redirects.csv" with content:
      """
      /old-page,/destination-post
      /another-old-page,/destination-post
      """

    When I run `wp legacy-redirector import /tmp/redirects.csv --skip-validation`
    Then STDOUT should contain:
      """
      Processed 2 redirects.
      """

    When I run `wp legacy-redirector get /another-old-page --field=to`
    Then STDOUT should be:
      """
      /destination-post
      """

  # Contract test: the export `list` documents imports unedited, header and
  # status included, piped in on STDIN.
  Scenario: A list export imports back from STDIN
    Given there is a published post with a slug of "destination-post"
    And there is a redirect from "/round-trip" to "/destination-post"

    When I run `wp legacy-redirector disable /round-trip`
    And I run `wp legacy-redirector list --fields=from,to,status --format=csv`
    And save STDOUT as {EXPORT}
    And I run `wp legacy-redirector delete /round-trip --yes`
    And I run `wp legacy-redirector import -` with STDIN:
      """
      {EXPORT}
      """
    Then STDOUT should contain:
      """
      Processed 1 redirect.
      """

    When I run `wp legacy-redirector get /round-trip --fields=to,status --format=csv`
    Then STDOUT should be:
      """
      to,status
      /destination-post,disabled
      """

  # Contract test: one refused row fails the run, so a script cannot mistake a
  # partial import for a clean one, and the rows that passed are still saved.
  Scenario: An import with a refused row exits non-zero
    Given there is a published post with a slug of "destination-post"
    And a CSV file "partial.csv" with content:
      """
      /good-row,/destination-post
      /bad-row,/bad-row
      """

    When I try `wp legacy-redirector import /tmp/partial.csv`
    Then the return code should be 1
    And STDERR should contain:
      """
      Processed 1 of 2 rows: 1 failed.
      """

    When I run `wp legacy-redirector get /good-row --field=to`
    Then STDOUT should be:
      """
      /destination-post
      """
