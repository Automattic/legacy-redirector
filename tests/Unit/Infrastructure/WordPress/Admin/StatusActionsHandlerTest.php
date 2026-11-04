<?php
/**
 * StatusActionsHandler unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * StatusActionsHandlerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler
 */
final class StatusActionsHandlerTest extends MonkeyStubs {

	/**
	 * The mock manager.
	 *
	 * @var RedirectManager&Mockery\MockInterface
	 */
	private $manager;

	/**
	 * The handler under test.
	 *
	 * @var StatusActionsHandler
	 */
	private StatusActionsHandler $handler;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->manager = Mockery::mock( RedirectManager::class );
		$this->handler = new StatusActionsHandler( $this->manager, Mockery::mock( RedirectRepositoryInterface::class ) );
	}

	/**
	 * Test register hooks up admin_post actions correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler::register
	 */
	public function test_register_adds_admin_post_actions(): void {
		Functions\expect( 'add_action' )
			->once()
			->with(
				'admin_post_enable_redirect',
				Mockery::type( 'array' )
			);

		Functions\expect( 'add_action' )
			->once()
			->with(
				'admin_post_disable_redirect',
				Mockery::type( 'array' )
			);

		$this->handler->register();
	}
}
