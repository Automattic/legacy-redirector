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
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class LookupTest extends TestCase {

	/**
	 * Test a created redirect is found again from the same request path.
	 *
	 * Storage and keying of each script are covered at the repository level
	 * (PostTypeRedirectRepositoryTest::data_unicode_source_paths); these rows
	 * are the request-path spellings the resolver has to parse to that key.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @dataProvider data_redirects
	 *
	 * @param string      $from     The source path, as created and as requested.
	 * @param string      $to       The destination, as created.
	 * @param string|null $expected The destination URL served, when it differs from $to.
	 */
	public function test_get_redirect_data( string $from, string $to, ?string $expected = null ): void {
		$this->create_redirect( $from, $to );

		$redirect_data = $this->resolver()->get_redirect_data( $from );

		$this->assertSame( $expected ?? $to, $redirect_data['url'] ?? null );
		$this->assertSame( 301, $redirect_data['status_code'] );
	}

	/**
	 * Data provider for test_get_redirect_data.
	 *
	 * @return array<string, array{0: string, 1: string, 2?: string}>
	 */
	public function data_redirects(): array {
		return array(
			'simple'                     => array( '/test', 'http://example.com/' ),
			'query string'               => array( '/a-redirect?with=query-string', 'http://example.com/' ),
			// Only the path and query are stored, so the fragment is dropped.
			'fragment'                   => array( '/hash-redirect#with-hash', 'http://example.com/' ),
			'unicode with unicode query' => array( '/فوتوغرافيا/?test=فوتوغرافيا', 'http://example.com/some_other_page' ),
			'unicode, trailing slash'    => array( '/فوتوغرافيا/', 'http://example.com/' ),
			// Four UTF-8 bytes per character, which the parser must not split.
			'emoji'                      => array( '/party-🎉', 'http://example.com/emoji' ),
			'mixed scripts with query'   => array( '/привет-納豆-🎉?тест=значение', 'http://example.com/mixed' ),
			// The lookup path once decoded before parsing while creation
			// decoded once, so these three never matched what was stored.
			'encoded percent'            => array( '/100%25-cotton', 'http://example.com/cotton' ),
			'double-encoded space'       => array( '/a%2520b', 'http://example.com/a-b' ),
			'encoded hash'               => array( '/page%23section', 'http://example.com/section' ),
			'unicode destination'        => array( '/unicode-destination', 'http://example.com/привет-🎉' ),
			'unicode relative path'      => array( '/unicode-relative-destination', '/привет-мир', home_url() . '/привет-мир' ),
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

		// The save pre-warmed the cache, so even the first call reads it.
		$first_result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertSame( $to_url, $first_result['url'] );

		// Check cache is set.
		$cached_id = wp_cache_get(
			CachingRedirectRepository::cache_key( SourceUrl::from_string( $from_url )->hash() ),
			CachingRedirectRepository::CACHE_GROUP
		);
		$this->assertNotFalse( $cached_id );

		// Second call should return same result (from cache).
		$second_result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertSame( $to_url, $second_result['url'] );
	}

	/**
	 * Test a callback on a pre-2.0 hook name still changes the outcome.
	 *
	 * 1.x sites keep these callbacks after upgrading, so each must still reach
	 * the lookup through apply_filters_deprecated().
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @dataProvider data_deprecated_filters
	 *
	 * @param string   $hook     The pre-2.0 hook name.
	 * @param callable $callback The callback a 1.x site would attach.
	 * @param string   $request  The request URL.
	 * @param array    $expected The expected redirect data.
	 */
	public function test_get_redirect_data_applies_deprecated_filter( string $hook, callable $callback, string $request, array $expected ): void {
		$this->setExpectedDeprecated( $hook );

		$this->create_redirect( '/deprecated-filter-test', 'http://example.com/destination' );

		add_filter( $hook, $callback );

		$this->assertSame( $expected, $this->resolver()->get_redirect_data( $request ) );
	}

	/**
	 * Data provider for test_get_redirect_data_applies_deprecated_filter.
	 *
	 * @return array<string, array{string, callable, string, array}>
	 */
	public function data_deprecated_filters(): array {
		return array(
			'request path rewritten to a stored source' => array(
				'wpcom_legacy_redirector_request_path',
				fn( $path ) => '/old-name' === $path ? '/deprecated-filter-test' : $path,
				'/old-name',
				array(
					'url'         => 'http://example.com/destination',
					'status_code' => 301,
				),
			),
			'query key preserved onto the destination'  => array(
				'wpcom_legacy_redirector_preserve_query_params',
				fn() => array( 'utm_source' ),
				'/deprecated-filter-test?utm_source=newsletter',
				array(
					'url'         => 'http://example.com/destination?utm_source=newsletter',
					'status_code' => 301,
				),
			),
			'status code changed'                       => array(
				'wpcom_legacy_redirector_redirect_status',
				fn() => 302,
				'/deprecated-filter-test',
				array(
					'url'         => 'http://example.com/destination',
					'status_code' => 302,
				),
			),
		);
	}

	/**
	 * Test the 2.0 hook name wins when a callback is attached to both names.
	 *
	 * The deprecated filter runs first and its result feeds the new filter, so
	 * a caller that has migrated is never overridden by a stale callback.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_prefers_current_hook_over_deprecated(): void {
		$this->setExpectedDeprecated( 'wpcom_legacy_redirector_redirect_status' );

		$from_url = '/both-status-filters-test';
		$to_url   = 'http://example.com/destination';

		$this->create_redirect( $from_url, $to_url );

		add_filter( 'wpcom_legacy_redirector_redirect_status', fn() => 302 );
		add_filter( 'legacy_redirector_redirect_status', fn() => 308 );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertSame( 308, $redirect_data['status_code'] );
	}

	/**
	 * Test that trashing a redirect stops it redirecting and frees its source, with the lookup cache warm.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 */
	public function test_trashing_a_redirect_frees_its_source(): void {
		$post_id = $this->create_redirect( '/trash-frees-source', '/new' );
		$source  = SourceUrl::from_string( '/trash-frees-source' );
		$this->assertSame( $post_id, $this->repository()->get_id_by_source( $source ) );
		$this->assertSame( home_url( '/new' ), $this->resolver()->get_redirect_data( '/trash-frees-source' )['url'] ?? null );

		wp_trash_post( $post_id );

		$this->assertSame( 0, $this->repository()->get_id_by_source( $source ) );
		$this->assertNull( $this->resolver()->get_redirect_data( '/trash-frees-source' ) );
		$this->assertTrue( $this->create_redirect_result( '/trash-frees-source', '/other' )->is_success() );
	}

	/**
	 * Test that deleting a redirect outside the plugin stops it redirecting and frees its source, with the lookup cache warm.
	 *
	 * Core's delete does not go through the repository, so only the plugin's
	 * clean_post_cache hook drops the cached entry.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 */
	public function test_deleting_a_redirect_outside_the_plugin_frees_its_source(): void {
		$post_id = $this->create_redirect( '/delete-frees-source', '/new' );
		$source  = SourceUrl::from_string( '/delete-frees-source' );
		$this->assertSame( $post_id, $this->repository()->get_id_by_source( $source ) );
		$this->assertSame( home_url( '/new' ), $this->resolver()->get_redirect_data( '/delete-frees-source' )['url'] ?? null );

		wp_delete_post( $post_id, true );

		// The key first: a lookup that finds the post gone drops the entry
		// itself, which would hide a missed invalidation.
		$this->assertSame( 0, $this->repository()->get_id_by_source( $source ) );
		$this->assertNull( $this->resolver()->get_redirect_data( '/delete-frees-source' ) );
		$this->assertTrue( $this->create_redirect_result( '/delete-frees-source', '/other' )->is_success() );
	}

	/**
	 * Test that deleting a disabled duplicate leaves the live redirect it shares a source with working.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::delete
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_deleting_a_duplicate_keeps_the_live_redirect(): void {
		$live_id      = $this->create_redirect( '/clash', 'https://example.com/one' );
		$duplicate_id = $this->insert_disabled_duplicate( '/clash/', $live_id, marked: false );
		$source       = SourceUrl::from_string( '/clash' );
		$this->assertSame( $live_id, $this->repository()->find_by_source( $source )?->id() );

		$this->assertTrue( $this->repository()->delete( $this->repository()->find_by_id( $duplicate_id ) ) );

		$this->assertSame( $live_id, $this->repository()->find_by_source( $source )?->id() );
		$this->assertSame( $live_id, $this->repository()->find_by_source( $source )?->id() );
	}

	/**
	 * Test that deleting a redirect with no twin leaves its source resolving to nothing.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::delete
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_deleting_a_redirect_leaves_its_source_resolving_to_nothing(): void {
		$post_id = $this->create_redirect( '/deleted-no-twin', '/new' );
		$source  = SourceUrl::from_string( '/deleted-no-twin' );

		$this->assertTrue( $this->repository()->delete( $this->repository()->find_by_id( $post_id ) ) );

		$this->assertNull( $this->repository()->find_by_source( $source ) );
		$this->assertSame( 0, $this->repository()->get_id_by_source( $source ) );
	}

	/**
	 * Test a miss cached while a redirect was in the trash does not outlive its return.
	 *
	 * Untrashing restores it as disabled, so it takes an enable as well before
	 * the source should redirect again. Enabled through the plugin, its save
	 * refreshes the entry; enabled through core, only the plugin's
	 * clean_post_cache hooks do.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 * @dataProvider data_enabled_through
	 *
	 * @param bool $through_core Whether to enable it with core's post API rather than the manager.
	 */
	public function test_a_redirect_back_from_the_trash_is_found_again( bool $through_core ): void {
		$post_id = $this->create_redirect( '/back-from-trash', 'https://example.com/destination' );
		wp_trash_post( $post_id );

		// A cold lookup, as a later request makes, so what it caches is the miss.
		$this->clear_lookup_cache( '/back-from-trash' );
		$this->assertNull( $this->resolver()->get_redirect_data( '/back-from-trash' ) );
		$this->assertSame( 0, wp_cache_get( CachingRedirectRepository::cache_key( md5( '/back-from-trash' ) ), CachingRedirectRepository::CACHE_GROUP ) );

		wp_untrash_post( $post_id );
		if ( $through_core ) {
			wp_publish_post( $post_id );
		} else {
			$this->assertTrue( $this->manager()->enable( $post_id ) );
		}

		$this->assertSame( 'https://example.com/destination', $this->resolver()->get_redirect_data( '/back-from-trash' )['url'] ?? null );
	}

	/**
	 * Data provider: the ways a redirect is enabled.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function data_enabled_through(): array {
		return array(
			'the manager' => array( false ),
			'core'        => array( true ),
		);
	}
}
