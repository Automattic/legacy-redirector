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
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
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
		$data = $this->resolver()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data );
		$this->assertStringContainsString( 'destination-1', $data['url'] );

		// Switch to site 2.
		switch_to_blog( $this->site_2_id );

		// Should NOT see site 1's redirect.
		$data_site_2 = $this->resolver()->get_redirect_data( '/shared-path' );
		$this->assertNull( $data_site_2 );

		// Create different redirect on site 2.
		$this->create_redirect( '/shared-path', '/destination-2' );

		$data_site_2_after = $this->resolver()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data_site_2_after );
		$this->assertStringContainsString( 'destination-2', $data_site_2_after['url'] );

		// Switch back to main site.
		restore_current_blog();

		// Main site should still have its original redirect.
		$data_main = $this->resolver()->get_redirect_data( '/shared-path' );
		$this->assertNotNull( $data_main );
		$this->assertStringContainsString( 'destination-1', $data_main['url'] );
	}

	/**
	 * Test that the subsite prefix is only stripped on a path-segment boundary.
	 *
	 * A subsite at '/blog' must not have that prefix taken off '/blogging-tips':
	 * the redirect stored for '/blogging-tips' would never fire, and one stored
	 * for '/ging-tips' would fire on the wrong URL in its place.
	 */
	public function test_subsite_prefix_is_stripped_only_on_a_path_boundary(): void {
		// Unique per run: the Integration TestCase has no rollback transaction,
		// so sites created here persist for the whole run.
		$prefix  = 'blog' . substr( md5( (string) microtime( true ) ), 0, 6 );
		$site_id = (int) self::factory()->blog->create( array( 'path' => '/' . $prefix . '/' ) );

		switch_to_blog( $site_id );

		$home_path     = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$expected_home = '/' . $prefix;

		if ( $expected_home !== $home_path ) {
			restore_current_blog();
			wp_delete_site( $site_id );
			$this->markTestSkipped( 'Subdirectory multisite required' );
		}

		$this->create_redirect( '/' . $prefix . 'ging-tips', '/right-destination' );
		$this->create_redirect( '/ging-tips', '/wrong-destination' );
		$this->create_redirect( '/old-page', '/stripped-destination' );

		// Shares the prefix's characters, but not on a segment boundary.
		$partial = $this->resolver()->get_redirect_data( '/' . $prefix . 'ging-tips' );

		// A genuine in-subsite request, where the prefix should be stripped.
		$in_subsite = $this->resolver()->get_redirect_data( '/' . $prefix . '/old-page' );

		restore_current_blog();
		wp_delete_site( $site_id );

		$this->assertNotNull( $partial );
		$this->assertStringContainsString( 'right-destination', $partial['url'] );

		$this->assertNotNull( $in_subsite );
		$this->assertStringContainsString( 'stripped-destination', $in_subsite['url'] );
	}

	/**
	 * A source given as a full URL is stored relative to the site it is saved on.
	 *
	 * Sources are stored relative to the site's own home URL, and the resolver
	 * strips the subsite prefix from every incoming request before looking one
	 * up. A full URL that kept its subsite prefix would therefore be saved
	 * under a key no request can produce, and the redirect would never fire.
	 *
	 * @return void
	 */
	public function test_full_url_source_on_subsite_resolves_for_its_own_request(): void {
		$path    = 'subdir' . substr( md5( (string) microtime( true ) ), 0, 6 );
		$site_id = (int) self::factory()->blog->create( array( 'path' => '/' . $path . '/' ) );

		switch_to_blog( $site_id );

		$full_url = home_url( '/old-page' );
		$this->create_redirect( $full_url, '/new-page' );

		$this->assertSame(
			'/old-page',
			SourceUrl::from_string( $full_url )->path(),
			'The site home path should not survive into the stored source.'
		);

		// The path a visitor hitting that same URL actually requests. On a
		// subdirectory subsite it still carries the prefix; the resolver is
		// what takes it back off.
		$request_path = (string) wp_parse_url( $full_url, PHP_URL_PATH );

		$data = $this->resolver()->get_redirect_data( $request_path );

		$this->assertNotNull( $data, 'A full-URL source should resolve for the URL it was given as.' );
		$this->assertStringContainsString( '/new-page', $data['url'] );

		restore_current_blog();
	}

	/**
	 * Test that repository exists() method respects blog context.
	 */
	public function test_repository_exists_respects_blog_context(): void {
		$source = SourceUrl::from_string( '/test-path' );
		$this->create_redirect( '/test-path', '/dest' );

		$this->assertTrue( $this->repository()->exists( $source ) );

		switch_to_blog( $this->site_2_id );
		$this->assertFalse( $this->repository()->exists( $source ) );
	}

	/**
	 * Test that repository find_by_source() method respects blog context.
	 */
	public function test_repository_find_by_source_respects_blog_context(): void {
		$source = SourceUrl::from_string( '/find-test' );
		$this->create_redirect( '/find-test', '/dest' );

		$redirect = $this->repository()->find_by_source( $source );
		$this->assertNotNull( $redirect );

		switch_to_blog( $this->site_2_id );
		$redirect_site_2 = $this->repository()->find_by_source( $source );
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
		$redirect_main = $this->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_main );
		$this->assertSame( $post_id_main, $redirect_main->destination()->as_post_id()->value() );

		// Switch to site 2 and create redirect with different destination.
		switch_to_blog( $this->site_2_id );

		$post_id_site_2 = self::factory()->post->create();
		$this->create_redirect( '/same-source', $post_id_site_2 );

		// Verify on site 2.
		$redirect_site_2 = $this->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_site_2 );
		$this->assertSame( $post_id_site_2, $redirect_site_2->destination()->as_post_id()->value() );

		// Verify post IDs are different.
		$this->assertNotSame( $post_id_main, $post_id_site_2 );

		// Switch back and verify main site still correct.
		restore_current_blog();

		$redirect_main_after = $this->repository()->find_by_source(
			SourceUrl::from_string( '/same-source' )
		);
		$this->assertNotNull( $redirect_main_after );
		$this->assertSame( $post_id_main, $redirect_main_after->destination()->as_post_id()->value() );
	}
}
