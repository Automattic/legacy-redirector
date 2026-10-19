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
 * told apart by asking not to follow redirects.
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
				// The probe does not follow redirects; the destination check does.
				if ( 0 === ( $args['redirection'] ?? 5 ) ) {
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
		$GLOBALS['wp_cli_format_items_calls'] = array();
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

		$this->assert_success_contains( 'Tested 1 redirect(s): no issues found.' );
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

		$this->assert_warning_contains( 'Tested 1 redirect(s): 2 result(s) need attention.' );
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
		$this->assert_stdout_contains( 'Redirect not found: /test-nonexistent' );
	}
}
