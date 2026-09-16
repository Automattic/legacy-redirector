<?php
/**
 * RedirectRequestHandler unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectResolver;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * RedirectRequestHandlerTest class.
 *
 * Tests the request-handling shell around RedirectResolver. The redirect
 * itself cannot be unit tested (wp_safe_redirect is followed by exit), so
 * these tests cover the early-exit paths, the Cache-Control header, and
 * the allowed-hosts filter logic.
 *
 * RedirectResolver is final, so the handler is tested with a real resolver
 * over a mocked repository.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class RedirectRequestHandlerTest extends MonkeyStubs {

	/**
	 * The mock repository backing the resolver.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The handler under test.
	 *
	 * @var RedirectRequestHandler
	 */
	private RedirectRequestHandler $handler;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->handler    = new RedirectRequestHandler( new RedirectResolver( $this->repository ) );

		$GLOBALS['wpcom_legacy_redirector_sent_headers'] = array();
	}

	/**
	 * Invoke the private Cache-Control header method.
	 *
	 * The method is reached in production only via perform_redirect(), which
	 * ends in exit, so it is driven directly here.
	 *
	 * @param string $url         The destination URL.
	 * @param int    $status_code The HTTP status code.
	 * @return string[] The headers sent, as recorded by the header() stub.
	 */
	private function send_cache_control_header( string $url, int $status_code ): array {
		( new \ReflectionMethod( $this->handler, 'send_cache_control_header' ) )
			->invoke( $this->handler, $url, $status_code );

		return $GLOBALS['wpcom_legacy_redirector_sent_headers'];
	}

	/**
	 * Test the max-age defaults to one minute for a non-permanent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::send_cache_control_header
	 */
	public function test_cache_control_max_age_defaults_to_a_minute_for_temporary_redirects(): void {
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_max_age' )
			->once()
			->with( MINUTE_IN_SECONDS, 'https://example.com/destination', 302 )
			->andReturnFirstArg();

		$this->assertSame(
			array( 'Cache-Control: max-age=60' ),
			$this->send_cache_control_header( 'https://example.com/destination', 302 )
		);
	}

	/**
	 * Test the filter overrides the default max-age.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::send_cache_control_header
	 */
	public function test_cache_control_max_age_can_be_overridden_by_the_filter(): void {
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_max_age' )
			->once()
			->with( DAY_IN_SECONDS, 'https://example.com/destination', 301 )
			->andReturn( 3600 );

		$this->assertSame(
			array( 'Cache-Control: max-age=3600' ),
			$this->send_cache_control_header( 'https://example.com/destination', 301 )
		);
	}

	/**
	 * Test a filtered max-age of zero or less suppresses the header.
	 *
	 * @dataProvider data_suppressing_max_ages
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::send_cache_control_header
	 *
	 * @param int $max_age The filtered max-age.
	 * @return void
	 */
	public function test_cache_control_header_is_suppressed_by_a_non_positive_max_age( int $max_age ): void {
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_max_age' )
			->once()
			->andReturn( $max_age );

		$this->assertSame(
			array(),
			$this->send_cache_control_header( 'https://example.com/destination', 301 )
		);
	}

	/**
	 * Data provider for max-ages that suppress the header.
	 *
	 * @return array<string, array{int}>
	 */
	public static function data_suppressing_max_ages(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Test maybe_redirect exits early when not 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_not_404(): void {
		Functions\expect( 'is_404' )
			->once()
			->andReturn( false );

		// The repository should NOT be consulted since we exit early.
		$this->repository
			->shouldNotReceive( 'find_by_source' );

		$this->handler->maybe_redirect();

		// If we get here without a lookup, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_redirect exits early when REQUEST_URI is empty.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_request_uri_empty(): void {
		// Backup and clear REQUEST_URI.
		$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '';

		Functions\expect( 'is_404' )
			->once()
			->andReturn( true );

		// The repository should NOT be consulted since REQUEST_URI is empty.
		$this->repository
			->shouldNotReceive( 'find_by_source' );

		$this->handler->maybe_redirect();

		// Restore REQUEST_URI.
		if ( null !== $original_request_uri ) {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

		$this->assertTrue( true );
	}

	/**
	 * Test maybe_redirect exits early when no redirect found.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_no_redirect_found(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		// Set REQUEST_URI.
		$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/nonexistent-page';

		Functions\expect( 'is_404' )
			->once()
			->andReturn( true );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( null );

		// wp_safe_redirect should NOT be called.
		Functions\expect( 'wp_safe_redirect' )
			->never();

		$this->handler->maybe_redirect();

		// Restore REQUEST_URI.
		if ( null !== $original_request_uri ) {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

		$this->assertTrue( true );
	}

	/**
	 * Test the destination's host is added to the allowed redirect hosts.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::allow_redirect_host
	 */
	public function test_destination_host_is_added_to_allowed_redirect_hosts(): void {
		$callback = null;

		Filters\expectAdded( 'allowed_redirect_hosts' )
			->once()
			->whenHappen(
				static function ( callable $added ) use ( &$callback ): void {
					$callback = $added;
				}
			);

		$this->allow_redirect_host( 'https://external-site.com/destination' );

		$this->assertIsCallable( $callback );
		$this->assertSame(
			array( 'example.com', 'external-site.com' ),
			$callback( array( 'example.com' ) )
		);
	}

	/**
	 * Test no filter is added for a destination without a host.
	 *
	 * A site-relative destination is already an allowed redirect target, so
	 * there is nothing to permit and no callback should be left behind.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::allow_redirect_host
	 */
	public function test_no_filter_is_added_for_a_destination_without_a_host(): void {
		Filters\expectAdded( 'allowed_redirect_hosts' )->never();

		$this->allow_redirect_host( '/site-relative-destination' );

		$this->assertTrue( true );
	}

	/**
	 * Invoke the private allowed-hosts method.
	 *
	 * Like the Cache-Control header, this runs only on the way to an exit,
	 * so it is driven directly.
	 *
	 * @param string $url The destination URL.
	 * @return void
	 */
	private function allow_redirect_host( string $url ): void {
		( new \ReflectionMethod( $this->handler, 'allow_redirect_host' ) )
			->invoke( $this->handler, $url );
	}
}
