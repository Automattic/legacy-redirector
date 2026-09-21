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
		$this->assert_stdout_contains(
			'2 redirect(s) would be inspected, of which 2 would be published, 0 would have their source path rewritten, 0 would be trashed as duplicates, and 1 would have their destination made relative.'
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

		$this->assert_stdout_contains( 'Processed 2 redirect(s)...' );
		$this->assert_success_contains(
			'Migration complete. 2 redirect(s) inspected, 2 published, 0 source path(s) rewritten, 0 duplicate(s) trashed, 1 destination(s) made relative.'
		);

		$this->assertSame( 'publish', get_post_status( $external_id ) );
		$this->assertSame( 'publish', get_post_status( $internal_id ) );
		$this->assertSame( '/new-page', get_post( $internal_id )->post_excerpt );
		$this->assertFalse( $this->upgrader->needs_upgrade() );
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
