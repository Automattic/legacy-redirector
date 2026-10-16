<?php
/**
 * ImportFromMetaCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromMetaCommand;

/**
 * Integration tests for ImportFromMetaCommand.
 *
 * Note: the WP_CLI stub used by these tests does not halt execution on
 * WP_CLI::error(), unlike the real WP-CLI runner. Tests therefore assert on
 * the recorded error rather than on execution stopping.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromMetaCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
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
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ImportFromMetaCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ImportFromMetaCommand
	 */
	private ImportFromMetaCommand $command;

	/**
	 * Meta key unique to the current test.
	 *
	 * The integration TestCase does not start a database transaction, so posts
	 * and post meta created by one test are still present in the next. A unique
	 * key per test keeps the meta rows seen by the command isolated.
	 *
	 * @var string
	 */
	private string $meta_key;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->meta_key = 'legacy-url-' . uniqid();
		$this->command  = new ImportFromMetaCommand(
			$this->manager(),
			$this->repository()
		);
	}

	/**
	 * Create a published post carrying a legacy URL in post meta.
	 *
	 * @param string $legacy_url The legacy URL to store in meta.
	 * @return int The post ID.
	 */
	private function create_post_with_legacy_url( string $legacy_url ): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $post_id, $this->meta_key, $legacy_url );

		return $post_id;
	}

	/**
	 * Look up a redirect by its source path.
	 *
	 * @param string $source The source path.
	 * @return \Automattic\LegacyRedirector\Domain\Redirect|null The redirect, or null.
	 */
	private function find_redirect( string $source ) {
		return $this->repository()->find_by_source( SourceUrl::from_string( $source ) );
	}

	/**
	 * Test importing redirects from post meta.
	 */
	public function test_imports_redirects_from_post_meta(): void {
		$first  = $this->create_post_with_legacy_url( '/legacy-one' );
		$second = $this->create_post_with_legacy_url( '/legacy-two' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$this->assert_stdout_contains( '---Live Run---' );
		$this->assert_stdout_contains( 'All of your redirects have been imported' );

		$redirect = $this->find_redirect( '/legacy-one' );
		$this->assertNotNull( $redirect );
		$this->assertTrue( $redirect->destination()->is_post_id() );
		$this->assertSame( $first, $redirect->destination()->as_post_id()->value() );

		$redirect = $this->find_redirect( '/legacy-two' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $second, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test that full URLs in meta are reduced to their path.
	 */
	public function test_imports_full_urls_as_paths(): void {
		$post_id = $this->create_post_with_legacy_url( 'https://example.com/archive/old-story' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$redirect = $this->find_redirect( '/archive/old-story' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test legacy URLs that differ only by query import as separate redirects.
	 */
	public function test_keeps_query_string_of_legacy_url(): void {
		$first  = $this->create_post_with_legacy_url( '/article.php?id=5' );
		$second = $this->create_post_with_legacy_url( '/article.php?id=6' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$this->assert_stdout_contains( 'All of your redirects have been imported' );
		$this->assertNull( $this->find_redirect( '/article.php' ) );

		$redirect = $this->resolver()->find_redirect( '/article.php?id=5' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $first, $redirect->destination()->as_post_id()->value() );

		$redirect = $this->resolver()->find_redirect( '/article.php?id=6' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $second, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test a legacy URL that is only a query on the root keeps the query.
	 *
	 * @dataProvider data_query_only_legacy_urls
	 *
	 * @param string $legacy_url The meta value.
	 */
	public function test_imports_query_on_root( string $legacy_url ): void {
		$post_id = $this->create_post_with_legacy_url( $legacy_url );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$redirect = $this->find_redirect( '/?p=123' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/?p=123', $redirect->source()->path() );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Meta values that are a query on the root.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_query_only_legacy_urls(): array {
		return array(
			'rooted'   => array( '/?p=123' ),
			'no path'  => array( '?p=123' ),
			'full URL' => array( 'https://old.example.com?p=123' ),
		);
	}

	/**
	 * Test a full URL keeps its query as well as its path.
	 */
	public function test_imports_full_url_with_query(): void {
		$post_id = $this->create_post_with_legacy_url( 'https://old.example.com/a?b=1' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$redirect = $this->find_redirect( '/a?b=1' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/a?b=1', $redirect->source()->path() );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test --skip-dupes matches an existing redirect on path and query.
	 */
	public function test_skip_dupes_matches_on_query(): void {
		$this->create_redirect( '/article.php?id=7', '/original-destination' );
		$this->create_post_with_legacy_url( '/article.php?id=7' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key'   => $this->meta_key,
				'format'     => 'csv',
				'skip-dupes' => true,
				'verbose'    => true,
			)
		);

		$this->assert_stdout_contains( 'Skipped - Redirect for this from URL already exists' );
		$this->assertSame( '/original-destination', $this->find_redirect( '/article.php?id=7' )->destination()->as_url()->value() );
	}

	/**
	 * Test verbose mode reports each successful import.
	 */
	public function test_verbose_reports_successful_imports(): void {
		$this->create_post_with_legacy_url( '/verbose-import' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
				'verbose'  => true,
			)
		);

		$this->assert_stdout_contains( 'Successfully imported' );
		$this->assert_stdout_contains( '/verbose-import' );
	}

	/**
	 * Test the start offset skips earlier meta rows.
	 */
	public function test_start_offset_skips_earlier_rows(): void {
		$this->create_post_with_legacy_url( '/offset-first' );
		$this->create_post_with_legacy_url( '/offset-second' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
				'start'    => 1,
			)
		);

		$this->assertNull( $this->find_redirect( '/offset-first' ) );
		$this->assertNotNull( $this->find_redirect( '/offset-second' ) );
	}

	/**
	 * Test dry run reports the run type and creates nothing.
	 */
	public function test_dry_run_creates_no_redirects(): void {
		$this->create_post_with_legacy_url( '/dry-run-source' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
				'dry-run'  => true,
			)
		);

		$this->assert_stdout_contains( '---Dry Run---' );
		$this->assertNull( $this->find_redirect( '/dry-run-source' ) );
	}

	/**
	 * Test a meta value with no path is reported and skipped.
	 */
	public function test_skips_meta_value_without_path(): void {
		$this->create_post_with_legacy_url( 'https://example.com' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$this->assert_stdout_contains( 'Invalid source URL - no path found' );
		$this->assert_stdout_not_contains( 'All of your redirects have been imported' );
	}

	/**
	 * Test --skip-dupes leaves an existing redirect untouched.
	 */
	public function test_skip_dupes_preserves_existing_redirect(): void {
		$this->create_redirect( '/already-mapped', '/original-destination' );
		$this->create_post_with_legacy_url( '/already-mapped' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key'   => $this->meta_key,
				'format'     => 'csv',
				'skip-dupes' => true,
				'verbose'    => true,
			)
		);

		$this->assert_stdout_contains( 'Skipped - Redirect for this from URL already exists' );

		$redirect = $this->find_redirect( '/already-mapped' );
		$this->assertNotNull( $redirect );
		$this->assertTrue( $redirect->destination()->is_url() );
		$this->assertSame( '/original-destination', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test a destination post that is not published is reported.
	 */
	public function test_reports_unpublished_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_post_meta( $post_id, $this->meta_key, '/draft-destination' );

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => $this->meta_key,
				'format'   => 'csv',
			)
		);

		$this->assertNull( $this->find_redirect( '/draft-destination' ) );
		$this->assertStringNotContainsString( 'All of your redirects have been imported', $this->get_output() );
		$this->assertStringContainsString( '/draft-destination', $this->get_output() );
	}

	/**
	 * Test an error is raised when the meta key matches no rows.
	 */
	public function test_errors_when_no_meta_rows_found(): void {
		$this->invoke_command(
			$this->command,
			array(),
			array(
				'meta-key' => 'no-such-meta-key',
				'format'   => 'csv',
			)
		);

		$this->assert_error_contains( 'No redirects found for meta_key: no-such-meta-key' );
	}

	/**
	 * Test an error is raised when no meta key is supplied at all.
	 */
	public function test_errors_when_meta_key_missing(): void {
		$this->invoke_command(
			$this->command,
			array(),
			array( 'format' => 'csv' )
		);

		$this->assert_error_contains( 'No redirects found for meta_key' );
	}
}
