<?php
/**
 * SearchPostsHandler AJAX integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\SearchPostsHandler;
use WPAjaxDieStopException;

/**
 * Integration tests for the post search autocomplete AJAX endpoint.
 *
 * The response shapes asserted here are the exact contract consumed by
 * js/admin-redirect-form.js, which reads response.success and iterates
 * response.data.posts expecting id, title and type on each entry.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\SearchPostsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 */
final class SearchPostsHandlerTest extends AjaxHandlerTestCase {

	/**
	 * Set up the handler under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new SearchPostsHandler() )->register();
	}

	/**
	 * Test a request without a nonce is rejected before any search runs.
	 */
	public function test_request_without_nonce_is_rejected(): void {
		$this->login_as_redirect_manager();
		$_POST = array( 'search' => 'hello' );

		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		$this->_handleAjax( SearchPostsHandler::get_action() );
	}

	/**
	 * Test a valid nonce without the manage capability is rejected.
	 */
	public function test_request_without_capability_is_rejected(): void {
		$this->login_without_manage_capability();

		$response = $this->dispatch( SearchPostsHandler::get_action(), array( 'search' => 'hello' ) );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Permission denied.' ),
			),
			$response
		);
	}

	/**
	 * Test a search term under two characters returns no posts without querying.
	 */
	public function test_short_search_term_returns_no_posts(): void {
		$this->login_as_redirect_manager();
		self::factory()->post->create( array( 'post_title' => 'A post that would match' ) );

		$response = $this->dispatch( SearchPostsHandler::get_action(), array( 'search' => 'a' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'posts' => array() ),
			),
			$response
		);
	}

	/**
	 * Test a matching search returns posts in the shape the admin JS expects.
	 */
	public function test_matching_search_returns_posts(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Zydeco festival lineup',
				'post_status' => 'publish',
			)
		);

		$response = $this->dispatch( SearchPostsHandler::get_action(), array( 'search' => 'Zydeco' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'posts' => array(
						array(
							'id'    => $post_id,
							'title' => 'Zydeco festival lineup',
							'type'  => 'Post',
						),
					),
				),
			),
			$response
		);
	}

	/**
	 * Test unpublished posts are not returned.
	 */
	public function test_unpublished_posts_are_not_returned(): void {
		$this->login_as_redirect_manager();
		self::factory()->post->create(
			array(
				'post_title'  => 'Zydeco draft post',
				'post_status' => 'draft',
			)
		);

		$response = $this->dispatch( SearchPostsHandler::get_action(), array( 'search' => 'Zydeco' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'posts' => array() ),
			),
			$response
		);
	}
}
