<?php
/**
 * CheckSourceHandler unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * CheckSourceHandlerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler
 */
final class CheckSourceHandlerTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The handler under test.
	 *
	 * @var CheckSourceHandler
	 */
	private CheckSourceHandler $handler;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->handler    = new CheckSourceHandler( $this->repository, new RedirectAuditor() );
	}

	/**
	 * Test get_action returns the correct action name.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler::get_action
	 */
	public function test_get_action_returns_correct_name(): void {
		$this->assertSame( 'check_redirect_source', CheckSourceHandler::get_action() );
	}

	/**
	 * Test register hooks up the AJAX action.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler::register
	 */
	public function test_register_adds_ajax_action(): void {
		Functions\expect( 'add_action' )
			->once()
			->with(
				'wp_ajax_check_redirect_source',
				Mockery::type( 'array' )
			);

		$this->handler->register();
	}
}
