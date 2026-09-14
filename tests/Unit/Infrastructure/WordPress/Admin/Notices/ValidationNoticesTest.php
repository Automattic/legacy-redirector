<?php
/**
 * ValidationNotices unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use WP_Post;

/**
 * ValidationNoticesTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices
 */
final class ValidationNoticesTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The notices handler under test.
	 *
	 * @var ValidationNotices
	 */
	private ValidationNotices $notices;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\stubs( array( 'wp_kses_post' ) );

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->notices    = new ValidationNotices(
			$this->repository,
			Mockery::mock( RedirectValidator::class )
		);
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		unset( $_GET['validate'], $_GET['ids'], $_GET['action'], $_GET['post'], $_REQUEST['_validate_redirect'] );

		parent::tear_down();
	}

	/**
	 * Test no notice is rendered for users without the manage redirects capability.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_display_validation_notices_requires_capability(): void {
		$this->prime_notice_request();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_post' )->never();

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test the title of a post that is not a redirect is never disclosed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_display_validation_notices_ignores_other_post_types(): void {
		$this->prime_notice_request();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->post( 'post', 'Secret draft title' ) );

		$this->assertStringNotContainsString( 'Secret draft title', $this->render() );
	}

	/**
	 * Test the source path of a redirect is shown in the notice.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_display_validation_notices_shows_redirect_source(): void {
		$this->prime_notice_request();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->post( PostType::POST_TYPE, '/old-page' ) );

		$this->assertStringContainsString( '/old-page', $this->render() );
	}

	/**
	 * Test register hooks the validation handler on admin_init, not a front-end hook.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::register
	 */
	public function test_register_hooks_validation_handler_on_admin_init(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_notices', Mockery::type( 'array' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'admin_init', Mockery::type( 'array' ) );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'removable_query_args', Mockery::type( 'array' ) );

		$this->notices->register();
	}

	/**
	 * Test handle_validation_action dies without validating when the user lacks the capability.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::handle_validation_action
	 */
	public function test_handle_validation_action_requires_capability(): void {
		$_GET['action']                 = 'validate';
		$_GET['post']                   = '123';
		$_REQUEST['_validate_redirect'] = 'valid-nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )
			->once()
			->andThrow( new \RuntimeException( 'wp_die' ) );

		$this->repository->shouldNotReceive( 'find_by_id' );

		$this->expectException( \RuntimeException::class );

		$this->notices->handle_validation_action();
	}

	/**
	 * Prime the request superglobals for a notice display.
	 *
	 * @return void
	 */
	private function prime_notice_request(): void {
		$_GET['validate'] = 'valid';
		$_GET['ids']      = '123';
	}

	/**
	 * Build a WP_Post stub.
	 *
	 * @param string $post_type  Post type.
	 * @param string $post_title Post title.
	 * @return WP_Post
	 */
	private function post( string $post_type, string $post_title ): WP_Post {
		$post             = Mockery::mock( WP_Post::class );
		$post->post_type  = $post_type;
		$post->post_title = $post_title;

		return $post;
	}

	/**
	 * Capture the output of the notices.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->notices->display_validation_notices();

		return (string) ob_get_clean();
	}
}
