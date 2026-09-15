<?php
/**
 * Data upgrade integration tests for subdirectory multisite.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderMultisiteTest class.
 *
 * Version 1.x prefixed home_url() onto every source path before saving, so on
 * a subdirectory multisite it stored '/subsite1/old-page'. Version 2.0 strips
 * the subsite prefix from incoming requests and looks up '/old-page', so those
 * redirects never match until they have been rewritten.
 *
 * @group multisite
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class UpgraderMultisiteTest extends TestCase {

	/**
	 * The subsite ID.
	 *
	 * @var int
	 */
	private int $site_id;

	/**
	 * The subsite's path segment, without slashes.
	 *
	 * Unique per test: the shared Integration TestCase does not call
	 * parent::set_up(), so WP_UnitTestCase never opens its rollback
	 * transaction and created sites persist for the whole run.
	 *
	 * @var string
	 */
	private string $subsite;

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

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required' );
		}

		static $counter = 0;
		++$counter;

		$this->subsite  = 'subsite' . $counter . substr( md5( (string) microtime( true ) ), 0, 6 );
		$this->site_id  = (int) self::factory()->blog->create( array( 'path' => '/' . $this->subsite . '/' ) );
		$this->upgrader = new Upgrader();

		switch_to_blog( $this->site_id );

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
		if ( is_multisite() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	/**
	 * Create a redirect exactly as 1.x stored it on a subdirectory subsite.
	 *
	 * @param string $path_within_subsite The path below the subsite, e.g. '/old-page'.
	 * @return int The created post ID.
	 */
	private function create_legacy_redirect( string $path_within_subsite ): int {
		$network_absolute_path = '/' . $this->subsite . $path_within_subsite;

		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $network_absolute_path ),
				'post_title'   => $network_absolute_path,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * Create a redirect already stored in the subsite-relative 2.0 form.
	 *
	 * @param string $path The subsite-relative path.
	 * @return int The created post ID.
	 */
	private function create_relative_redirect( string $path ): int {
		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $path ),
				'post_title'   => $path,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * The subsite prefix is stripped and the source is rehashed.
	 *
	 * @return void
	 */
	public function test_subsite_prefix_is_stripped_from_legacy_source() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( '/old-page', $post->post_title, 'The subsite prefix should have been stripped.' );
		$this->assertSame( md5( '/old-page' ), $post->post_name, 'The source hash should match the rewritten path.' );
		$this->assertSame( 'publish', $post->post_status, 'The redirect should also have been published.' );
		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( 1, $result['published'] );
	}

	/**
	 * After migrating, the redirect is found by the path a visitor requests.
	 *
	 * This is the assertion that would have caught the regression: publishing
	 * alone leaves the redirect stored under a key no request ever produces.
	 *
	 * @return void
	 */
	public function test_migrated_redirect_is_reachable_by_lookup() {
		$this->create_legacy_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$redirect_data = $this->resolver()->get_redirect_data( '/old-page' );

		$this->assertNotEmpty( $redirect_data, 'The migrated redirect should resolve for a subsite-relative request.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A path that already omits the prefix is left untouched.
	 *
	 * @return void
	 */
	public function test_already_relative_source_is_not_rewritten() {
		$post_id = $this->create_relative_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * A prefixed source written under 2.0 is never rewritten by a re-walk.
	 *
	 * On a subsite at /subsite1, a stored '/subsite1/old-page' created under
	 * 2.0 is indistinguishable from a deliberate redirect for the real URL
	 * /subsite1/subsite1/old-page, so the repath pass must leave it alone once
	 * the site's data is past the 1.x boundary.
	 *
	 * @return void
	 */
	public function test_prefixed_source_written_under_2_0_is_not_rewritten() {
		update_option( Upgrader::VERSION_OPTION, 2 );
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame(
			'/' . $this->subsite . '/old-page',
			get_post( $post_id )->post_title,
			'A prefixed source under 2.0 may be a deliberate double-prefix redirect and must not be touched.'
		);
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * Re-walking the set does not republish a redirect someone disabled.
	 *
	 * From 2.0 on, 'draft' means "deliberately disabled". The publish pass is
	 * for 1.x data only, so a later version bump must leave those alone even
	 * though it re-walks every redirect.
	 *
	 * @return void
	 */
	public function test_deliberately_disabled_redirect_survives_a_later_upgrade() {
		update_option( Upgrader::VERSION_OPTION, 3 );

		// Dated so that no redirect can be older than the run: the separate
		// "touched since the upgrade began" guard is taken out of play, leaving
		// the version gate as the only thing standing between this redirect and
		// being republished.
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2100-01-01 00:00:00' );

		$post_id = $this->create_relative_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post( $post_id )->post_status, 'A disabled redirect should stay disabled.' );
		$this->assertSame( 0, $result['published'] );
	}

	/**
	 * A rewrite that would collide is skipped and reported.
	 *
	 * @return void
	 */
	public function test_colliding_rewrite_is_skipped_and_reported() {
		$prefixed_id = $this->create_legacy_redirect( '/old-page' );
		$this->create_relative_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame(
			'/' . $this->subsite . '/old-page',
			get_post( $prefixed_id )->post_title,
			'A colliding redirect should be left exactly as it was.'
		);
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'would collide', $result['conflicts'][0] );
	}
}
