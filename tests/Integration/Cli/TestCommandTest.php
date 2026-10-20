<?php
/**
 * TestCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\TestCommand;

/**
 * Integration tests for TestCommand.
 *
 * Every HTTP request is answered by a canned response: the source probe is
 * told apart by its host, the site's own.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\TestCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::status_label
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 * @uses \Automattic\LegacyRedirector\Domain\AuditDetail
 * @uses \Automattic\LegacyRedirector\Application\FindingText
 */
final class TestCommandTest extends CliTestCase {

	/**
	 * The destination the fixtures redirect to.
	 *
	 * @var string
	 */
	private const string DESTINATION = 'https://example.com/target';

	/**
	 * The command under test.
	 *
	 * @var TestCommand
	 */
	private TestCommand $command;

	/**
	 * Requests made to a source, by URL.
	 *
	 * @var string[]
	 */
	private array $source_requests = array();

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new TestCommand(
			new RedirectBatch( new RedirectFetcher( $this->repository() ) ),
			$this->auditor()
		);
	}

	/**
	 * Answer the source probe and destination requests with canned responses.
	 *
	 * @param array|\WP_Error $source      What requesting the source gets.
	 * @param array|\WP_Error $destination What requesting the destination gets.
	 * @return void
	 */
	private function respond( $source, $destination ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $source, $destination ) {
				if ( str_starts_with( $url, home_url( '/' ) ) ) {
					$this->source_requests[] = $url;
					return $source;
				}

				return $destination;
			},
			10,
			3
		);
	}

	/**
	 * A response redirecting to the fixtures' destination.
	 *
	 * @return array The response.
	 */
	private static function redirects_to_destination(): array {
		return array(
			'response' => array( 'code' => 301 ),
			'headers'  => array( 'location' => self::DESTINATION ),
		);
	}

	/**
	 * Run the command and return the rows it formatted.
	 *
	 * @param string[]             $args       The redirects.
	 * @param array<string, mixed> $assoc_args Extra options.
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows( array $args, array $assoc_args = array() ): array {
		$this->invoke_command( $this->command, $args, $assoc_args + array( 'format' => 'json' ) );

		return $GLOBALS['wp_cli_format_items_calls'][0][1] ?? array();
	}

	/**
	 * Test a working redirect says what was checked, with nothing to fix.
	 */
	public function test_a_working_redirect_reports_both_checks_ok(): void {
		$this->create_redirect( '/test-ok', self::DESTINATION );
		$this->respond( self::redirects_to_destination(), array( 'response' => array( 'code' => 200 ) ) );

		$this->invoke_command( $this->command, array( '/test-ok' ), array() );

		$this->assert_success_contains( 'Tested 1 redirect: no issues found.' );
		$this->assert_stdout_contains( 'Destination responds' );
		$this->assert_stdout_contains( 'Source redirects to the destination' );
	}

	/**
	 * Test a destination returning 404 is a problem, with what to do about it.
	 */
	public function test_a_missing_destination_is_a_problem(): void {
		$this->create_redirect( '/test-gone', self::DESTINATION );
		$this->respond( self::redirects_to_destination(), array( 'response' => array( 'code' => 404 ) ) );

		$rows = $this->rows( array( '/test-gone' ) );

		$this->assertSame( 'problem', $rows[0]['severity'] );
		$this->assertSame( 'Destination returns 404', $rows[0]['issue'] );
		$this->assertSame( 'Point this redirect at a page that exists, or delete it', $rows[0]['remedy'] );
	}

	/**
	 * Answer destination requests by URL, recording each one.
	 *
	 * @param array<string, array> $responses Responses by URL; any other URL gets a 200.
	 * @return \ArrayObject<int, string> The URLs requested, filled in as the command runs.
	 */
	private function respond_by_url( array $responses ): \ArrayObject {
		$requested = new \ArrayObject();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $responses, $requested ) {
				$requested[] = $url;

				return $responses[ $url ] ?? array( 'response' => array( 'code' => 200 ) );
			},
			10,
			3
		);

		return $requested;
	}

	/**
	 * Test a destination redirecting within allowed hosts is followed to where it ends.
	 */
	public function test_a_destination_hop_to_an_allowed_host_is_followed(): void {
		$this->create_redirect( '/test-hop-allowed', self::DESTINATION );
		$requested = $this->respond_by_url(
			array(
				self::DESTINATION           => array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'https://example.com/moved' ),
				),
				'https://example.com/moved' => array( 'response' => array( 'code' => 404 ) ),
			)
		);

		$issues = array_column( $this->rows( array( '/test-hop-allowed' ), array( 'only' => 'destination' ) ), 'issue' );

		$this->assertSame( array( 'Destination returns 404' ), $issues );
		$this->assertSame( array( self::DESTINATION, 'https://example.com/moved' ), $requested->getArrayCopy() );
	}

	/**
	 * Test a destination redirecting outside allowed hosts is not followed there.
	 */
	public function test_a_destination_hop_to_another_host_is_not_requested(): void {
		$this->create_redirect( '/test-hop-elsewhere', self::DESTINATION );
		$requested = $this->respond_by_url(
			array(
				self::DESTINATION => array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'http://169.254.169.254/latest/' ),
				),
			)
		);

		$issues = array_column( $this->rows( array( '/test-hop-elsewhere' ), array( 'only' => 'destination' ) ), 'issue' );

		$this->assertSame( array( 'Destination responds' ), $issues );
		$this->assertSame( array( self::DESTINATION ), $requested->getArrayCopy() );
	}

	/**
	 * Test a relative Location is resolved against the URL that sent it, as the HTTP API did.
	 *
	 * @dataProvider relative_location_provider
	 *
	 * @param string $source      The redirect's source.
	 * @param string $destination The destination, which redirects.
	 * @param string $location    The relative Location it redirects with.
	 * @param string $resolved    The URL the hop should request.
	 */
	public function test_a_relative_destination_hop_is_resolved( string $source, string $destination, string $location, string $resolved ): void {
		$this->create_redirect( $source, $destination );
		$requested = $this->respond_by_url(
			array(
				$destination => array(
					'response' => array( 'code' => 301 ),
					'headers'  => array( 'location' => $location ),
				),
				$resolved    => array( 'response' => array( 'code' => 404 ) ),
			)
		);

		$issues = array_column( $this->rows( array( $source ), array( 'only' => 'destination' ) ), 'issue' );

		$this->assertSame( array( 'Destination returns 404' ), $issues );
		$this->assertSame( array( $destination, $resolved ), $requested->getArrayCopy() );
	}

	/**
	 * Relative Location forms and what each resolves to.
	 *
	 * @return array<string, string[]>
	 */
	public function relative_location_provider(): array {
		return array(
			'root-relative'   => array( '/test-hop-root', 'https://example.com/a/b', '/moved', 'https://example.com/moved' ),
			'scheme-relative' => array( '/test-hop-scheme', 'https://example.com/a/b', '//example.com/moved', 'https://example.com/moved' ),
			'path-relative'   => array( '/test-hop-path', 'https://example.com/a/b', 'page2', 'https://example.com/a/page2' ),
			'parent-relative' => array( '/test-hop-parent', 'https://example.com/a/b/c', '../x', 'https://example.com/a/x' ),
		);
	}

	/**
	 * Test a destination request that times out is inconclusive, not broken.
	 */
	public function test_a_timed_out_destination_is_inconclusive(): void {
		$this->create_redirect( '/test-slow', self::DESTINATION );
		$this->respond( self::redirects_to_destination(), new \WP_Error( 'http_request_failed', 'Operation timed out' ) );

		$rows = $this->rows( array( '/test-slow' ) );

		$this->assertSame( 'inconclusive', $rows[0]['severity'] );
		$this->assertSame( 'Request failed', $rows[0]['issue'] );
	}

	/**
	 * Test a source that 404s without redirecting is reported, with what to do.
	 */
	public function test_a_source_that_does_not_fire_is_a_problem(): void {
		$this->create_redirect( '/test-not-firing', self::DESTINATION );
		$this->respond( array( 'response' => array( 'code' => 404 ) ), array( 'response' => array( 'code' => 200 ) ) );

		$rows   = $this->rows( array( '/test-not-firing' ) );
		$issues = array_column( $rows, 'issue' );

		$this->assertContains( 'Destination responds', $issues );
		$this->assertContains( 'Redirect did not fire', $issues );

		$source = $rows[ array_search( 'Redirect did not fire', $issues, true ) ];
		$this->assertSame( 'problem', $source['severity'] );
		$this->assertStringContainsString( 'Check the source matches the URL visitors request', $source['remedy'] );
	}

	/**
	 * Test --only narrows the test to one check.
	 */
	public function test_only_runs_one_check(): void {
		$this->create_redirect( '/test-only', self::DESTINATION );
		$this->respond( self::redirects_to_destination(), array( 'response' => array( 'code' => 200 ) ) );

		$destination_only = array_column( $this->rows( array( '/test-only' ), array( 'only' => 'destination' ) ), 'issue' );
		$this->assertSame( array( 'Destination responds' ), $destination_only );
		$this->assertSame( array(), $this->source_requests );

		$source_only = array_column( $this->rows( array( '/test-only' ), array( 'only' => 'source' ) ), 'issue' );
		$this->assertSame( array( 'Source redirects to the destination' ), $source_only );
	}

	/**
	 * Test a disabled redirect's source is not requested: it is not meant to fire.
	 */
	public function test_a_disabled_redirects_source_is_not_requested(): void {
		$redirect_id = $this->create_redirect( '/test-disabled', self::DESTINATION );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);
		$this->respond( array( 'response' => array( 'code' => 404 ) ), array( 'response' => array( 'code' => 200 ) ) );

		$issues = array_column( $this->rows( array( '/test-disabled' ) ), 'issue' );

		$this->assertContains( 'Source not requested: the redirect is disabled', $issues );
		$this->assertSame( array(), $this->source_requests );
	}

	/**
	 * Test nothing is recorded, however bad the result: a timeout is not a fact about the redirect.
	 */
	public function test_nothing_is_recorded(): void {
		$redirect_id = $this->create_redirect( '/test-unrecorded', self::DESTINATION );
		$this->respond( new \WP_Error( 'http_request_failed', 'Operation timed out' ), array( 'response' => array( 'code' => 404 ) ) );

		$this->invoke_command( $this->command, array( '/test-unrecorded' ), array() );

		$this->assert_warning_contains( 'Tested 1 redirect: 2 results need attention.' );
		$this->assertSame( '', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
		$this->assertFalse( get_option( AuditFlags::COUNTS_OPTION ) );
	}

	/**
	 * Test the count format prints how many results need attention.
	 */
	public function test_count_format(): void {
		$this->create_redirect( '/test-count-ok', self::DESTINATION );
		$this->respond( self::redirects_to_destination(), array( 'response' => array( 'code' => 404 ) ) );

		$this->invoke_command( $this->command, array( '/test-count-ok' ), array( 'format' => 'count' ) );

		$this->assertSame( '1', trim( $this->get_stdout() ) );
	}

	/**
	 * Test an unknown redirect is an error.
	 */
	public function test_unknown_redirect_is_an_error(): void {
		$this->invoke_command( $this->command, array( '/test-nonexistent' ), array() );

		$this->assert_command_error();
		$this->assert_error_contains( 'Redirect not found: /test-nonexistent' );
	}

	/**
	 * Test a redirect named but not found fails the run, after the others are tested.
	 */
	public function test_partly_unknown_redirects_are_an_error(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/test-partly-found', $post_id );

		$this->invoke_command( $this->command, array( '/test-partly-found', '/test-partly-missing' ), array( 'only' => 'destination' ) );

		$this->assert_warning_contains( 'Redirect not found: /test-partly-missing' );
		$this->assert_stdout_contains( 'Destination is valid' );
		$this->assert_error_contains( 'Only tested 1 of 2 redirects.' );
	}

	/**
	 * Test a corrupt row is a problem whichever check is asked for.
	 *
	 * Neither check can judge a row whose stored data cannot be read, so
	 * --only=source must not pass it off as fine.
	 */
	public function test_a_corrupt_row_is_a_problem_even_for_the_source_only(): void {
		// No source at all: the row can only be named by its ID.
		$corrupt_id = $this->insert_redirect_post( array( 'post_title' => '' ) );
		$this->respond( self::redirects_to_destination(), array( 'response' => array( 'code' => 200 ) ) );

		$rows = $this->rows( array( (string) $corrupt_id ), array( 'only' => 'source' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'problem', $rows[0]['severity'] );
		$this->assertSame( 'Corrupt stored data', $rows[0]['issue'] );
		$this->assertSame( array(), $this->source_requests );
	}
}
