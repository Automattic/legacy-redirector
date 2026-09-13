<?php
/**
 * Multisite integration tests.
 *
 * Tests that redirects are properly isolated between sites in multisite.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Tests for multisite redirect isolation.
 *
 * @group multisite
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectExecutor
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 */
final class MultisiteTest extends TestCase {

	/**
	 * The second test site ID.
	 *
	 * @var int
	 */
	private int $site_2_id;

	/**
	 * Sets up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required' );
		}

		$this->site_2_id = self::factory()->blog->create();
	}

	/**
	 * Tears down test fixtures.
	 */
	public function tear_down(): void {
		restore_current_blog();
		wp_delete_site( $this->site_2_id );
		parent::tear_down();
	}

	/**
	 * Test that redirects on one site are not visible on another site.
	 */
	public function test_cache_isolation_between_sites(): void {
		// Create redirect on main site.
		$this->create_redirect( '/shared-path', '/destination-1' );

		// Verify it exists.
		$data = $this->container()->executor()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data );
		$this->assertStringContainsString( 'destination-1', $data['url'] );

		// Switch to site 2.
		switch_to_blog( $this->site_2_id );

		// Should NOT see site 1's redirect.
		$data_site_2 = $this->container()->executor()->get_redirect_data( '/shared-path' );
		$this->assertNull( $data_site_2 );

		// Create different redirect on site 2.
		$this->create_redirect( '/shared-path', '/destination-2' );

		$data_site_2_after = $this->container()->executor()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data_site_2_after );
		$this->assertStringContainsString( 'destination-2', $data_site_2_after['url'] );

		// Switch back to main site.
		restore_current_blog();

		// Main site should still have its original redirect.
		$data_main = $this->container()->executor()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data_main );
		$this->assertStringContainsString( 'destination-1', $data_main['url'] );
	}

	/**
	 * Test that repository exists() method respects blog context.
	 */
	public function test_repository_exists_respects_blog_context(): void {
		$source = SourceUrl::from_string( '/test-path' );
		$this->create_redirect( '/test-path', '/dest' );

		$this->assertTrue( $this->container()->repository()->exists( $source ) );

		switch_to_blog( $this->site_2_id );
		$this->assertFalse( $this->container()->repository()->exists( $source ) );
	}

	/**
	 * Test that repository find_by_source() method respects blog context.
	 */
	public function test_repository_find_by_source_respects_blog_context(): void {
		$source = SourceUrl::from_string( '/find-test' );
		$this->create_redirect( '/find-test', '/dest' );

		$redirect = $this->container()->repository()->find_by_source( $source );
		$this->assertNotNull( $redirect );

		switch_to_blog( $this->site_2_id );
		$redirect_site_2 = $this->container()->repository()->find_by_source( $source );
		$this->assertNull( $redirect_site_2 );
	}

	/**
	 * Test that the same source path can have different destinations per site.
	 */
	public function test_independent_redirects_per_site(): void {
		// Create redirect on main site to post ID 100.
		$post_id_main = self::factory()->post->create();
		$this->create_redirect( '/same-source', $post_id_main );

		// Verify on main site.
		$redirect_main = $this->container()->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_main );
		$this->assertSame( $post_id_main, $redirect_main->destination()->as_post_id()->value() );

		// Switch to site 2 and create redirect with different destination.
		switch_to_blog( $this->site_2_id );

		$post_id_site_2 = self::factory()->post->create();
		$this->create_redirect( '/same-source', $post_id_site_2 );

		// Verify on site 2.
		$redirect_site_2 = $this->container()->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_site_2 );
		$this->assertSame( $post_id_site_2, $redirect_site_2->destination()->as_post_id()->value() );

		// Verify post IDs are different.
		$this->assertNotSame( $post_id_main, $post_id_site_2 );

		// Switch back and verify main site still correct.
		restore_current_blog();

		$redirect_main_after = $this->container()->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_main_after );
		$this->assertSame( $post_id_main, $redirect_main_after->destination()->as_post_id()->value() );
	}
}
