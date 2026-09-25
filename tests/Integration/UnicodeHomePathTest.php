<?php
/**
 * Lookup integration tests for a site whose home path contains non-ASCII characters.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * UnicodeHomePathTest class.
 *
 * Creation and lookup used to disagree about encoding whenever the site's home
 * path contained non-ASCII characters. SourceUrl decodes before it strips the
 * home path, so a source saved as a full URL was stored home-relative. The
 * resolver compared the still-percent-encoded request against the decoded home
 * path from home_url(), which never matched, so it looked the source up with
 * the prefix still attached. The redirect was stored under one key and
 * searched for under another, and never fired.
 *
 * Both halves now go through HomePath::make_relative(), which compares whole
 * path segments decoded. These tests drive the two halves against each other
 * rather than asserting either in isolation, because the defect was in their
 * disagreement and each half looked correct on its own.
 *
 * The site is put in a non-ASCII subdirectory by filtering home_url(), the
 * same way UpgraderSubdirectoryTest does it: multisite rejects such a path, so
 * this shape only arises for a single site installed in a directory like
 * example.com/日本/.
 *
 * @covers \Automattic\LegacyRedirector\Application\HomePath
 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class UnicodeHomePathTest extends TestCase {

	/**
	 * The non-ASCII subdirectory the site is pretending to live in.
	 */
	private const SUBDIR = '日本';

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'home_url', array( $this, 'filter_home_url' ) );
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
	 * Move home into a non-ASCII subdirectory.
	 *
	 * @param string $url The home URL.
	 * @return string The home URL with the subdirectory appended.
	 */
	public function filter_home_url( $url ): string {
		return untrailingslashit( $url ) . '/' . self::SUBDIR;
	}

	/**
	 * The percent-encoded request path for a path below the subdirectory.
	 *
	 * What a browser actually puts on the request line.
	 *
	 * @param string $path_within_site The path below the subdirectory, without a leading slash.
	 * @return string The encoded request path.
	 */
	private function encoded_request_path( string $path_within_site ): string {
		return '/' . rawurlencode( self::SUBDIR ) . '/' . rawurlencode( $path_within_site );
	}

	/**
	 * Test the home path survives being read back out of home_url().
	 *
	 * Everything below depends on this: if the home path comes back corrupted
	 * then nothing can match it, and the failure looks like a lookup bug.
	 *
	 * @return void
	 */
	public function test_home_path_is_read_back_intact(): void {
		$this->assertSame( '/' . self::SUBDIR, HomePath::current() );
	}

	/**
	 * Test a source saved as a full URL is stored relative to home.
	 *
	 * @return void
	 */
	public function test_full_url_source_is_stored_home_relative(): void {
		$full_url = home_url() . '/ページ';

		$this->assertSame(
			'/ページ',
			SourceUrl::from_string( $full_url, HomePath::current() )->path(),
			'The unicode home path should not survive into the stored source.'
		);
	}

	/**
	 * Test a redirect saved from a full URL fires for the request it names.
	 *
	 * The regression test for the defect: both halves, driven against each
	 * other. Before the fix the source was stored as '/ページ' and looked up
	 * as '/日本/ページ', and this returned null.
	 *
	 * @return void
	 */
	public function test_redirect_saved_from_a_full_url_resolves_for_its_own_request(): void {
		$this->create_redirect( home_url() . '/ページ', 'https://example.com/destination' );

		$data = $this->resolver()->get_redirect_data( $this->encoded_request_path( 'ページ' ) );

		$this->assertNotNull( $data, 'A full-URL source should resolve for the URL it was given as.' );
		$this->assertSame( 'https://example.com/destination', $data['url'] );
	}

	/**
	 * Test a redirect saved as a home-relative path resolves the same way.
	 *
	 * The other way an admin enters a source. It has to reach the same key as
	 * the full-URL form, or which of the two you typed decides whether the
	 * redirect works.
	 *
	 * @return void
	 */
	public function test_redirect_saved_as_a_relative_path_resolves_for_the_encoded_request(): void {
		$this->create_redirect( '/ページ', 'https://example.com/destination' );

		$data = $this->resolver()->get_redirect_data( $this->encoded_request_path( 'ページ' ) );

		$this->assertNotNull( $data, 'A relative source should resolve for the encoded request.' );
		$this->assertSame( 'https://example.com/destination', $data['url'] );
	}

	/**
	 * Test both source forms reach the same stored redirect.
	 *
	 * @return void
	 */
	public function test_full_url_and_relative_sources_are_the_same_redirect(): void {
		$first = $this->create_redirect( home_url() . '/ページ', 'https://example.com/destination' );

		$source = SourceUrl::from_string( '/ページ', HomePath::current() );

		$this->assertSame( $first, $this->repository()->find_by_source( $source )->id() );
	}

	/**
	 * Test a request outside the home path is not rebased into it.
	 *
	 * '/日本語' is not inside '/日本'. A byte-prefix match would cut it to
	 * '語' and fire whatever redirect happened to be stored there.
	 *
	 * @return void
	 */
	public function test_request_outside_the_home_path_is_left_alone(): void {
		$this->create_redirect( '/語', 'https://example.com/wrong' );

		$data = $this->resolver()->get_redirect_data( '/' . rawurlencode( '日本語' ) );

		$this->assertNull( $data, 'A path that merely shares the home path bytes must not be rebased.' );
	}

	/**
	 * Test a request for the site's own home page resolves to the root path.
	 *
	 * @return void
	 */
	public function test_request_for_the_home_page_resolves_to_the_root_path(): void {
		$this->create_redirect( '/', 'https://example.com/destination' );

		$data = $this->resolver()->get_redirect_data( '/' . rawurlencode( self::SUBDIR ) );

		$this->assertNotNull( $data, 'The subdirectory root should look up the stored root path.' );
		$this->assertSame( 'https://example.com/destination', $data['url'] );
	}

	/**
	 * Test an internal destination under the unicode home path is stored relative.
	 *
	 * InternalDestinationNormalizer shares the same home-path comparison, so
	 * it had the same blind spot: a destination entered as a full URL stayed
	 * absolute instead of being normalized to its relative form.
	 *
	 * @return void
	 */
	public function test_internal_destination_under_the_home_path_is_stored_relative(): void {
		$id = $this->create_redirect( '/ページ', home_url() . '/destination' );

		$redirect = $this->repository()->find_by_id( $id );

		$this->assertSame( '/destination', $redirect->destination()->as_url()->value() );
	}
}
