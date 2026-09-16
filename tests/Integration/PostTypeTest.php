<?php
/**
 * Post type tests
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Post type tests class.
 */
final class PostTypeTest extends TestCase {
	/**
	 * Tear down method to be called after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		( new Capability() )->unregister();
		parent::tear_down();
	}

	/**
	 * Test that the post type exists.
	 *
	 * @coversNothing
	 */
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( PostType::POST_TYPE ) );
	}

	/**
	 * Test that redirect records cannot be requested from the front end.
	 *
	 * Redirect posts hold the source path in the title and the destination in
	 * the excerpt, so a publicly queryable post type would leak the mappings
	 * via /?post_type=vip-legacy-redirect&name=<md5-of-source-path>.
	 *
	 * @coversNothing
	 */
	public function test_post_type_is_not_publicly_queryable() {
		$this->assertFalse( is_post_type_viewable( PostType::POST_TYPE ) );
	}

	/**
	 * Test that the redirects list screen is gated on manage_redirects.
	 *
	 * The list screen (wp-admin/edit.php) checks the post type's edit_posts
	 * capability, so an Author (who has edit_posts for regular posts) must
	 * fail it, while a user with manage_redirects must pass. Redirect maps
	 * can reveal unpublished slugs and internal structure, so lower-privileged
	 * users must not be able to read them.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::register
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
	 */
	public function test_list_screen_requires_manage_redirects() {
		( new Capability() )->register();

		$list_screen_capability = get_post_type_object( PostType::POST_TYPE )->cap->edit_posts;

		$this->assertSame( Capability::MANAGE_REDIRECTS_CAPABILITY, $list_screen_capability );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( $list_screen_capability ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( $list_screen_capability ) );
	}
}
