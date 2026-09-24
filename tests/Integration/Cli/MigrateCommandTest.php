<?php
/**
 * MigrateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\MigrateCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Integration tests for MigrateCommand.
 *
 * The Upgrader itself is covered by UpgraderTest and UpgraderMultisiteTest;
 * these tests cover the CLI entry point wrapped around it: the dry-run flag,
 * the already-up-to-date short circuit, and the progress and summary output.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\MigrateCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class MigrateCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var MigrateCommand
	 */
	private MigrateCommand $command;

	/**
	 * The upgrade routine the command wraps.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( 'wpcom_legacy_redirector_upgrade_started_gmt' );
		delete_option( 'wpcom_legacy_redirector_upgrade_cursor' );
		delete_option( 'wpcom_legacy_redirector_upgrade_ceiling' );
		delete_transient( 'wpcom_legacy_redirector_upgrade_cli' );
		delete_option( 'wpcom_legacy_redirector_upgrade_retry' );

		$this->upgrader = new Upgrader();
		$this->command  = new MigrateCommand( $this->upgrader );
	}

	/**
	 * Create a redirect exactly as version 1.x would have stored it.
	 *
	 * 1.x called wp_insert_post() with no post_status, so WordPress defaulted
	 * every redirect to 'draft'.
	 *
	 * @param string $source_path The source path as 1.x stored it.
	 * @param string $destination The destination URL.
	 * @return int The created post ID.
	 */
	private function create_legacy_redirect( string $source_path, string $destination = 'https://external.example.net/x' ): int {
		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $source_path ),
				'post_title'   => $source_path,
				'post_excerpt' => $destination,
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * An already-current site short-circuits without touching anything.
	 */
	public function test_up_to_date_site_reports_nothing_to_migrate(): void {
		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION );
		$post_id = $this->create_legacy_redirect( '/left-alone' );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_success_contains( 'Redirect data is already up to date; nothing to migrate.' );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * A dry run reports every pending change without writing any of them.
	 */
	public function test_dry_run_reports_pending_work_without_writing(): void {
		$external_id = $this->create_legacy_redirect( '/old-external' );
		$internal_id = $this->create_legacy_redirect( '/old-internal', home_url( '/new-page' ) );

		$this->invoke_command( $this->command, array(), array( 'dry-run' => true ) );

		$this->assert_stdout_contains( 'Dry run - no changes will be made.' );
		$this->assert_stdout_contains( 'Checked 2 of 2 (100%)' );
		$this->assert_stdout_contains(
			'2 redirect(s) would be inspected: 2 would change, 0 need no change, and 0 would be left alone because they were edited after the upgrade began.'
		);
		$this->assert_stdout_contains(
			'Of those changing, 2 would be published, 0 would have their source path rewritten, 0 would be trashed as duplicates, and 1 would have their destination made relative.'
		);

		$this->assertSame( 'draft', get_post_status( $external_id ) );
		$this->assertSame( 'draft', get_post_status( $internal_id ) );
		$this->assertSame( home_url( '/new-page' ), get_post( $internal_id )->post_excerpt );
		$this->assertTrue( $this->upgrader->needs_upgrade(), 'A dry run must leave the upgrade still pending.' );
	}

	/**
	 * A real run migrates the data and reports what it did.
	 */
	public function test_migrate_publishes_legacy_redirects_and_reports_totals(): void {
		$external_id = $this->create_legacy_redirect( '/old-external' );
		$internal_id = $this->create_legacy_redirect( '/old-internal', home_url( '/new-page' ) );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_stdout_contains( 'Migrating 2 redirect(s).' );
		$this->assert_stdout_contains( 'Processed 2 of 2 (100%)' );
		$this->assert_stdout_not_contains( 'Resuming' );
		$this->assert_success_contains(
			'Migration complete. 2 redirect(s) inspected in this run: 2 changed, 0 needed no change, 0 left alone because they were edited after the upgrade began, and 0 could not be written. Of those changed, 2 published, 0 source path(s) rewritten, 0 duplicate(s) trashed, 1 destination(s) made relative.'
		);

		$this->assertSame( 'publish', get_post_status( $external_id ) );
		$this->assertSame( 'publish', get_post_status( $internal_id ) );
		$this->assertSame( '/new-page', get_post( $internal_id )->post_excerpt );
		$this->assertFalse( $this->upgrader->needs_upgrade() );
	}

	/**
	 * A run picking up after an interrupted one says so, and counts from there.
	 */
	public function test_resumed_run_reports_where_it_picked_up(): void {
		$this->create_legacy_redirect( '/first' );
		$this->create_legacy_redirect( '/second' );
		$this->create_legacy_redirect( '/third' );

		// An earlier run that stopped after one redirect.
		$this->upgrader->run_batch( 1 );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_stdout_contains( 'Resuming where the last run stopped: 1 of 3 redirect(s) already done.' );
		$this->assert_stdout_contains( 'Migrating 2 redirect(s)' );
		$this->assert_stdout_contains( 'Processed 3 of 3 (100%)' );
		$this->assert_success_contains( 'Migration complete. 2 redirect(s) inspected in this run' );
	}

	/**
	 * A long duplicate-source list shows the first few and says how many more there are.
	 */
	public function test_duplicate_source_list_is_capped(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->create_legacy_redirect( '/clash-' . $i, 'https://external.example.net/one' );
			$this->create_legacy_redirect( '/clash-' . $i . '/', 'https://external.example.net/two' );
		}

		$this->invoke_command( $this->command, array(), array( 'dry-run' => true ) );

		$this->assert_warning_contains( '21 redirect(s) would have the same source as another redirect with a different destination.' );
		$this->assert_stdout_contains( '  ...and 1 more. To list every one, repeat this dry run with --debug=legacy-redirector; like this one, it changes nothing.' );
		$this->assertSame( 20, substr_count( $this->get_stdout(), 'has the same source as' ) );

		$debugged = array_filter( \WP_CLI::$calls, static fn( array $call ): bool => 'debug' === $call[0] && 'legacy-redirector' === $call[2] );
		$this->assertCount( 1, $debugged, 'The rest should go to the debug group.' );
	}

	/**
	 * Redirects the database refuses to write are listed, and the run fails.
	 */
	public function test_failed_writes_fail_the_run_then_are_retried(): void {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page/' );
		$refuse  = static fn( string $query ): string => str_starts_with( $query, "UPDATE `{$wpdb->posts}`" ) ? '' : $query;

		add_filter( 'query', $refuse );
		$this->invoke_command( $this->command, array(), array() );
		remove_filter( 'query', $refuse );

		$this->assert_warning_contains( '1 redirect(s) could not be written' );
		$this->assert_stdout_contains( '  #' . $post_id . ' (/old-page/): ' );
		$this->assert_stdout_contains( '0 changed, 0 needed no change, 0 left alone because they were edited after the upgrade began, and 1 could not be written.' );
		$this->assert_error_contains( '1 redirect(s) are waiting to be retried. Once the cause is fixed, run `wp legacy-redirector migrate` again: it retries just those, without walking the rest again.' );
		$this->assertFalse( $this->output->had_success() );

		$this->invoke_command( $this->command, array(), array( 'dry-run' => true ) );
		$this->assert_stdout_contains( '1 redirect(s) could not be written in an earlier run. Running `wp legacy-redirector migrate` without --dry-run retries just those.' );

		$this->invoke_command( $this->command, array(), array() );
		$this->assert_stdout_contains( 'Retrying the 1 redirect(s) that could not be written in an earlier run.' );
		$this->assert_success_contains( 'Retry complete. 1 redirect(s) inspected in this run: 1 changed' );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( '/old-page', get_post( $post_id )->post_title );

		$this->invoke_command( $this->command, array(), array() );
		$this->assert_success_contains( 'Redirect data is already up to date; nothing to migrate.' );
	}

	/**
	 * A database failure stops the run with a message saying it is safe to rerun.
	 */
	public function test_database_failure_stops_the_run_safely(): void {
		global $wpdb;

		$this->create_legacy_redirect( '/old-page' );
		$refuse = static fn( string $query ): string => str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" ) ? '' : $query;

		add_filter( 'query', $refuse );
		$this->invoke_command( $this->command, array(), array() );
		remove_filter( 'query', $refuse );

		$this->assert_error_contains( 'Stopped because the database could not read the redirects' );
		$this->assert_error_contains( 'It is safe to run the same command again' );
		$this->assertTrue( $this->upgrader->needs_upgrade() );
	}

	/**
	 * A duplicate source is reported with both destinations, and where to list them later.
	 */
	public function test_duplicate_source_is_reported_with_both_destinations(): void {
		$kept_id  = $this->create_legacy_redirect( '/clash', 'https://external.example.net/one' );
		$loser_id = $this->create_legacy_redirect( '/clash/', 'https://external.example.net/two' );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_warning_contains( '1 redirect(s) now have the same source as another redirect with a different destination.' );
		$this->assert_warning_contains( 'the one already there stays live, and the other has been disabled.' );
		$this->assert_stdout_contains( sprintf( '  /clash/ → https://external.example.net/two (#%d) has the same source as /clash → https://external.example.net/one (#%d)', $loser_id, $kept_id ) );
		$this->assert_stdout_contains( '  - To keep the live one\'s, delete the disabled redirect: wp legacy-redirector delete <disabled ID>' );
		$this->assert_stdout_contains( '`wp legacy-redirector list --duplicates` lists them, now or later, as does the Duplicate sources view on the Redirects screen.' );
		$this->assertSame( 'draft', get_post_status( $loser_id ) );
		$this->assertSame( 'publish', get_post_status( $kept_id ) );
	}

	/**
	 * Running the command again after completion is a safe no-op.
	 */
	public function test_second_run_is_a_no_op(): void {
		$this->create_legacy_redirect( '/old-page' );

		$this->invoke_command( $this->command, array(), array() );
		$this->assert_success_contains( 'Migration complete.' );

		$this->invoke_command( $this->command, array(), array() );
		$this->assert_success_contains( 'Redirect data is already up to date; nothing to migrate.' );
	}

	/**
	 * A duplicate that never fired under 1.x is called out as safe to delete.
	 */
	public function test_never_fired_duplicate_is_called_out(): void {
		$this->create_legacy_redirect( '/caf%C3%A9-x', 'https://external.example.net/one' );
		$this->create_legacy_redirect( '/café-x/', 'https://external.example.net/two' );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_stdout_contains( '(never fired under 1.x)' );
		$this->assert_stdout_contains( '1 of them never fired under 1.x (marked above), so disabling them changed nothing for visitors. Delete them unless you want their destination.' );
		$this->assert_stdout_not_contains( 'For each, decide which destination is right' );
	}
}
