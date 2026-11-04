Feature: Import redirects from post meta
  As a site administrator
  I want to create redirects from URLs stored in post meta
  So that I can migrate legacy URLs after importing content

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Contract test: meta values become redirects pointing at their posts.
  Scenario: Import redirects from a meta key
    Given there is a published post with a slug of "meta-destination"

    When I run `wp post list --post_name=meta-destination --field=ID --post_status=publish`
    And save STDOUT as {POST_ID}
    And I run `wp post meta add {POST_ID} legacy_redirect_url /old-meta-source`
    And I run `wp legacy-redirector import-from-meta --meta-key=legacy_redirect_url`
    Then STDOUT should contain:
      """
      All of your redirects have been imported.
      """

    When I run `wp legacy-redirector get /old-meta-source`
    Then STDOUT should contain:
      """
      /old-meta-source
      """
    And STDOUT should contain:
      """
      enabled
      """

  # Contract test: a dry run checks each row as the live run would, and does
  # not claim an import it did not make.
  Scenario: A dry run reports a source that already has a redirect
    Given there is a published post with a slug of "meta-dry-destination"
    And there is a redirect from "/meta-dry-source" to "/meta-dry-destination"

    When I run `wp post list --post_name=meta-dry-destination --field=ID --post_status=publish`
    And save STDOUT as {POST_ID}
    And I run `wp post meta add {POST_ID} legacy_redirect_url /meta-dry-source`
    And I try `wp legacy-redirector import-from-meta --meta-key=legacy_redirect_url --dry-run`
    Then the return code should be 0
    And STDOUT should contain:
      """
      already has the source "/meta-dry-source"
      """
    And STDERR should contain:
      """
      Dry run: 1 row would fail. Nothing was changed.
      """
    And STDOUT should not match /All of your redirects have been imported/
