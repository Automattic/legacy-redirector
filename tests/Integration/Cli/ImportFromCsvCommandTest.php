<?php
/**
 * ImportFromCsvCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixtures write to a temp file, not the WP filesystem.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixtures clean up their own temp files.

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromCsvCommand;

/**
 * Integration tests for ImportFromCsvCommand.
 *
 * Note: the WP_CLI stub used by these tests does not halt execution on
 * WP_CLI::error(), unlike the real WP-CLI runner, so the command keeps running
 * after an error is recorded. Tests assert on the recorded error message.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromCsvCommand
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class ImportFromCsvCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ImportFromCsvCommand
	 */
	private ImportFromCsvCommand $command;

	/**
	 * Temp files created by the test, for clean up.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new ImportFromCsvCommand( $this->container()->manager() );
	}

	/**
	 * Remove any temp files created by the test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	/**
	 * Write a CSV file with the given contents.
	 *
	 * @param string $contents The raw CSV contents.
	 * @return string The path to the CSV file.
	 */
	private function write_csv( string $contents ): string {
		$path               = tempnam( sys_get_temp_dir(), 'wlr' );
		$this->temp_files[] = $path;

		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Look up a redirect by its source path.
	 *
	 * @param string $source The source path.
	 * @return \Automattic\LegacyRedirector\Domain\Redirect|null The redirect, or null.
	 */
	private function find_redirect( string $source ) {
		return $this->container()->inner_repository()->find_by_source( SourceUrl::from_string( $source ) );
	}

	/**
	 * Get the post status of the redirect with the given source path.
	 *
	 * @param string $source The source path.
	 * @return string The post status, or an empty string if there is no redirect.
	 */
	private function redirect_status( string $source ): string {
		$redirect_id = $this->container()->inner_repository()->get_id_by_source( SourceUrl::from_string( $source ) );

		if ( 0 === $redirect_id ) {
			return '';
		}

		return (string) get_post( $redirect_id )->post_status;
	}

	// =========================================================================
	// Tests for import mode
	// =========================================================================

	/**
	 * Test importing new redirects from a CSV file.
	 */
	public function test_imports_redirects_from_csv(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$csv     = $this->write_csv( "/csv-one,/csv-dest-one\n/csv-two,$post_id\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
			)
		);

		$this->assert_success_contains( 'Processed 2 redirects.' );
		$this->assert_stdout_contains( 'Created: 2' );

		$redirect = $this->find_redirect( '/csv-one' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/csv-dest-one', $redirect->destination()->as_url()->value() );

		$redirect = $this->find_redirect( '/csv-two' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test that the optional status column is honoured.
	 */
	public function test_import_honours_status_column(): void {
		$csv = $this->write_csv( "/status-on,/dest-on,enabled\n/status-off,/dest-off,disabled\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
			)
		);

		$this->assert_command_success();
		$this->assertSame( 'publish', $this->redirect_status( '/status-on' ) );
		$this->assertSame( 'draft', $this->redirect_status( '/status-off' ) );
	}

	/**
	 * Test that an omitted status column defaults to enabled.
	 */
	public function test_import_defaults_to_enabled_without_status_column(): void {
		$csv = $this->write_csv( "/status-default,/dest-default\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
			)
		);

		$this->assertSame( 'publish', $this->redirect_status( '/status-default' ) );
	}

	/**
	 * Test verbose mode logs each processed row.
	 */
	public function test_verbose_logs_each_row(): void {
		$csv = $this->write_csv( "/verbose-row,/verbose-dest\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
				'verbose'         => true,
			)
		);

		$this->assert_stdout_contains( 'Processing CSV in import mode...' );
		$this->assert_stdout_contains( 'Processing row 1: /verbose-row' );
	}

	// =========================================================================
	// Tests for update mode
	// =========================================================================

	/**
	 * Test update mode changes the destination of an existing redirect.
	 */
	public function test_update_mode_updates_existing_destination(): void {
		$this->create_redirect( '/update-me', '/old-destination' );
		$csv = $this->write_csv( "/update-me,/new-destination\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'update'          => true,
				'skip-validation' => true,
			)
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );
		$this->assert_stdout_contains( 'Updated: 1' );

		$redirect = $this->find_redirect( '/update-me' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/new-destination', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test update mode creates a redirect that does not exist yet.
	 */
	public function test_update_mode_creates_missing_redirect(): void {
		$csv = $this->write_csv( "/update-creates,/created-destination\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'update'          => true,
				'skip-validation' => true,
			)
		);

		$this->assert_stdout_contains( 'Created: 1' );
		$this->assertNotNull( $this->find_redirect( '/update-creates' ) );
	}

	/**
	 * Test update mode applies the status column to an existing redirect.
	 */
	public function test_update_mode_applies_status_column(): void {
		$this->create_redirect( '/update-status', '/old-destination' );
		$csv = $this->write_csv( "/update-status,/new-destination,disabled\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'update'          => true,
				'skip-validation' => true,
			)
		);

		$this->assertSame( 'draft', $this->redirect_status( '/update-status' ) );
	}

	// =========================================================================
	// Tests for delete mode
	// =========================================================================

	/**
	 * Test delete mode removes the listed redirects.
	 */
	public function test_delete_mode_deletes_listed_redirects(): void {
		$this->create_redirect( '/delete-me', '/some-destination' );
		$this->create_redirect( '/keep-me', '/some-destination-two' );
		$csv = $this->write_csv( "/delete-me\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $csv,
				'delete' => true,
			)
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );
		$this->assert_stdout_contains( 'Deleted: 1' );

		$this->assertNull( $this->find_redirect( '/delete-me' ) );
		$this->assertNotNull( $this->find_redirect( '/keep-me' ) );
	}

	/**
	 * Test delete mode reports sources that have no redirect.
	 */
	public function test_delete_mode_reports_not_found(): void {
		$csv = $this->write_csv( "/never-existed\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $csv,
				'delete' => true,
			)
		);

		$this->assert_stdout_contains( 'Not found: 1' );
	}

	// =========================================================================
	// Tests for dry run
	// =========================================================================

	/**
	 * Test a dry run import creates nothing.
	 */
	public function test_dry_run_import_creates_nothing(): void {
		$csv = $this->write_csv( "/dry-run-import,/dry-run-dest\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'dry-run'         => true,
				'skip-validation' => true,
				'format'          => 'csv',
			)
		);

		$this->assert_warning_contains( 'Dry run mode - no changes will be made.' );
		$this->assert_stdout_contains( 'Would create: 1' );
		$this->assertNull( $this->find_redirect( '/dry-run-import' ) );
	}

	/**
	 * Test a dry run delete removes nothing.
	 */
	public function test_dry_run_delete_removes_nothing(): void {
		$this->create_redirect( '/dry-run-delete', '/some-destination' );
		$csv = $this->write_csv( "/dry-run-delete\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'     => $csv,
				'delete'  => true,
				'dry-run' => true,
				'format'  => 'csv',
			)
		);

		$this->assert_stdout_contains( 'Would delete: 1' );
		$this->assertNotNull( $this->find_redirect( '/dry-run-delete' ) );
	}

	// =========================================================================
	// Tests for invalid rows
	// =========================================================================

	/**
	 * Test rows without a destination are reported as errors.
	 */
	public function test_row_without_destination_is_reported(): void {
		$csv = $this->write_csv( "/good-row,/good-dest\n/no-destination\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
				'format'          => 'csv',
			)
		);

		$this->assert_warning_contains( 'Errors:' );
		$this->assert_stdout_contains( 'Missing destination' );
		$this->assert_stdout_contains( '/no-destination' );

		// The valid row is still imported.
		$this->assertNotNull( $this->find_redirect( '/good-row' ) );
	}

	/**
	 * Test rows with an unusable destination are reported as errors.
	 */
	public function test_row_with_invalid_destination_is_reported(): void {
		$csv = $this->write_csv( "/bad-scheme,ftp://example.com/file\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
				'format'          => 'csv',
			)
		);

		$this->assert_stdout_contains( 'Invalid destination' );
		$this->assertNull( $this->find_redirect( '/bad-scheme' ) );
	}

	/**
	 * Test blank lines are skipped without being counted.
	 */
	public function test_blank_rows_are_skipped(): void {
		$csv = $this->write_csv( "/blank-test,/blank-dest\n\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
			)
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );
	}

	// =========================================================================
	// Tests for validation
	// =========================================================================

	/**
	 * Test validation rejects a destination post ID that does not exist.
	 */
	public function test_validation_rejects_missing_destination_post(): void {
		$csv = $this->write_csv( "/validated-row,999999\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $csv,
				'format' => 'csv',
			)
		);

		$this->assert_warning_contains( 'Errors:' );
		$this->assert_stdout_contains( 'does not exist' );
		$this->assertNull( $this->find_redirect( '/validated-row' ) );
	}

	/**
	 * Test skip-validation allows a destination that would otherwise be rejected.
	 */
	public function test_skip_validation_allows_unverified_destination(): void {
		$csv = $this->write_csv( "/unvalidated-row,/no-such-page\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'             => $csv,
				'skip-validation' => true,
			)
		);

		$this->assert_success_contains( 'Processed 1 redirects.' );
		$this->assertNotNull( $this->find_redirect( '/unvalidated-row' ) );
	}

	// =========================================================================
	// Tests for error handling
	// =========================================================================

	/**
	 * Test an error is raised when the CSV file does not exist.
	 */
	public function test_errors_when_csv_missing(): void {
		$this->invoke_command(
			$this->command,
			array(),
			array( 'csv' => '/no/such/file.csv' )
		);

		$this->assert_error_contains( "Invalid 'csv' file" );
	}

	/**
	 * Test an error is raised when update and delete are combined.
	 */
	public function test_errors_when_update_and_delete_combined(): void {
		$csv = $this->write_csv( '' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $csv,
				'update' => true,
				'delete' => true,
			)
		);

		$this->assert_error_contains( 'Cannot use --update and --delete together.' );
	}

	/**
	 * Test a warning is shown when the CSV has no usable rows.
	 */
	public function test_warns_when_csv_has_no_rows(): void {
		$csv = $this->write_csv( '' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'csv' => $csv )
		);

		$this->assert_warning_contains( 'No rows processed from CSV.' );
	}
}
