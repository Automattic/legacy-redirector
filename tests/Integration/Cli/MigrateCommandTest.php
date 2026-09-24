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

		$this->assert_stdout_contains( 'Migrating 2 redirect(s) in batches of 2,000, pausing 0.25s after each batch that writes' );
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
	 * A long conflict list shows the first few and says how many more there are.
	 */
	public function test_conflict_list_is_capped(): void {
		for ( $i = 0; $i < 21; $i++ ) {
			$this->create_legacy_redirect( '/clash-' . $i, 'https://external.example.net/one' );
			$this->create_legacy_redirect( '/clash-' . $i . '/', 'https://external.example.net/two' );
		}

		$this->invoke_command( $this->command, array(), array( 'dry-run' => true ) );

		$this->assert_warning_contains( '21 source path(s) would collide' );
		$this->assert_stdout_contains( '  ...and 1 more. Run again with --debug=legacy-redirector to list them all.' );
		$this->assertSame( 20, substr_count( $this->get_stdout(), 'would be drafted' ) );

		$debugged = array_filter( \WP_CLI::$calls, static fn( array $call ): bool => 'debug' === $call[0] && 'legacy-redirector' === $call[2] );
		$this->assertCount( 1, $debugged, 'The rest should go to the debug group.' );
	}

	/**
	 * Redirects the database refuses to write are listed, and the run fails.
	 */
	public function test_failed_writes_fail_the_run(): void {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page' );
		$refuse  = static fn( string $query ): string => preg_match( "/^UPDATE `?{$wpdb->posts}`? /", $query ) ? '' : $query;

		add_filter( 'query', $refuse );
		$this->invoke_command( $this->command, array(), array() );
		remove_filter( 'query', $refuse );

		$this->assert_warning_contains( '1 redirect(s) could not be written' );
		$this->assert_stdout_contains( '  #' . $post_id . ': ' );
		$this->assert_stdout_contains( '0 changed, 0 needed no change, 0 left alone because they were edited after the upgrade began, and 1 could not be written.' );
		$this->assert_error_contains( 'not every redirect could be migrated' );
		$this->assertFalse( $this->output->had_success() );
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
}
