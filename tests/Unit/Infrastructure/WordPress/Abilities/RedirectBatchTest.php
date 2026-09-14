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
