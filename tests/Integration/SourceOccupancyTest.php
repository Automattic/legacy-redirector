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
}
