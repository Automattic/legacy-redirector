<?php
/**
 * RedirectBatch unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\BatchOutcome;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * RedirectBatchTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
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
	 * Test resolution reports an outcome for every identifier, in order.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::resolve
	 */
	public function test_resolve_reports_each_identifier_that_fails(): void {
		$redirect = $this->redirect( 12 );

		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 99 )->andReturn( null );

		$items = $this->batch->resolve( array( 12, 99 ) );

		$this->assertCount( 2, $items );

		$this->assertSame( '12', $items[0]['identifier'] );
		$this->assertSame( BatchOutcome::SUCCESS, $items[0]['outcome'] );
		$this->assertSame( $redirect, $items[0]['redirect'] );

		$this->assertSame( '99', $items[1]['identifier'] );
		$this->assertSame( BatchOutcome::NOT_FOUND, $items[1]['outcome'] );
		$this->assertNull( $items[1]['redirect'] );

		$this->assertSame( 1, RedirectBatch::count_succeeded( $items ) );
		$this->assertSame( array( $redirect ), RedirectBatch::redirects( $items ) );
	}

	/**
	 * Test a source path is resolved through the source lookup.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::resolve
	 */
	public function test_resolve_accepts_a_source_path(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$redirect = $this->redirect( 7, '/old-page' );

		$this->repository->shouldReceive( 'get_id_by_source' )->once()->andReturn( 7 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 7 )->andReturn( $redirect );

		$items = $this->batch->resolve( array( '/old-page' ) );

		$this->assertSame( '/old-page', $items[0]['identifier'] );
		$this->assertSame( BatchOutcome::SUCCESS, $items[0]['outcome'] );
	}

	/**
	 * Test an unparseable identifier is reported rather than thrown.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::resolve
	 */
	public function test_resolve_reports_an_invalid_identifier(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$items = $this->batch->resolve( array( 'http://example.com' ) );

		$this->assertSame( 'http://example.com', $items[0]['identifier'] );
		$this->assertSame( BatchOutcome::INVALID, $items[0]['outcome'] );
		$this->assertNotEmpty( $items[0]['error'] );
		$this->assertSame( array(), RedirectBatch::redirects( $items ) );
	}

	/**
	 * Test the action only runs for redirects that resolved.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::apply
	 */
	public function test_apply_skips_identifiers_that_did_not_resolve(): void {
		$redirect = $this->redirect( 12 );

		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 99 )->andReturn( null );

		$seen = array();

		$items = $this->batch->apply(
			array( 12, 99 ),
			static function ( Redirect $resolved ) use ( &$seen ): bool {
				$seen[] = $resolved->id();

				return true;
			}
		);

		$this->assertSame( array( 12 ), $seen );
		$this->assertSame( 1, RedirectBatch::count_succeeded( $items ) );
	}

	/**
	 * Test a mixed batch tells its three kinds of outcome apart.
	 *
	 * This is the reason the loop is shared: a redirect that exists but could
	 * not be saved must never be reported as one that does not exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::apply
	 */
	public function test_apply_distinguishes_not_found_from_a_failed_action(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$redirect = $this->redirect( 12 );

		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $redirect );
		$this->repository->shouldReceive( 'find_by_id' )->with( 99 )->andReturn( null );

		$items = $this->batch->apply(
			array( 12, 99, 'http://example.com' ),
			static fn(): RedirectCreationResult => RedirectCreationResult::error( 'save_failed', 'The database said no.' )
		);

		$this->assertSame( BatchOutcome::FAILED, $items[0]['outcome'], 'A save failure is not a missing redirect.' );
		$this->assertSame( 'The database said no.', $items[0]['error'], 'The action error message should carry through.' );
		$this->assertSame( $redirect, $items[0]['redirect'] );

		$this->assertSame( BatchOutcome::NOT_FOUND, $items[1]['outcome'] );
		$this->assertNull( $items[1]['error'] );

		$this->assertSame( BatchOutcome::INVALID, $items[2]['outcome'] );

		$this->assertSame( 0, RedirectBatch::count_succeeded( $items ) );
	}

	/**
	 * Test an action returning false fails the item without an error message.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectBatch::apply
	 */
	public function test_apply_fails_an_item_when_the_action_returns_false(): void {
		$this->repository->shouldReceive( 'find_by_id' )->with( 12 )->andReturn( $this->redirect( 12 ) );

		$items = $this->batch->apply( array( 12 ), static fn(): bool => false );

		$this->assertSame( BatchOutcome::FAILED, $items[0]['outcome'] );
		$this->assertNull( $items[0]['error'] );
	}

	/**
	 * Build a redirect for the tests.
	 *
	 * @param int    $id     The redirect ID.
	 * @param string $source The source path.
	 * @return Redirect
	 */
	private function redirect( int $id, string $source = '/old' ): Redirect {
		return Redirect::reconstitute(
			$id,
			SourceUrl::from_string( $source ),
			Destination::from_mixed( '/new' ),
			'publish'
		);
	}
}
