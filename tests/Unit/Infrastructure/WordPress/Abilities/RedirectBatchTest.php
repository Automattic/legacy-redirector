<?php
/**
 * RedirectBatch unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * RedirectBatchTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class RedirectBatchTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The batch resolver under test.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->batch      = new RedirectBatch( new RedirectFetcher( $this->repository ) );
	}

	/**
	 * Test resolution splits found redirects from the ones that cannot be resolved.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch::resolve
	 */
	public function test_resolve_reports_each_identifier_that_fails(): void {
		$redirect = Redirect::reconstitute(
			12,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( '/new' ),
			'publish'
		);

		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 99 )->andReturn( null );

		$result = $this->batch->resolve( array( 12, 99 ) );

		$this->assertCount( 1, $result['resolved'] );
		$this->assertSame( '12', $result['resolved'][0]['identifier'] );
		$this->assertSame( $redirect, $result['resolved'][0]['redirect'] );

		$this->assertCount( 1, $result['failures'] );
		$this->assertSame( '99', $result['failures'][0]['redirect'] );
		$this->assertNotEmpty( $result['failures'][0]['reason'] );
	}

	/**
	 * Test a source path is resolved through the source lookup.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch::resolve
	 */
	public function test_resolve_accepts_a_source_path(): void {
		$redirect = Redirect::reconstitute(
			7,
			SourceUrl::from_string( '/old-page' ),
			Destination::from_mixed( 5 ),
			'publish'
		);

		$this->repository->shouldReceive( 'get_id_by_source' )->once()->andReturn( 7 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 7 )->andReturn( $redirect );

		$result = $this->batch->resolve( array( '/old-page' ) );

		$this->assertSame( '/old-page', $result['resolved'][0]['identifier'] );
		$this->assertSame( array(), $result['failures'] );
	}

	/**
	 * Test the action runs for resolved redirects, and failures are collected.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch::apply
	 */
	public function test_apply_counts_changes_and_collects_failures(): void {
		$redirect = Redirect::reconstitute(
			12,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( '/new' ),
			'publish'
		);

		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 13 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 99 )->andReturn( null );

		$seen = array();

		$result = $this->batch->apply(
			array( 12, 13, 99 ),
			static function ( Redirect $resolved ) use ( &$seen ): bool {
				$seen[] = $resolved->id();

				// Refuse the second one, to prove a refusal is not counted.
				return 1 === count( $seen );
			},
			'Could not be changed.'
		);

		$this->assertCount( 2, $seen, 'The action should not run for unresolved identifiers.' );
		$this->assertSame( 1, $result['changed'] );
		$this->assertCount( 2, $result['failures'] );
		$this->assertSame( 'Could not be changed.', $result['failures'][1]['reason'] );
	}

	/**
	 * Test an unparseable identifier is reported rather than thrown.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch::resolve
	 */
	public function test_resolve_reports_an_invalid_identifier(): void {
		Functions\when( 'esc_url_raw' )->justReturn( '' );

		$result = $this->batch->resolve( array( 'not a path' ) );

		$this->assertSame( array(), $result['resolved'] );
		$this->assertSame( 'not a path', $result['failures'][0]['redirect'] );
	}
}
