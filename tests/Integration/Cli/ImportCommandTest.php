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
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException::duplicate_source
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 * @uses \Automattic\LegacyRedirector\Domain\AuditDetail
 * @uses \Automattic\LegacyRedirector\Application\FindingText
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

		$this->assert_success_contains( 'Processed 1 redirect.' );
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
	 * Test create mode skips a row that matches its existing redirect, so
	 * re-running an unchanged CSV is a clean no-op.
	 *
	 * @dataProvider data_validation_flags
	 *
	 * @param array<string, bool> $flags Extra command flags.
	 */
	public function test_import_create_mode_skips_unchanged_duplicate( array $flags ): void {
		$file = $this->create_csv( "/import-same,https://example.com/original\n/import-same-disabled,https://example.com/a,disabled\n" );

		$this->invoke_command( $this->command, array( $file ), $flags );
		$this->assert_command_success();

		$this->invoke_command( $this->command, array( $file ), $flags );

		$this->assert_command_success();
		$this->assert_success_contains( 'Processed 0 redirects, skipped 2 unchanged.' );
		$this->assertSame( 1, $this->count_redirect_posts( '/import-same' ) );
	}

	/**
	 * Flags that do and do not validate.
	 *
	 * @return array<string, array{array<string, bool>}>
	 */
	public static function data_validation_flags(): array {
		return array(
			'validating'      => array( array() ),
			'skip-validation' => array( array( 'skip-validation' => true ) ),
		);
	}

	/**
	 * Test an unchanged internal destination is matched in its normalized form.
	 */
	public function test_import_create_mode_skips_duplicate_with_equivalent_destination(): void {
		$this->create_redirect( '/import-same-internal', '/import-target' );
		$file = $this->create_csv( '/import-same-internal,' . home_url( '/import-target' ) . "\n" );

		$this->invoke_command( $this->command, array( $file ), array( 'skip-validation' => true ) );

		$this->assert_command_success();
		$this->assert_stdout_contains( 'Skipped: 1' );
	}

	/**
	 * Test create mode fails a row whose source has a redirect elsewhere,
	 * pointing the user at upsert, and leaves the existing redirect alone.
	 *
	 * @dataProvider data_changed_duplicates
	 *
	 * @param string $row The CSV row.
	 */
	public function test_import_create_mode_rejects_changed_duplicate( string $row ): void {
		$this->create_redirect( '/import-dupe', 'https://example.com/original' );
		$file = $this->create_csv( $row . "\n" );

		$this->invoke_command( $this->command, array( $file ), array() );

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Error: 1' );
		$this->assert_stdout_contains( '--mode=upsert' );

		$redirect = $this->repository()->find_by_id(
			$this->repository()->get_id_by_source( SourceUrl::from_string( '/import-dupe' ) )
		);
		$this->assertSame( 'https://example.com/original', $redirect->destination()->as_url()->value() );
		$this->assertTrue( $redirect->is_active() );
	}

	/**
	 * Rows that differ from the existing redirect.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_changed_duplicates(): array {
		return array(
			'destination' => array( '/import-dupe,https://example.com/replacement' ),
			'status'      => array( '/import-dupe,https://example.com/original,disabled' ),
		);
	}

	/**
	 * Test a live run with a failed row stores the good rows but fails,
	 * naming the count, instead of reporting success.
	 */
	public function test_import_partial_failure_is_an_error(): void {
		$file = $this->create_csv( "/import-good,https://example.com/a\n/import-bad\n" );

		$this->invoke_command( $this->command, array( $file ), array( 'skip-validation' => true ) );

		$this->assertTrue( $this->redirect_exists( '/import-good' ) );
		$this->assertFalse( $this->output->had_success() );
		$this->assert_error_contains( 'Processed 1 of 2 rows: 1 failed.' );
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

		$this->assert_success_contains( 'Processed 1 redirect.' );

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
	 * Test a dry run reports the rows the live run would refuse, and changes nothing.
	 *
	 * @dataProvider data_dry_run_refusals
	 *
	 * @param string $row      The CSV row.
	 * @param string $expected Text the error row carries.
	 */
	public function test_import_dry_run_reports_refused_rows( string $row, string $expected ): void {
		$this->create_redirect( '/import-dry-dupe', 'https://example.com/original' );
		$file = $this->create_csv( $row . "\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'dry-run' => true,
			)
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( "\terror\t" );
		$this->assert_stdout_contains( $expected );
		$this->assert_stdout_not_contains( 'would create' );
		$this->assertSame( 1, $this->count_redirect_posts( '/import-dry-dupe' ) );
		$this->assertFalse( $this->redirect_exists( '/import-dry-external' ) );
	}

	/**
	 * Rows a live run would refuse.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_dry_run_refusals(): array {
		return array(
			'changed duplicate'     => array( '/import-dry-dupe,https://example.com/replacement', '--mode=upsert' ),
			'disallowed host'       => array( '/import-dry-external,https://not-allowed.invalid/x', 'destination host is not allowed' ),
			'source is destination' => array( '/import-dry-external,/import-dry-external', 'error' ),
		);
	}

	/**
	 * Test a dry run previews the skip a live run gives an unchanged row.
	 */
	public function test_import_dry_run_previews_unchanged_row_as_skipped(): void {
		$this->create_redirect( '/import-dry-same', 'https://example.com/original' );
		$file = $this->create_csv( "/import-dry-same,https://example.com/original\n" );

		$this->invoke_command( $this->command, array( $file ), array( 'dry-run' => true ) );

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( 'would skip' );
	}

	/**
	 * Test an upsert dry run judges an existing source as the update it would be.
	 */
	public function test_import_upsert_dry_run_accepts_existing_source(): void {
		$this->create_redirect( '/import-dry-upsert', 'https://example.com/original' );
		$file = $this->create_csv( "/import-dry-upsert,https://example.com/replacement\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'dry-run' => true,
				'mode'    => 'upsert',
			)
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( 'would update/create' );
	}

	/**
	 * Test --skip-validation still previews rows the validator would refuse.
	 */
	public function test_import_dry_run_skip_validation_does_not_validate(): void {
		$file = $this->create_csv( "/import-dry-unchecked,https://not-allowed.invalid/x\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'dry-run'         => true,
				'skip-validation' => true,
			)
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( 'would create' );
	}

	/**
	 * Test an unrecognized status is an error row, live and dry, and creates nothing.
	 *
	 * @dataProvider data_dry_run_flag
	 *
	 * @param array<string, bool> $flags Extra command flags.
	 */
	public function test_import_rejects_unknown_status( array $flags ): void {
		$file = $this->create_csv( "/import-bad-status,https://example.com/a,inactive\n" );

		$this->invoke_command( $this->command, array( $file ), $flags + array( 'skip-validation' => true ) );

		$this->assert_command_error();
		$this->assert_stdout_contains( "Unknown status 'inactive'; use enabled or disabled" );
		$this->assertSame( 0, $this->count_redirect_posts( '/import-bad-status' ) );
	}

	/**
	 * Live and dry runs.
	 *
	 * @return array<string, array{array<string, bool>}>
	 */
	public static function data_dry_run_flag(): array {
		return array(
			'live' => array( array() ),
			'dry'  => array( array( 'dry-run' => true ) ),
		);
	}

	/**
	 * Test the recognized status spellings still apply.
	 *
	 * @dataProvider data_known_statuses
	 *
	 * @param string $status   The status column.
	 * @param bool   $expected Whether the redirect is enabled.
	 */
	public function test_import_accepts_known_status( string $status, bool $expected ): void {
		$file = $this->create_csv( "/import-known-status,https://example.com/a,{$status}\n" );

		$this->invoke_command( $this->command, array( $file ), array( 'skip-validation' => true ) );

		$this->assert_command_success();
		$redirect = $this->repository()->find_by_id(
			$this->repository()->get_id_by_source( SourceUrl::from_string( '/import-known-status' ) )
		);
		$this->assertSame( $expected, $redirect->is_active() );
	}

	/**
	 * Recognized status spellings.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function data_known_statuses(): array {
		return array(
			'enabled'            => array( 'enabled', true ),
			'padded capitalized' => array( 'Disabled ', false ),
			'draft'              => array( 'draft', false ),
			'empty'              => array( '', true ),
		);
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

		$this->assert_success_contains( 'Processed 1 redirect.' );

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

	/**
	 * Test a structured format prints the rows alone: no summary, no closing success.
	 */
	public function test_import_json_prints_only_the_rows(): void {
		$file = $this->create_csv( "/import-json,https://example.com/a\n" );

		$this->invoke_command(
			$this->command,
			array( $file ),
			array(
				'skip-validation' => true,
				'format'          => 'json',
			)
		);

		$this->assertTrue( $this->redirect_exists( '/import-json' ) );
		$this->assertSame( '', $this->get_stdout() );
		$this->assertFalse( $this->output->had_error() );
		$this->assertCount( 1, $GLOBALS['wp_cli_format_items_calls'] );
		$this->assertSame( array(), $GLOBALS['wp_cli_format_items_calls'][0][1], 'With no errors to show, JSON is an empty list.' );
	}

	/**
	 * Test a file whose one row fails says so in the singular.
	 */
	public function test_import_single_failed_row(): void {
		$file = $this->create_csv( "/import-lonely\n" );

		$this->invoke_command( $this->command, array( $file ), array() );

		$this->assert_error_contains( 'The only row failed.' );
	}

	/**
	 * Test every hundred rows clear the object cache, and a dry run does not pause.
	 */
	public function test_import_dry_run_clears_cache_without_pausing(): void {
		$file = $this->create_csv( implode( "\n", array_map( fn( $i ) => "/import-pause-$i", range( 1, 100 ) ) ) . "\n" );
		wp_cache_set( 'sentinel', 'kept', 'legacy-redirector-test' );

		$started = microtime( true );
		$this->invoke_command( $this->command, array( $file ), array( 'dry-run' => true ) );

		$this->assertFalse( wp_cache_get( 'sentinel', 'legacy-redirector-test' ), 'The hundredth row clears the object cache.' );
		$this->assertLessThan( 1.0, microtime( true ) - $started, 'A dry run writes nothing, so never pauses.' );
	}
}
