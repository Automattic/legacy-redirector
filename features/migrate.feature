Feature: Migrating 1.x redirect data
  As a site administrator upgrading from version 1.x
  I want to run the migrate command
  So that my existing redirects fire under 2.0

  Background:
    Given a WP installation with the Legacy Redirector plugin

  # Smoke test: the command is registered, and a site with no redirects
  # migrates cleanly and is then a safe no-op. A fresh install only records
  # its data version once a migration has run - on its first page load, or
  # here, since WP-CLI never runs one on bootstrap. The database reset between
  # scenarios leaves options alone, so start from a fresh install's state.
  Scenario: Migrating a site with no redirects, then running it again
    Given I run `wp eval 'delete_option( "wpcom_legacy_redirector_db_version" );'`

    When I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      Success: Migration complete. 0 redirect(s) inspected
      """

    When I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      Success: Redirect data is already up to date; nothing to migrate.
      """

  # Web requests migrate automatically in batches of 100, but WP-CLI never
  # does, so every count below covers all 210 seeded redirects. A batch on
  # each command's bootstrap would show up here as the dry run inspecting
  # fewer rows, and the dry run itself writing.
  Scenario: Migrating seeded 1.x data end to end
    Given I run `wp eval 'for ( $i = 0; $i < 210; $i++ ) { wp_insert_post( array( "post_type" => "vip-legacy-redirect", "post_status" => "draft", "post_title" => "/legacy-" . $i, "post_name" => md5( "/legacy-" . $i ), "post_excerpt" => "https://external.example.net/" . $i ) ); } delete_option( "wpcom_legacy_redirector_db_version" ); WP_CLI::success( "Seeded legacy redirects." );'`
    And STDOUT should contain:
      """
      Seeded legacy redirects.
      """

    When I run `wp legacy-redirector migrate --dry-run`
    Then STDOUT should contain:
      """
      Dry run - no changes will be made.
      """
    And STDOUT should contain:
      """
      210 redirect(s) would be inspected: 210 would change, 0 need no change, and 0 would be left alone because they were edited after the upgrade began.
      """

    When I run `wp post list --post_type=vip-legacy-redirect --post_status=draft --format=count`
    Then STDOUT should be:
      """
      210
      """

    When I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      Success: Migration complete. 210 redirect(s) inspected in this run: 210 changed, 0 needed no change, 0 left alone because they were edited after the upgrade began, and 0 could not be written. Of those changed, 210 published, 0 source path(s) rewritten, 0 duplicate(s) trashed, 0 destination(s) made relative.
      """

    When I run `wp post list --post_type=vip-legacy-redirect --post_status=draft --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `wp legacy-redirector migrate`
    Then STDOUT should contain:
      """
      Success: Redirect data is already up to date; nothing to migrate.
      """
