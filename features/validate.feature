Feature: Validate redirects
  As a site administrator
  I want to validate redirects
  So that I can find broken redirects and learn what to do about them

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Contract test: verifies a full pass runs with real WordPress.
  Scenario: Validate every redirect when none are named
    Given there is a published post with a slug of "batch-destination"
    And there is a redirect from "/batch-test-1" to "/batch-destination"
    And there is a redirect from "/batch-test-2" to "/batch-destination"

    When I run `wp legacy-redirector validate`
    Then STDOUT should contain:
      """
      Validated 2 redirect(s): 0 with problems, 0 with warnings.
      """

  # Contract test: verifies WordPress post state integration.
  Scenario: Validate a redirect pointing to a trashed post
    Given there is a published post with a slug of "trashed-destination"
    And there is a redirect from "/validate-trashed" to "/trashed-destination"
    And the post "trashed-destination" is trashed

    When I run `wp legacy-redirector validate /validate-trashed`
    Then STDOUT should contain:
      """
      Found 1 issue
      """
    And STDOUT should contain:
      """
      Restore the post from the trash, or point this redirect elsewhere
      """

  # Contract test: the live check runs; a destination that is a post needs no request.
  Scenario: Test a redirect's destination
    Given there is a published post with a slug of "tested-destination"
    And there is a redirect from "/test-destination-only" to "/tested-destination"

    When I run `wp legacy-redirector test /test-destination-only --only=destination`
    Then STDOUT should contain:
      """
      Destination is valid
      """
