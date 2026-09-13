Feature: Import redirects from post meta
  As a site administrator
  I want to create redirects from URLs stored in post meta
  So that I can migrate legacy URLs after importing content

  Background:
    Given a WP installation with the WPCOM Legacy Redirector plugin

  # Contract test: meta values become redirects pointing at their posts.
  Scenario: Import redirects from a meta key
    Given there is a published post with a slug of "meta-destination"

    When I run `wp post list --post_name=meta-destination --field=ID --post_status=publish`
    And save STDOUT as {POST_ID}
    And I run `wp post meta add {POST_ID} legacy_redirect_url /old-meta-source`
    And I run `wp wpcom-legacy-redirector import-from-meta --meta-key=legacy_redirect_url`
    Then STDOUT should contain:
      """
      All of your redirects have been imported.
      """

    When I run `wp wpcom-legacy-redirector get /old-meta-source`
    Then STDOUT should contain:
      """
      /old-meta-source
      """
    And STDOUT should contain:
      """
      enabled
      """
