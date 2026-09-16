<?php
/**
 * Data upgrade integration tests for a single site installed in a subdirectory.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderSubdirectoryTest class.
 *
 * A single site installed at example.com/blog has a non-empty home path, so
 * 1.x stored sources as '/blog/old-page' — it read the raw REQUEST_URI path
 * for both storage and matching, so the two agreed. 2.0 strips the home path
 * before lookup and searches for '/old-page', which never matches.
 *
 * The repath pass exists to reconcile exactly that, but it used to be gated on
 * is_multisite() and so never ran here, leaving every legacy redirect inert
 * while the migration reported success. These tests pin the predicate to the
 * home path instead.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class UpgraderSubdirectoryTest extends TestCase {

	/**
	 * The subdirectory the site is pretending to live in, without slashes.
	 */
	private const SUBDIR = 'blog';

	/**
	 * The upgrade routine under test.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'home_url', array( $this, 'filter_home_url' ) );

		$this->upgrader = new Upgrader();

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( 'wpcom_legacy_redirector_upgrade_started_gmt' );
		delete_option( 'wpcom_legacy_redirector_upgrade_cursor' );
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'home_url', array( $this, 'filter_home_url' ) );

		parent::tear_down();
	}

	/**
	 * Move home into a subdirectory, so the home path is non-empty.
	 *
	 * @param string $url The home URL.
	 * @return string The home URL with the subdirectory appended.
	 */
	public function filter_home_url( $url ): string {
		return untrailingslashit( $url ) . '/' . self::SUBDIR;
	}

	/**
	 * Create a redirect exactly as 1.x stored it on a subdirectory install.
	 *
	 * @param string $path_within_site The path below the subdirectory, e.g. '/old-page'.
	 * @return int The created post ID.
	 */
	private function create_legacy_redirect( string $path_within_site ): int {
		$prefixed_path = '/' . self::SUBDIR . $path_within_site;

		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $prefixed_path ),
				'post_title'   => $prefixed_path,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * The home path is stripped and the source is rehashed, on a single site.
	 *
	 * @return void
	 */
	public function test_home_path_is_stripped_from_legacy_source_on_single_site(): void {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( '/old-page', $post->post_title, 'The home path should have been stripped.' );
		$this->assertSame( md5( '/old-page' ), $post->post_name, 'The source hash should match the rewritten path.' );
		$this->assertSame( 'publish', $post->post_status, 'The redirect should also have been published.' );
		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( 1, $result['published'] );
	}

	/**
	 * After migrating, the redirect resolves for the URL a visitor requests.
	 *
	 * This is the assertion the old is_multisite() gate failed: publishing
	 * alone left the redirect stored under a key no request ever produces.
	 *
	 * @return void
	 */
	public function test_migrated_redirect_is_reachable_by_lookup(): void {
		$this->create_legacy_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$redirect_data = $this->resolver()->get_redirect_data( '/' . self::SUBDIR . '/old-page' );

		$this->assertNotEmpty( $redirect_data, 'The migrated redirect should resolve for the real request path.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A unicode legacy source is repathed and stays reachable.
	 *
	 * Upgrader::strip_home_prefix() slices by byte offset, so a multibyte path
	 * would be cut mid-sequence if the offset were ever computed in characters
	 * - producing a post_title that is not valid UTF-8 and a hash no request
	 * can match. The rehash has to agree with what SourceUrl produces from the
	 * percent-encoded request a browser actually sends.
	 *
	 * @return void
	 */
	public function test_unicode_legacy_source_is_repathed_and_reachable(): void {
		$path    = '/привет-🎉';
		$post_id = $this->create_legacy_redirect( $path );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( $path, $post->post_title, 'The home path should have been stripped without damaging the unicode.' );
		$this->assertSame( md5( $path ), $post->post_name, 'The source hash should match the rewritten unicode path.' );
		$this->assertSame( 1, $result['repathed'] );

		$redirect_data = $this->resolver()->get_redirect_data( '/' . self::SUBDIR . '/' . rawurlencode( 'привет-🎉' ) );

		$this->assertNotEmpty( $redirect_data, 'The migrated unicode redirect should resolve for the encoded request path.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A unicode path that only resembles the home prefix is left alone.
	 *
	 * The subdirectory is ASCII, but the byte-prefix test still has to hold a
	 * segment boundary when the rest of the path is not.
	 *
	 * @return void
	 */
	public function test_unicode_path_outside_the_home_prefix_is_not_repathed(): void {
		$prefixed = '/' . self::SUBDIR . 'ging/привет';

		$post_id = (int) wp_insert_post(
			array(
				'post_name'    => md5( $prefixed ),
				'post_title'   => $prefixed,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( $prefixed, $post->post_title, 'A path outside the home prefix should keep its title.' );
		$this->assertSame( md5( $prefixed ), $post->post_name );
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * The dry run reports the same work the real run would do.
	 *
	 * @return void
	 */
	public function test_dry_run_counts_the_repath_on_single_site(): void {
		$this->create_legacy_redirect( '/old-page' );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['to_repath'], 'The dry run should count the repath it would perform.' );
	}
}
