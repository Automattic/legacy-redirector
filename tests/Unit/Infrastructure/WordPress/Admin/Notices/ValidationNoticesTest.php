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

		$this->notices = new ValidationNotices(
			Mockery::mock( RedirectRepositoryInterface::class ),
			Mockery::mock( RedirectValidator::class )
		);

		$_GET['validate'] = 'valid';
		$_GET['ids']      = '123';
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		unset( $_GET['validate'], $_GET['ids'] );

		parent::tear_down();
	}

	/**
	 * Test no notice is rendered for users without the manage redirects capability.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_display_validation_notices_requires_capability(): void {
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
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( $this->post( PostType::POST_TYPE, '/old-page' ) );

		$this->assertStringContainsString( '/old-page', $this->render() );
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
