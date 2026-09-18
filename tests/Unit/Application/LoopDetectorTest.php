<?php
/**
 * LoopDetector service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\LoopDetector;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * LoopDetectorTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class LoopDetectorTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The detector under test.
	 *
	 * @var LoopDetector
	 */
	private LoopDetector $detector;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->detector   = new LoopDetector( $this->repository );
	}

	/**
	 * Creates a redirect to a URL destination.
	 *
	 * @param string $source The source path.
	 * @param string $url    The destination URL.
	 * @return Redirect
	 */
	private function create_redirect( string $source, string $url ): Redirect {
		return Redirect::create(
			SourceUrl::from_string( $source ),
			Destination::from_url( DestinationUrl::from_string( $url ) )
		);
	}

	/**
	 * Expect a publish-only lookup for a path, returning a redirect or null.
	 *
	 * @param string        $path   The source path looked up.
	 * @param Redirect|null $result What the repository returns.
	 * @return void
	 */
	private function expect_lookup( string $path, ?Redirect $result ): void {
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( SourceUrl $s ) => $s->path() === $path ) )
			->andReturn( $result );
	}

	/**
	 * Test a stored self-loop is a cycle of one, found without any lookup.
	 */
	public function test_finds_a_self_loop_without_lookups(): void {
		$this->repository->shouldNotReceive( 'find_by_source' );

		$cycle = $this->detector->find_cycle( $this->create_redirect( '/a', '/a' ) );

		$this->assertSame( array( '/a', '/a' ), $cycle );
	}

	/**
	 * Test a two-member cycle is found and reported in hop order.
	 */
	public function test_finds_a_two_member_cycle(): void {
		$this->expect_lookup( '/b', $this->create_redirect( '/b', '/a' ) );

		$cycle = $this->detector->find_cycle( $this->create_redirect( '/a', '/b' ) );

		$this->assertSame( array( '/a', '/b', '/a' ), $cycle );
	}

	/**
	 * Test a three-member cycle is found.
	 */
	public function test_finds_a_three_member_cycle(): void {
		$this->expect_lookup( '/b', $this->create_redirect( '/b', '/c' ) );
		$this->expect_lookup( '/c', $this->create_redirect( '/c', '/a' ) );

		$cycle = $this->detector->find_cycle( $this->create_redirect( '/a', '/b' ) );

		$this->assertSame( array( '/a', '/b', '/c', '/a' ), $cycle );
	}

	/**
	 * Test a chain ending at a free path is not a cycle.
	 */
	public function test_a_chain_ending_free_is_not_a_cycle(): void {
		$this->expect_lookup( '/b', $this->create_redirect( '/b', '/c' ) );
		$this->expect_lookup( '/c', null );

		$this->assertSame( array(), $this->detector->find_cycle( $this->create_redirect( '/a', '/b' ) ) );
	}

	/**
	 * Test a cycle met along the walk, but not through the start, is not reported.
	 *
	 * Its own members report it when audited; reporting it here would flag
	 * every redirect that merely points at a loop.
	 */
	public function test_a_cycle_not_through_the_start_is_not_reported(): void {
		$this->expect_lookup( '/b', $this->create_redirect( '/b', '/c' ) );
		$this->expect_lookup( '/c', $this->create_redirect( '/c', '/b' ) );

		$this->assertSame( array(), $this->detector->find_cycle( $this->create_redirect( '/a', '/b' ) ) );
	}

	/**
	 * Test an external destination ends the walk immediately.
	 */
	public function test_an_external_destination_ends_the_walk(): void {
		$this->repository->shouldNotReceive( 'find_by_source' );

		$this->assertSame( array(), $this->detector->find_cycle( $this->create_redirect( '/a', 'https://external.com/page' ) ) );
	}

	/**
	 * Test a post ID destination ends the walk immediately.
	 */
	public function test_a_post_id_destination_ends_the_walk(): void {
		$this->repository->shouldNotReceive( 'find_by_source' );

		$redirect = Redirect::create(
			SourceUrl::from_string( '/a' ),
			Destination::from_post_id( DestinationPostId::from_int( 123 ) )
		);

		$this->assertSame( array(), $this->detector->find_cycle( $redirect ) );
	}

	/**
	 * Test a query string keeps two sources distinct, mirroring the resolver.
	 *
	 * The resolver looks sources up with their query string, so /a pointing
	 * at /a?utm_source=x is only a loop if a redirect exists for that exact
	 * source.
	 */
	public function test_a_query_string_destination_is_a_distinct_source(): void {
		$this->expect_lookup( '/a?utm_source=x', null );

		$this->assertSame( array(), $this->detector->find_cycle( $this->create_redirect( '/a', '/a?utm_source=x' ) ) );
	}

	/**
	 * Test the walk stops at the hop cap on a long non-cyclic chain.
	 */
	public function test_the_walk_stops_at_the_hop_cap(): void {
		$this->repository
			->shouldReceive( 'find_by_source' )
			->times( 10 )
			->andReturnUsing(
				function ( SourceUrl $source ): Redirect {
					$step = (int) substr( $source->path(), strlen( '/hop-' ) );
					return $this->create_redirect( $source->path(), '/hop-' . ( $step + 1 ) );
				}
			);

		$this->assertSame( array(), $this->detector->find_cycle( $this->create_redirect( '/hop-0', '/hop-1' ) ) );
	}
}
