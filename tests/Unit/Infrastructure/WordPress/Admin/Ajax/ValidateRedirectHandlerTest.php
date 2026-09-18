<?php
/**
 * ValidateRedirectHandler unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * ValidateRedirectHandlerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler
 */
final class ValidateRedirectHandlerTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The mock auditor.
	 *
	 * @var RedirectAuditor&Mockery\MockInterface
	 */
	private $auditor;

	/**
	 * The handler under test.
	 *
	 * @var ValidateRedirectHandler
	 */
	private ValidateRedirectHandler $handler;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->auditor    = Mockery::mock( RedirectAuditor::class );
		$this->handler    = new ValidateRedirectHandler( $this->repository, $this->auditor );
	}

	/**
	 * Test get_action returns the correct action name.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler::get_action
	 */
	public function test_get_action_returns_correct_name(): void {
		$this->assertSame( 'validate_redirect', ValidateRedirectHandler::get_action() );
	}

	/**
	 * Test register hooks up the AJAX action.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler::register
	 */
	public function test_register_adds_ajax_action(): void {
		Functions\expect( 'add_action' )
			->once()
			->with(
				'wp_ajax_validate_redirect',
				Mockery::type( 'array' )
			);

		$this->handler->register();
	}
}
