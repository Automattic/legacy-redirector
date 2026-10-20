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

	/**
	 * Test the single-item trash notice shows an encoded source as stored.
	 *
	 * The source arrives already decoded once by PHP. Decoding it again turned
	 * a stored %2F into a slash, and sanitize_text_field() then deleted any
	 * %xx sequence left, so the notice named a different redirect. Core then
	 * runs sprintf() over the message to insert the count, which read %2F as
	 * a float and threw on %C3, so the assertion goes through that step too.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::bulk_post_updated_messages
	 */
	public function test_trash_notice_shows_encoded_source_as_stored() {
		$_GET['redirect_source'] = wp_slash( '/a%2Fb?q=caf%C3%A9' );

		$messages = ( new PostType() )->bulk_post_updated_messages(
			array(),
			array(
				'updated'   => 0,
				'locked'    => 0,
				'deleted'   => 0,
				'trashed'   => 1,
				'untrashed' => 0,
			)
		);

		unset( $_GET['redirect_source'] );

		// Mirror wp-admin/edit.php, which inserts the count with sprintf().
		$notice = sprintf( $messages[ PostType::POST_TYPE ]['trashed'], number_format_i18n( 1 ) );

		$this->assertStringContainsString( '<code>/a%2Fb?q=caf%C3%A9</code>', $notice );
	}
}
