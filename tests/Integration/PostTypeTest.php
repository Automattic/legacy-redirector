<?php
/**
 * Post type tests
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

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
}
