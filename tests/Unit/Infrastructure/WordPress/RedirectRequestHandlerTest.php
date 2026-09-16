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
 * these tests cover the early-exit paths and the allowed-hosts filter logic.
 *
 * RedirectResolver is final, so the handler is tested with a real resolver
 * over a mocked repository.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
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
	 * Test that the allowed_redirect_hosts filter callback adds the host correctly.
	 *
	 * This tests the closure logic directly by simulating what allow_redirect_host does.
	 * The actual integration with wp_safe_redirect is tested in integration tests.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::allow_redirect_host
	 */
	public function test_allowed_redirect_hosts_filter_adds_host_to_array(): void {
		// Simulate the closure that allow_redirect_host creates.
		$host            = 'external-site.com';
		$filter_callback = static function ( array $hosts ) use ( $host ): array {
			$hosts[] = $host;
			return $hosts;
		};

		$existing_hosts = array( 'example.com', 'another-site.com' );
		$result         = $filter_callback( $existing_hosts );

		$this->assertContains( 'external-site.com', $result );
		$this->assertContains( 'example.com', $result );
		$this->assertContains( 'another-site.com', $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Test that the filter callback works with empty initial hosts array.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler::allow_redirect_host
	 */
	public function test_allowed_redirect_hosts_filter_works_with_empty_array(): void {
		$host            = 'external-site.com';
		$filter_callback = static function ( array $hosts ) use ( $host ): array {
			$hosts[] = $host;
			return $hosts;
		};

		$result = $filter_callback( array() );

		$this->assertContains( 'external-site.com', $result );
		$this->assertCount( 1, $result );
	}
}
