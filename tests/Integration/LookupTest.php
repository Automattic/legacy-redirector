<?php
/**
 * Redirect lookup integration tests.
 *
 * Tests redirect lookup functionality using the DDD services.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;

/**
 * LookupTest class.
 *
 * Tests redirect lookup functionality via the RedirectResolver and Repository.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class LookupTest extends TestCase {

	/**
	 * Test redirect data lookup.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @dataProvider get_protected_redirect_data
	 *
	 * @param string $from_url        Redirect From URL.
	 * @param string $to_url          Redirect To URL.
	 * @param int    $redirect_status Redirect Status Code.
	 * @return void
	 */
	public function test_get_redirect_data( $from_url, $to_url, $redirect_status ) {

		$this->create_redirect( $from_url, $to_url );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertEquals( $to_url, $redirect_data['url'] );
		$this->assertEquals( $redirect_status, $redirect_data['status_code'] );
	}

	/**
	 * Data provider for tests methods
	 *
	 * @return array
	 */
	public function get_protected_redirect_data() {
		return array(
			'redirect unicode characters with querystring' => array(
				'/فوتوغرافيا/?test=فوتوغرافيا',
				'http://example.com/some_other_page',
				'301',
			),
			'redirect_simple'                              => array(
				'/test',
				'http://example.com/',
				'301',
			),
			'redirect_unicode_no_query'                    => array(
				'/فوتوغرافيا/',
				'http://example.com/',
				'301',
			),
		);
	}

	/**
	 * Test get_redirect_data returns null for URLs without a path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @dataProvider get_urls_without_path_data
	 *
	 * @param string $url URL without a path component.
	 */
	public function test_get_redirect_data_returns_null_for_urls_without_path( $url ) {
		$this->assertNull( $this->resolver()->get_redirect_data( $url ) );
	}

	/**
	 * Data provider for URLs without a path component.
	 *
	 * @return array
	 */
	public function get_urls_without_path_data() {
		return array(
			'empty string'      => array( '' ),
			'query string only' => array( '?foo=bar' ),
			'fragment only'     => array( '#section' ),
			'malformed url'     => array( '://invalid' ),
		);
	}

	/**
	 * Test that trashed redirects do not redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_trashed_redirect_does_not_redirect() {
		$from_url = '/trashed-redirect-test';
		$to_url   = 'http://example.com/destination';

		// Insert a redirect.
		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		// Verify the redirect works initially.
		$redirect_data = $this->resolver()->get_redirect_data( $from_url );
		$this->assertIsArray( $redirect_data );
		$this->assertEquals( $to_url, $redirect_data['url'] );

		// Trash the redirect.
		wp_trash_post( $post_id );

		// Clear the cache to ensure we're testing the post_status check.
		$url_hash = SourceUrl::from_string( $from_url )->hash();
		wp_cache_delete( $url_hash, CachingRedirectRepository::CACHE_GROUP );

		// Verify the redirect no longer works.
		$redirect_data = $this->resolver()->get_redirect_data( $from_url );
		$this->assertNull( $redirect_data );
	}

	/**
	 * Test that draft redirects do not redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_draft_redirect_does_not_redirect() {
		$from_url = '/draft-redirect-test';
		$to_url   = 'http://example.com/destination';

		// Insert a redirect.
		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		// Change status to draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		// Clear the cache.
		$url_hash = SourceUrl::from_string( $from_url )->hash();
		wp_cache_delete( $url_hash, CachingRedirectRepository::CACHE_GROUP );

		// Verify the redirect does not work.
		$redirect_data = $this->resolver()->get_redirect_data( $from_url );
		$this->assertNull( $redirect_data );
	}

	/**
	 * Test redirect to internal post by ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_redirect_to_internal_post(): void {
		// Create destination post.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'internal-destination',
				'post_title'  => 'Internal Destination',
			)
		);

		$from_url = '/internal-redirect-test';

		$post_id = $this->create_redirect( $from_url, $destination_post_id );
		$this->assertIsInt( $post_id );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertSame( get_permalink( $destination_post_id ), $redirect_data['url'] );
	}

	/**
	 * Test redirect with relative path destination prepends home_url.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_redirect_with_relative_path_prepends_home_url(): void {
		$from_url = '/relative-excerpt-test';
		$to_url   = '/destination-path';

		$this->create_redirect( $from_url, $to_url );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertSame( home_url() . $to_url, $redirect_data['url'] );
	}

	/**
	 * Test caching behavior - second call should use cache.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_caching_behavior(): void {
		$from_url = '/cache-test-' . wp_generate_uuid4();
		$to_url   = 'http://example.com/cached';

		$this->create_redirect( $from_url, $to_url );

		// First call should set cache.
		$first_result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertSame( $to_url, $first_result['url'] );

		// Check cache is set (key includes blog ID prefix for multisite safety).
		$url_hash  = get_current_blog_id() . ':' . SourceUrl::from_string( $from_url )->hash();
		$cached_id = wp_cache_get( $url_hash, CachingRedirectRepository::CACHE_GROUP );
		$this->assertNotFalse( $cached_id );

		// Second call should return same result (from cache).
		$second_result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertSame( $to_url, $second_result['url'] );
	}

	/**
	 * Test cache is reset when redirect post is deleted.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_cache_behavior_with_deleted_post(): void {
		$from_url = '/delete-cache-test-' . wp_generate_uuid4();
		$to_url   = 'http://example.com/to-delete';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		// Prime the cache.
		$result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertSame( $to_url, $result['url'] );

		// Delete the post permanently.
		wp_delete_post( $post_id, true );

		// The redirect should no longer work.
		// Note: Cache still holds the post ID, but get_post() returns null.
		$result_after_delete = $this->resolver()->get_redirect_data( $from_url );
		$this->assertNull( $result_after_delete );
	}

	/**
	 * Test repository get_id_by_source returns correct ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_returns_correct_id(): void {
		$from_url = '/post-id-lookup-test';
		$to_url   = 'http://example.com/destination';

		$expected_post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $expected_post_id );

		$actual_post_id = $this->repository()->get_id_by_source( SourceUrl::from_string( $from_url ) );

		$this->assertEquals( $expected_post_id, $actual_post_id );
	}

	/**
	 * Test repository get_id_by_source returns 0 for nonexistent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_returns_zero_for_nonexistent(): void {
		$nonexistent_url = '/this-redirect-does-not-exist-' . wp_generate_uuid4();

		$post_id = $this->repository()->get_id_by_source( SourceUrl::from_string( $nonexistent_url ) );

		$this->assertEquals( 0, $post_id );
	}

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_request_path filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_request_path_filter(): void {
		$original_from = '/original-path';
		$filtered_from = '/filtered-path';
		$to_url        = 'http://example.com/destination';

		// Create redirect for the filtered path.
		$this->create_redirect( $filtered_from, $to_url );

		// Add filter to modify the request path.
		add_filter(
			'wpcom_legacy_redirector_request_path',
			function ( $path ) use ( $original_from, $filtered_from ) {
				if ( $path === $original_from ) {
					return $filtered_from;
				}
				return $path;
			}
		);

		// Request with original path should be redirected via filtered path.
		$redirect_data = $this->resolver()->get_redirect_data( $original_from );

		$this->assertIsArray( $redirect_data );
		$this->assertSame( $to_url, $redirect_data['url'] );

		// Clean up filter.
		remove_all_filters( 'wpcom_legacy_redirector_request_path' );
	}

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_redirect_status filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_redirect_status_filter(): void {
		$from_url = '/status-filter-test';
		$to_url   = 'http://example.com/destination';

		$this->create_redirect( $from_url, $to_url );

		// Add filter to change status to 302.
		add_filter(
			'wpcom_legacy_redirector_redirect_status',
			function () {
				return 302;
			}
		);

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertSame( 302, $redirect_data['status_code'] );

		// Clean up filter.
		remove_all_filters( 'wpcom_legacy_redirector_redirect_status' );
	}

	/**
	 * Test get_redirect_data returns null when filter returns falsy path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_on_falsy_filter_path(): void {
		$from_url = '/filter-blocks-test';
		$to_url   = 'http://example.com/destination';

		$this->create_redirect( $from_url, $to_url );

		// Add filter to return false (block the redirect).
		add_filter( 'wpcom_legacy_redirector_request_path', '__return_false' );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertNull( $redirect_data );

		// Clean up filter.
		remove_all_filters( 'wpcom_legacy_redirector_request_path' );
	}
}
