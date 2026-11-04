<?php
/**
 * Data upgrade integration tests for subdirectory multisite.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderMultisiteTest class.
 *
 * Version 1.x prefixed home_url() onto every source path before saving, so on
 * a subdirectory multisite it stored '/subsite/old-page'. Version 2.0 strips
 * the subsite prefix from incoming requests and looks up '/old-page', so those
 * redirects never match until they have been rewritten.
 *
 * The Upgrader reads that prefix from home_url(), not from anything multisite,
 * so the home-path logic itself is tested in UpgraderSubdirectoryTest, which
 * runs in every CI cell. This file keeps what only a real subsite shows: the
 * migrated redirect resolving there, and one site's upgrade leaving another's
 * alone.
 *
 * @group multisite
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class UpgraderMultisiteTest extends TestCase {

	/**
	 * The subsite's path segment, without slashes.
	 *
	 * The site is rolled back with WP_UnitTestCase's per-test transaction,
	 * so every test can use the same one.
	 */
	private const SUBSITE = 'subsite';

	/**
	 * The subsite ID.
	 *
	 * @var int
	 */
	private int $site_id;

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

		$this->site_id  = (int) self::factory()->blog->create( array( 'path' => '/' . self::SUBSITE . '/' ) );
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
	 * @param string     $path_within_subsite The path below the subsite, e.g. '/old-page'.
	 * @param string|int $destination         The destination URL, or the ID of the post to redirect to.
	 * @return int The created post ID.
	 */
	private function create_prefixed_redirect( string $path_within_subsite, string|int $destination = 'https://example.com/new' ): int {
		return $this->create_legacy_redirect( '/' . self::SUBSITE . $path_within_subsite, $destination );
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
		$this->create_prefixed_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$redirect_data = $this->resolver()->get_redirect_data( '/old-page' );

		$this->assertNotEmpty( $redirect_data, 'The migrated redirect should resolve for a subsite-relative request.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * Upgrading a subsite leaves another site's redirects and upgrade state alone.
	 *
	 * Each site upgrades its own tables on its own schedule, so the main site,
	 * still on 1.x data and mid-batch, neither holds the subsite up nor has
	 * its rows, version or lock touched.
	 *
	 * @return void
	 */
	public function test_upgrading_a_subsite_leaves_another_site_alone() {
		global $wpdb;

		switch_to_blog( get_main_site_id() );
		delete_option( Upgrader::VERSION_OPTION );
		$other_id = $this->create_legacy_redirect( '/old-page/' );
		$lock     = static fn(): ?string => $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpcom_legacy_redirector_upgrade_lock' ) );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wpcom_legacy_redirector_upgrade_lock',
				'option_value' => (string) time(),
				'autoload'     => 'off',
			)
		);
		$held     = $lock();
		restore_current_blog();

		$post_id = $this->create_prefixed_redirect( '/old-page' );
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertTrue( $result['complete'] );
		$this->assertSame( '/old-page', get_post( $post_id )->post_title );

		switch_to_blog( get_main_site_id() );
		$other = get_post( $other_id );
		$this->assertSame( '/old-page/', $other->post_title );
		$this->assertSame( 'draft', $other->post_status );
		$this->assertTrue( $this->upgrader->needs_upgrade() );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );
		$this->assertSame( $held, $lock() );
		restore_current_blog();
	}
}
