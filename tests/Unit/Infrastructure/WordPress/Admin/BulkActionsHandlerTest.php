<?php
/**
 * BulkActionsHandler unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * BulkActionsHandlerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class BulkActionsHandlerTest extends MonkeyStubs {

	/**
	 * The mock manager.
	 *
	 * @var RedirectManager&Mockery\MockInterface
	 */
	private $manager;

	/**
	 * The mock repository the batch resolves IDs through.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The handler under test.
	 *
	 * @var BulkActionsHandler
	 */
	private BulkActionsHandler $handler;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->manager    = Mockery::mock( RedirectManager::class );
		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->handler    = new BulkActionsHandler( $this->manager, new RedirectBatch( new RedirectFetcher( $this->repository ) ) );
	}

	/**
	 * Test register hooks up filters correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::register
	 */
	public function test_register_adds_filters(): void {
		Functions\expect( 'add_filter' )
			->once()
			->with(
				'bulk_actions-edit-vip-legacy-redirect',
				Mockery::type( 'array' )
			);

		Functions\expect( 'add_filter' )
			->once()
			->with(
				'handle_bulk_actions-edit-vip-legacy-redirect',
				Mockery::type( 'array' ),
				10,
				3
			);

		$this->handler->register();
	}

	/**
	 * Test modify_bulk_actions adds enable and disable actions.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::modify_bulk_actions
	 */
	public function test_modify_bulk_actions_adds_enable_disable(): void {
		Functions\when( '__' )->returnArg( 1 );

		$input = array(
			'edit'  => 'Edit',
			'trash' => 'Move to Trash',
		);

		$result = $this->handler->modify_bulk_actions( $input );

		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayHasKey( 'trash', $result );
		$this->assertArrayHasKey( 'enable_redirects', $result );
		$this->assertArrayHasKey( 'disable_redirects', $result );
		$this->assertSame( 'Enable', $result['enable_redirects'] );
		$this->assertSame( 'Disable', $result['disable_redirects'] );
		// The Test bulk action is handled client-side; the server keeps it a
		// no-op, so it must at least be offered here.
		$this->assertSame( 'Test', $result['test_redirects'] );
	}

	/**
	 * Test handle_bulk_actions ignores unrelated actions.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::handle_bulk_actions
	 */
	public function test_handle_bulk_actions_ignores_unrelated_actions(): void {
		$sendback = 'http://example.com/wp-admin/edit.php';

		$result = $this->handler->handle_bulk_actions( $sendback, 'trash', array( 1, 2, 3 ) );

		$this->assertSame( $sendback, $result );
	}

	/**
	 * Test handle_bulk_actions enables redirects.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::handle_bulk_actions
	 */
	public function test_handle_bulk_actions_enables_redirects(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->returnArg( 2 );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$this->expect_status_changes( array( 1, 2, 3 ), 'publish' );

		$result = $this->handler->handle_bulk_actions(
			'http://example.com/wp-admin/edit.php',
			'enable_redirects',
			array( 1, 2, 3 )
		);

		$this->assertStringContainsString( 'bulk_redirects_enabled=3', $result );
		$this->assertStringNotContainsString( 'bulk_redirects_failed', $result );
	}

	/**
	 * Test handle_bulk_actions disables redirects.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::handle_bulk_actions
	 */
	public function test_handle_bulk_actions_disables_redirects(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'remove_query_arg' )->returnArg( 2 );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$this->expect_status_changes( array( 5, 6 ), 'draft' );

		$result = $this->handler->handle_bulk_actions(
			'http://example.com/wp-admin/edit.php',
			'disable_redirects',
			array( 5, 6 )
		);

		$this->assertStringContainsString( 'bulk_redirects_disabled=2', $result );
	}

	/**
	 * Test handle_bulk_actions checks user capabilities.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::handle_bulk_actions
	 */
	public function test_handle_bulk_actions_checks_capabilities(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$sendback = 'http://example.com/wp-admin/edit.php';

		$result = $this->handler->handle_bulk_actions( $sendback, 'enable_redirects', array( 1 ) );

		$this->assertSame( $sendback, $result );
	}

	/**
	 * Expect each redirect to be found and changed to the given status.
	 *
	 * @param int[]  $ids        The redirect IDs.
	 * @param string $new_status The status each is changed to.
	 * @return void
	 */
	private function expect_status_changes( array $ids, string $new_status ): void {
		foreach ( $ids as $id ) {
			$this->repository
				->shouldReceive( 'find_by_id' )
				->with( $id )
				->andReturn(
					Redirect::reconstitute(
						$id,
						SourceUrl::from_string( '/old-' . $id ),
						Destination::from_url( DestinationUrl::from_string( '/new' ) ),
						'publish' === $new_status ? 'draft' : 'publish'
					)
				);

			$this->manager
				->shouldReceive( 'change_status' )
				->once()
				->with( $id, $new_status )
				->andReturn( RedirectCreationResult::success( $id ) );
		}
	}
}
