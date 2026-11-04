<?php
/**
 * PostPathResolver integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\PostPathResolver;

/**
 * Integration tests for resolving a path to the post it serves.
 *
 * @covers \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 */
final class PostPathResolverTest extends TestCase {

	/**
	 * Test a path whose slug only a non-viewable post carries resolves to nothing.
	 */
	public function test_ignores_a_post_no_visitor_can_view(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_template',
				'post_status' => 'publish',
				'post_name'   => 'only-a-template',
			)
		);

		$this->assertNull( PostPathResolver::post( 'only-a-template' ) );
	}

	/**
	 * Test a non-viewable post sharing a page's slug does not shadow the page.
	 */
	public function test_prefers_a_page_to_a_non_viewable_post_with_its_slug(): void {
		// Created first, so get_page_by_path() would otherwise find it first.
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_template',
				'post_status' => 'publish',
				'post_name'   => 'shared-slug',
			)
		);
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'shared-slug',
			)
		);

		$this->assertSame( $page_id, PostPathResolver::post( 'shared-slug' )?->ID );
	}

	/**
	 * Test asking about the same path twice in a row costs nothing the second time.
	 *
	 * An audit resolves a destination, then asks whether only HTTP can judge
	 * it, so the second lookup must not repeat the queries or object cache
	 * round trips of the first. Only the posts last-changed check remains.
	 */
	public function test_answers_a_repeated_lookup_from_memory(): void {
		global $wpdb, $wp_object_cache;

		$this->set_permalink_structure( '/%postname%/' );

		PostPathResolver::post( 'nothing-lives-here' );

		$queries = $wpdb->num_queries;
		$lookups = $wp_object_cache->cache_hits + $wp_object_cache->cache_misses;

		$this->assertNull( PostPathResolver::post( 'nothing-lives-here' ) );
		$this->assertSame( $queries, $wpdb->num_queries, 'A repeated lookup must not query the database.' );
		$this->assertLessThanOrEqual( 1, $wp_object_cache->cache_hits + $wp_object_cache->cache_misses - $lookups, 'A repeated lookup must not go back to the object cache.' );
	}

	/**
	 * Test a remembered answer does not outlive a post being saved.
	 *
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository::find_by_destination_post
	 */
	public function test_sees_a_post_saved_since_the_last_lookup(): void {
		$this->assertNull( PostPathResolver::post( 'arrives-later' ) );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'arrives-later',
			)
		);

		$this->assertSame( $page_id, PostPathResolver::post( 'arrives-later' )?->ID );

		wp_trash_post( $page_id );

		$this->assertNull( PostPathResolver::post( 'arrives-later' ) );
	}
}
