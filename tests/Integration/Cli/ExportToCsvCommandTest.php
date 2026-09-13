<?php
/**
 * ExportToCsvCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertions read a temp file, not a remote resource.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixtures write to a temp file.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixtures clean up their own temp files.

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ExportToCsvCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromCsvCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Integration tests for ExportToCsvCommand.
 *
 * Note: the WP_CLI stub used by these tests does not halt execution on
 * WP_CLI::error(), unlike the real WP-CLI runner, so the command keeps running
 * after an error is recorded. Tests assert on the recorded error message.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ExportToCsvCommand
 */
final class ExportToCsvCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ExportToCsvCommand
	 */
	private ExportToCsvCommand $command;

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

		$this->command = new ExportToCsvCommand();
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
	 * Get a temp file path that does not yet exist.
	 *
	 * @return string The file path.
	 */
	private function temp_csv_path(): string {
		$path               = sys_get_temp_dir() . '/wlr-export-' . uniqid() . '.csv';
		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * Get the exported file contents.
	 *
	 * @param string $path The file path.
	 * @return string The contents.
	 */
	private function csv_contents( string $path ): string {
		return (string) file_get_contents( $path );
	}

	/**
	 * Get the non-empty lines of the exported file.
	 *
	 * @param string $path The file path.
	 * @return string[] The lines.
	 */
	private function csv_lines( string $path ): array {
		$contents = trim( $this->csv_contents( $path ) );

		return '' === $contents ? array() : explode( "\n", $contents );
	}

	/**
	 * Disable a redirect by source path.
	 *
	 * @param string $source The source path.
	 * @return void
	 */
	private function disable_redirect( string $source ): void {
		$redirect_id = $this->container()->inner_repository()->get_id_by_source( SourceUrl::from_string( $source ) );
		$this->container()->manager()->disable( $redirect_id );
	}

	/**
	 * Delete every redirect, regardless of status.
	 *
	 * @return void
	 */
	private function delete_every_redirect(): void {
		$redirect_ids = get_posts(
			array(
				'post_type'      => PostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $redirect_ids as $redirect_id ) {
			wp_delete_post( $redirect_id, true );
		}
	}

	/**
	 * Return a canned HTTP response for every outbound request.
	 *
	 * @param int $status_code The status code to return.
	 * @return void
	 */
	private function block_http_requests( int $status_code ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $status_code ) {
				return array(
					'headers'  => array(),
					'body'     => '',
					'cookies'  => array(),
					'response' => array(
						'code'    => $status_code,
						'message' => 'Canned',
					),
				);
			}
		);
	}

	// =========================================================================
	// Tests for basic export
	// =========================================================================

	/**
	 * Test the export writes the source, destination and status columns.
	 */
	public function test_export_writes_rows_with_status_column(): void {
		$this->create_redirect( '/export-enabled', '/enabled-destination' );
		$this->create_redirect( '/export-disabled', '/disabled-destination' );
		$this->disable_redirect( '/export-disabled' );

		$path = $this->temp_csv_path();

		$this->invoke_command( $this->command, array(), array( 'csv' => $path ) );

		$contents = $this->csv_contents( $path );
		$this->assertStringContainsString( "/export-enabled,/enabled-destination,enabled\n", $contents );
		$this->assertStringContainsString( "/export-disabled,/disabled-destination,disabled\n", $contents );
		$this->assertCount( 2, $this->csv_lines( $path ) );
	}

	/**
	 * Test a post ID destination is exported as the post ID.
	 */
	public function test_export_writes_post_id_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/export-post-id', $post_id );

		$path = $this->temp_csv_path();

		$this->invoke_command( $this->command, array(), array( 'csv' => $path ) );

		$this->assertSame( array( "/export-post-id,$post_id,enabled" ), $this->csv_lines( $path ) );
	}

	/**
	 * Test exporting when there are no redirects writes an empty file.
	 */
	public function test_export_with_no_redirects_writes_empty_file(): void {
		$path = $this->temp_csv_path();

		$this->invoke_command( $this->command, array(), array( 'csv' => $path ) );

		$this->assertFileExists( $path );
		$this->assertSame( '', $this->csv_contents( $path ) );
	}

	// =========================================================================
	// Tests for the status filter
	// =========================================================================

	/**
	 * Test the enabled status filter excludes disabled redirects.
	 */
	public function test_status_filter_exports_only_enabled(): void {
		$this->create_redirect( '/filter-enabled', '/destination-one' );
		$this->create_redirect( '/filter-disabled', '/destination-two' );
		$this->disable_redirect( '/filter-disabled' );

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $path,
				'status' => 'enabled',
			)
		);

		$this->assertSame( array( '/filter-enabled,/destination-one,enabled' ), $this->csv_lines( $path ) );
	}

	/**
	 * Test the disabled status filter excludes enabled redirects.
	 */
	public function test_status_filter_exports_only_disabled(): void {
		$this->create_redirect( '/only-enabled', '/destination-one' );
		$this->create_redirect( '/only-disabled', '/destination-two' );
		$this->disable_redirect( '/only-disabled' );

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'    => $path,
				'status' => 'disabled',
			)
		);

		$this->assertSame( array( '/only-disabled,/destination-two,disabled' ), $this->csv_lines( $path ) );
	}

	// =========================================================================
	// Tests for file handling
	// =========================================================================

	/**
	 * Test an error is raised when no CSV path is given.
	 *
	 * The stubbed WP_CLI::error() does not halt execution, so after the error is
	 * recorded the command continues into file_exists( false ) and throws. The
	 * real WP-CLI runner stops at the error.
	 */
	public function test_errors_without_csv_option(): void {
		try {
			$this->invoke_command( $this->command, array(), array() );
		} catch ( \TypeError $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assert_error_contains( 'Invalid CSV file!' );
	}

	/**
	 * Test an error is raised when the file exists and overwrite is not set.
	 */
	public function test_errors_when_file_exists_without_overwrite(): void {
		$path = $this->temp_csv_path();
		file_put_contents( $path, "existing\n" );

		$this->invoke_command( $this->command, array(), array( 'csv' => $path ) );

		$this->assert_error_contains( 'CSV file already exists!' );
	}

	/**
	 * Test overwrite replaces the existing file and warns about it.
	 */
	public function test_overwrite_replaces_existing_file(): void {
		$this->create_redirect( '/overwrite-source', '/overwrite-destination' );

		$path = $this->temp_csv_path();
		file_put_contents( $path, "stale,content,enabled\n" );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'       => $path,
				'overwrite' => true,
			)
		);

		$this->assert_warning_contains( 'Overwriting file ' . $path );
		$this->assertSame( array( '/overwrite-source,/overwrite-destination,enabled' ), $this->csv_lines( $path ) );
	}

	// =========================================================================
	// Tests for broken-only mode
	// =========================================================================

	/**
	 * Test broken-only mode exports only redirects with broken destinations.
	 */
	public function test_broken_only_exports_only_broken_redirects(): void {
		$good_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$bad_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->create_redirect( '/broken-good', $good_post );
		$this->create_redirect( '/broken-bad', $bad_post );
		wp_delete_post( $bad_post, true );

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'         => $path,
				'broken-only' => true,
			)
		);

		$this->assert_stdout_contains( 'Found 1 broken redirects.' );
		$this->assertSame( array( "/broken-bad,$bad_post,enabled" ), $this->csv_lines( $path ) );
	}

	/**
	 * Test broken-only mode flags URL destinations that return 404.
	 */
	public function test_broken_only_with_check_urls_flags_404(): void {
		$this->block_http_requests( 404 );
		$this->create_redirect( '/broken-url', 'https://example.com/gone' );

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'         => $path,
				'broken-only' => true,
				'check-urls'  => true,
			)
		);

		$this->assert_warning_contains( 'URL checking enabled - this may be slow.' );
		$this->assert_stdout_contains( 'Found 1 broken redirects.' );
		$this->assertSame( array( '/broken-url,https://example.com/gone,enabled' ), $this->csv_lines( $path ) );
	}

	/**
	 * Test broken-only mode leaves URL destinations that respond normally.
	 */
	public function test_broken_only_with_check_urls_ignores_healthy_url(): void {
		$this->block_http_requests( 200 );
		$this->create_redirect( '/healthy-url', 'https://example.com/still-here' );

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'         => $path,
				'broken-only' => true,
				'check-urls'  => true,
			)
		);

		$this->assert_stdout_contains( 'Found 0 broken redirects.' );
		$this->assertSame( array(), $this->csv_lines( $path ) );
	}

	/**
	 * Test broken-only mode flags redirects with an empty destination.
	 */
	public function test_broken_only_flags_unpublished_post_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/unpublished-destination', $post_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$path = $this->temp_csv_path();

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'csv'         => $path,
				'broken-only' => true,
			)
		);

		$this->assertSame( array( "/unpublished-destination,$post_id,enabled" ), $this->csv_lines( $path ) );
	}

	// =========================================================================
	// Round trip
	// =========================================================================

	/**
	 * Test exporting then importing restores the same redirects.
	 */
	public function test_export_then_import_round_trip(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/round-trip-url', '/round-trip-destination' );
		$this->create_redirect( '/round-trip-post', $post_id );
		$this->create_redirect( '/round-trip-off', '/round-trip-off-destination' );
		$this->disable_redirect( '/round-trip-off' );

		$path = $this->temp_csv_path();
		$this->invoke_command( $this->command, array(), array( 'csv' => $path ) );
		$exported = $this->csv_lines( $path );

		$this->delete_every_redirect();

		$import = new ImportFromCsvCommand( $this->container()->manager() );
		$this->invoke_command(
			$import,
			array(),
			array(
				'csv'             => $path,
				'skip-validation' => true,
			)
		);
		$this->assert_command_success();

		$repository = $this->container()->inner_repository();

		$redirect = $repository->find_by_source( SourceUrl::from_string( '/round-trip-url' ) );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/round-trip-destination', $redirect->destination()->as_url()->value() );

		$redirect = $repository->find_by_source( SourceUrl::from_string( '/round-trip-post' ) );
		$this->assertNotNull( $redirect );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );

		$disabled_id = $repository->get_id_by_source( SourceUrl::from_string( '/round-trip-off' ) );
		$this->assertSame( 'draft', get_post( $disabled_id )->post_status );

		// Re-exporting produces the same rows.
		$second_path = $this->temp_csv_path();
		$this->invoke_command( $this->command, array(), array( 'csv' => $second_path ) );

		sort( $exported );
		$reexported = $this->csv_lines( $second_path );
		sort( $reexported );
		$this->assertSame( $exported, $reexported );
	}
}
