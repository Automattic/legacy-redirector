<?php
/**
 * ImportCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;

/**
 * Integration tests for ImportCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ImportCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ImportCommand
	 */
	private ImportCommand $command;

	/**
	 * Paths of temporary CSV files created during a test.
	 *
	 * @var string[]
	 */
	private array $csv_files = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new ImportCommand( $this->manager(), $this->auditor() );
	}

	/**
	 * Clean up temporary CSV files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->csv_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		$this->csv_files = array();

		parent::tear_down();
	}

	/**
	 * Write CSV content to a temporary file.
	 *
	 * @param string $content The CSV content.
	 * @return string The file path.
	 */
	private function create_csv( string $content ): string {
		$file = tempnam( sys_get_temp_dir(), 'redirects-test-' );
		file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->csv_files[] = $file;

		return $file;
	}

	/**
	 * Check whether a redirect exists for a source path.
	 *
	 * @param string $from The source path.
	 * @return bool
	 */
	private function redirect_exists( string $from ): bool {
		return $this->repository()->get_id_by_source( SourceUrl::from_string( $from ) ) > 0;
	}

	/**
	 * Count the redirect posts stored for a source path, in any status.
	 *
	 * Queries by the stored hash directly, because a duplicate insert produces
	 * two posts sharing one `post_name` and repository lookups only ever return
	 * the first.
	 *
	 * @param string $from The source path.
	 * @return int The number of matching posts.
	 */
	private function count_redirect_posts( string $from ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against uncached state.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = %s AND post_name = %s",
				PostTypeRedirectRepository::POST_TYPE,
				SourceUrl::from_string( $from )->hash()
			)
		);
	}

	/**
	 * Test importing new redirects.
	 */
	public function test_import_creates_redirects(): void {
		$file = $this->create_csv( "/import-one,https://example.com/a\n/import-two,https://example.com/b\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Processed 2 redirects.' );
		$this->assertTrue( $this->redirect_exists( '/import-one' ) );
		$this->assertTrue( $this->redirect_exists( '/import-two' ) );
	}

	/**
	 * Test our own CSV export header row is skipped.
	 */
	public function test_import_skips_export_header_row(): void {
		$file = $this->create_csv( "from,to,status\n/import-header,https://example.com/a,enabled\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );
		$this->assertTrue( $this->redirect_exists( '/import-header' ) );
	}

	/**
	 * Test a malformed first row is still reported rather than skipped.
	 */
	public function test_import_reports_malformed_first_row(): void {
		$file = $this->create_csv( "http://example.com,https://example.com/a\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Invalid source' );
	}

	/**
	 * Test the optional status column creates disabled redirects.
	 */
	public function test_import_respects_status_column(): void {
		$file = $this->create_csv( "/import-disabled,https://example.com/a,disabled\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_success();

		$repository = $this->repository();
		$redirect   = $repository->find_by_id(
			$repository->get_id_by_source( SourceUrl::from_string( '/import-disabled' ) )
		);
		$this->assertNotNull( $redirect );
		$this->assertFalse( $redirect->is_active() );
	}

	/**
	 * Test create mode reports duplicates as errors.
	 */
	public function test_import_create_mode_rejects_duplicates(): void {
		$this->create_redirect( '/import-dupe', 'https://example.com/original' );
		$file = $this->create_csv( "/import-dupe,https://example.com/replacement\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array()
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Error: 1' );
	}

	/**
	 * Test upsert mode updates existing redirects.
	 */
	public function test_import_upsert_updates_existing(): void {
		$this->create_redirect( '/import-upsert', 'https://example.com/original' );
		$file = $this->create_csv( "/import-upsert,https://example.com/replacement\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'mode' => 'upsert' )
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );

		$repository = $this->repository();
		$redirect   = $repository->find_by_id(
			$repository->get_id_by_source( SourceUrl::from_string( '/import-upsert' ) )
		);
		$this->assertSame( 'https://example.com/replacement', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test upsert mode creates redirects that do not exist yet.
	 */
	public function test_import_upsert_creates_missing(): void {
		$file = $this->create_csv( "/import-upsert-new,https://example.com/new\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'mode'            => 'upsert',
				'skip-validation' => true,
			)
		);

		$this->assert_command_success();
		$this->assertTrue( $this->redirect_exists( '/import-upsert-new' ) );
	}

	/**
	 * Test dry run makes no changes.
	 */
	public function test_import_dry_run_makes_no_changes(): void {
		$file = $this->create_csv( "/import-dry,https://example.com/a\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'dry-run' => true )
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( 'would create' );
		$this->assertFalse( $this->redirect_exists( '/import-dry' ) );
	}

	/**
	 * Test rows with a missing destination are reported as errors.
	 */
	public function test_import_reports_missing_destination(): void {
		$file = $this->create_csv( "/import-no-dest\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array()
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Missing destination' );
	}

	/**
	 * Test upsert mode re-points a disabled redirect rather than duplicating it.
	 *
	 * @see https://linear.app/a8c/issue/VIPPLUG-133
	 */
	public function test_import_upsert_updates_disabled_redirect(): void {
		$redirect_id = $this->create_redirect( '/import-upsert-disabled', 'https://example.com/original' );
		$this->manager()->disable( $redirect_id );

		$file = $this->create_csv( "/import-upsert-disabled,https://example.com/replacement\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'mode'            => 'upsert',
				'skip-validation' => true,
			)
		);

		$this->assert_command_success();
		$this->assertSame( 1, $this->count_redirect_posts( '/import-upsert-disabled' ) );

		$redirect = $this->repository()->find_by_id( $redirect_id );
		$this->assertSame( 'https://example.com/replacement', $redirect->destination()->as_url()->value() );
		$this->assertFalse( $redirect->is_active(), 'Status should be preserved when the CSV omits it.' );
	}

	/**
	 * Test upsert mode sees a disabled redirect a front-end lookup has cached.
	 *
	 * A cold cache is the interesting case: disabling pre-warms the entry with
	 * the redirect's ID, so it takes an eviction for a visitor's request to be
	 * the lookup that populates it. What that lookup stores must be which post
	 * holds the source, not its publish-only verdict. Storing "no redirect"
	 * would send this upsert down the create path, to fail on a duplicate the
	 * cache had just told it did not exist.
	 *
	 * @see https://linear.app/a8c/issue/VIPPLUG-113
	 */
	public function test_import_upsert_updates_disabled_redirect_after_frontend_lookup(): void {
		$redirect_id = $this->create_redirect( '/import-upsert-cached', 'https://example.com/original' );
		$this->manager()->disable( $redirect_id );

		wp_cache_flush();

		// Prime the cache the way a visitor requesting the disabled source does.
		$this->assertNull( $this->repository()->find_by_source( SourceUrl::from_string( '/import-upsert-cached' ) ) );

		$file = $this->create_csv( "/import-upsert-cached,https://example.com/replacement\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'mode'            => 'upsert',
				'skip-validation' => true,
			)
		);

		$this->assert_command_success();
		$this->assertSame( 1, $this->count_redirect_posts( '/import-upsert-cached' ) );

		$redirect = $this->repository()->find_by_id( $redirect_id );
		$this->assertSame( 'https://example.com/replacement', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test re-running an upsert import is idempotent for disabled redirects.
	 *
	 * @see https://linear.app/a8c/issue/VIPPLUG-133
	 */
	public function test_import_upsert_is_idempotent_for_disabled_redirects(): void {
		$file = $this->create_csv( "/import-upsert-twice,https://example.com/a,disabled\n" );

		foreach ( array( 1, 2 ) as $unused ) {
			$this->invoke_command(
				$this->command,
				array( $file ),
				array(
					'mode'            => 'upsert',
					'skip-validation' => true,
				)
			);
			$this->assert_command_success();
		}

		$this->assertSame( 1, $this->count_redirect_posts( '/import-upsert-twice' ) );
	}

	/**
	 * Test a row longer than 2,000 bytes imports intact.
	 *
	 * @see https://linear.app/a8c/issue/VIPPLUG-149
	 */
	public function test_import_does_not_truncate_long_rows(): void {
		$destination = 'https://example.com/new-page?utm_campaign=' . str_repeat( 'x', 2500 );
		$file        = $this->create_csv( "/import-long,$destination\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );

		$repository = $this->repository();
		$redirect   = $repository->find_by_id(
			$repository->get_id_by_source( SourceUrl::from_string( '/import-long' ) )
		);
		$this->assertSame( $destination, $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test error for a file that does not exist.
	 */
	public function test_import_missing_file(): void {
		$this->invoke_command(
			$this->command,
			array( '/tmp/definitely-not-a-real-file.csv' ),
			array()
		);

		$this->assert_error_contains( 'File not found' );
	}
}
