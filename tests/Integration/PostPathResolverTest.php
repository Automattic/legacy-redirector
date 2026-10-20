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
}
