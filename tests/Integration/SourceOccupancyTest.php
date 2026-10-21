<?php
/**
 * SourceOccupancy integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\SourceOccupancy;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Integration tests for finding published content at a redirect's source.
 *
 * @covers \Automattic\LegacyRedirector\Application\SourceOccupancy
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 */
final class SourceOccupancyTest extends TestCase {

	/**
	 * The post found at a source path, if any.
	 *
	 * @param string $path The source path.
	 * @return int|null The post ID, or null.
	 */
	private function occupant( string $path ): ?int {
		return ( new SourceOccupancy() )->occupying_post( SourceUrl::from_string( $path ) )?->ID;
	}

	/**
	 * Test a published page, nested or not, is found at its path.
	 */
	public function test_finds_a_published_page(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'about',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'team',
				'post_parent' => $parent,
			)
		);

		$this->assertSame( $parent, $this->occupant( '/about' ) );
		$this->assertSame( $child, $this->occupant( '/about/team/' ) );
		$this->assertSame( $parent, $this->occupant( '/about?utm_source=news' ) );
	}

	/**
	 * Test a page with a non-ASCII slug is found from either spelling of its path.
	 */
	public function test_finds_a_page_with_a_unicode_slug(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'привет',
			)
		);

		$this->assertSame( $page, $this->occupant( '/привет' ) );
		$this->assertSame( $page, $this->occupant( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82' ) );
	}

	/**
	 * Test a post behind a dated permalink is found through the permalink structure.
	 */
	public function test_finds_a_post_behind_a_dated_permalink(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%postname%/' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'spring-sale',
				'post_date'   => '2019-04-01 10:00:00',
			)
		);

		$this->assertSame( $post_id, $this->occupant( '/2019/04/spring-sale' ) );
	}

	/**
	 * Test content a visitor would get a 404 for is not counted.
	 *
	 * A draft, a private post or a post of a type that is not publicly
	 * viewable 404s for a visitor, so the redirect still fires there.
	 */
	public function test_ignores_content_a_visitor_cannot_see(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => 'draft-page',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
				'post_name'   => 'private-page',
			)
		);
		register_post_type( 'internal_note', array( 'public' => false ) );

		try {
			self::factory()->post->create(
				array(
					'post_type'   => 'internal_note',
					'post_status' => 'publish',
					'post_name'   => 'internal',
				)
			);

			$this->assertNull( $this->occupant( '/internal' ) );
		} finally {
			unregister_post_type( 'internal_note' );
		}

		$this->assertNull( $this->occupant( '/draft-page' ) );
		$this->assertNull( $this->occupant( '/private-page' ) );
		$this->assertNull( $this->occupant( '/nothing-here' ) );
	}

	/**
	 * Test the home page and reserved paths are never looked up as posts.
	 *
	 * A page is published at the reserved path, so only the is_reserved()
	 * guard keeps it from being reported.
	 */
	public function test_ignores_home_and_reserved_paths(): void {
		self::factory()->post->create( array( 'post_status' => 'pending' ) );
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'wp-admin',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'legacy',
				'post_parent' => $parent,
			)
		);

		$this->assertSame( $child, get_page_by_path( 'wp-admin/legacy' )?->ID, 'The page must exist at the reserved path.' );
		$this->assertNull( $this->occupant( '/' ) );
		$this->assertNull( $this->occupant( '/wp-admin/legacy' ) );
	}

	/**
	 * Test primed sources are answered without a slug query each, and a
	 * post published since priming is still found.
	 */
	public function test_primed_sources_need_no_slug_query_each(): void {
		global $wpdb;

		$page      = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'primed-page',
			)
		);
		$paths     = array( '/primed-page/', '/primed-nothing', '/wp-admin/primed', '/' );
		$sources   = array_map( array( SourceUrl::class, 'from_string' ), $paths );
		$occupancy = new SourceOccupancy();

		$occupancy->prime( $sources );

		$probes = array();
		$log    = static function ( string $query ) use ( &$probes, $wpdb ): string {
			if ( str_contains( $query, "FROM {$wpdb->posts} WHERE post_name = " ) ) {
				$probes[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $log );
		$found = array_map( static fn( SourceUrl $source ): ?int => $occupancy->occupying_post( $source )?->ID, $sources );
		remove_filter( 'query', $log );

		$this->assertSame( array( $page, null, null, null ), $found );
		$this->assertSame( array(), $probes );

		$later = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'primed-nothing',
			)
		);

		$this->assertSame( $later, $occupancy->occupying_post( $sources[1] )?->ID );
	}

	/**
	 * Test a refused priming read is not taken as "nothing here": each
	 * source is probed on its own instead.
	 */
	public function test_a_refused_priming_read_falls_back_to_each_probe(): void {
		$page      = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'refused-page',
			)
		);
		$source    = SourceUrl::from_string( '/refused-page/' );
		$occupancy = new SourceOccupancy();

		// An emptied query is refused before wpdb clears its last error.
		$refuse = static fn( string $query ): string => str_contains( $query, 'SELECT DISTINCT post_name' ) ? '' : $query;
		add_filter( 'query', $refuse );
		$occupancy->prime( array( $source ) );
		remove_filter( 'query', $refuse );

		$this->assertSame( $page, $occupancy->occupying_post( $source )?->ID );
	}

	/**
	 * Test a stored name the collation matches but its lowercase spelling
	 * does not (here a trailing space, from a write outside WordPress) is
	 * still found, by leaving the batch to the per-row probe.
	 */
	public function test_a_collation_only_match_falls_back_to_each_probe(): void {
		global $wpdb;

		$this->set_permalink_structure( '/%postname%/' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'padded-post',
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A write WordPress would never make, which is the point.
		$wpdb->update( $wpdb->posts, array( 'post_name' => 'padded-post ' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$source    = SourceUrl::from_string( '/padded-post' );
		$occupancy = new SourceOccupancy();
		$occupancy->prime( array( $source ) );

		$this->assertSame( $post_id, $occupancy->occupying_post( $source )?->ID );
	}
}
