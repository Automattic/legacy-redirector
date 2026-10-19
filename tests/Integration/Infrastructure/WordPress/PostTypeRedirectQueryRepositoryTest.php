<?php
/**
 * PostTypeRedirectQueryRepository integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for PostTypeRedirectQueryRepository.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 */
final class PostTypeRedirectQueryRepositoryTest extends TestCase {

	/**
	 * Paging by date visits every row exactly once, newest ID first, even
	 * when an import gave them all the same timestamp.
	 */
	public function test_paging_by_date_covers_every_row_despite_identical_dates(): void {
		$ids = $this->create_redirects_with_one_date( 5 );

		$seen = array();
		for ( $offset = 0; $offset < 5; $offset++ ) {
			$page = $this->query_repository()->find_matching( new RedirectCriteria( null, null, null, 'date', 'DESC', 1, $offset ) );
			$seen = array_merge( $seen, array_map( static fn ( Redirect $redirect ): ?int => $redirect->id(), $page ) );
		}

		rsort( $ids );
		$this->assertSame( $ids, $seen );
	}

	/**
	 * Ordering by ID needs no tiebreaker and is passed through as asked.
	 */
	public function test_ordering_by_id_is_passed_through(): void {
		$ids = $this->create_redirects_with_one_date( 3 );

		$found = $this->query_repository()->find_matching( new RedirectCriteria( null, null, null, 'ID', 'ASC' ) );

		$this->assertSame( $ids, array_map( static fn ( Redirect $redirect ): ?int => $redirect->id(), $found ) );
	}

	/**
	 * Destination type counts are exact, cover disabled redirects but not the
	 * Trash, count a row in every type it matches, and take one query.
	 */
	public function test_count_by_destination_type_counts_every_type_in_one_query(): void {
		global $wpdb;

		$post_id = self::factory()->post->create();

		$this->insert_redirect_post(
			array(
				'post_parent'  => $post_id,
				'post_excerpt' => '',
			)
		);
		$this->insert_redirect_post( array( 'post_excerpt' => '/path' ) );
		$this->insert_redirect_post( array( 'post_excerpt' => 'https://example.com/external' ) );
		$this->insert_redirect_post(
			array(
				'post_status'  => 'draft',
				'post_excerpt' => '/disabled-path',
			)
		);
		$this->insert_redirect_post(
			array(
				'post_status'  => 'trash',
				'post_excerpt' => 'https://example.com/trashed',
			)
		);
		$this->insert_redirect_post(
			array(
				'post_parent'  => $post_id,
				'post_excerpt' => '/overlap',
			)
		);

		$queries = $wpdb->num_queries;
		$counts  = $this->query_repository()->count_by_destination_type();
		$queries = $wpdb->num_queries - $queries;

		$this->assertSame(
			array(
				'post_id'  => 2,
				'path'     => 3,
				'external' => 1,
			),
			$counts
		);

		foreach ( $counts as $type => $count ) {
			$this->assertSame( $count, $this->count_rows_filtered_to( $type ), "The $type count must match its list filter." );
		}

		$this->assertSame( 1, $queries, 'The counts must take one query.' );
	}

	/**
	 * Count the live rows the list screen's filter for a destination type shows.
	 *
	 * @param string $type Destination kind: 'post_id', 'path', or 'external'.
	 * @return int The row count.
	 */
	private function count_rows_filtered_to( string $type ): int {
		$where = static fn ( string $sql ): string => $sql . PostTypeRedirectQueryRepository::destination_type_where( $type );

		add_filter( 'posts_where', $where );
		$query = new \WP_Query(
			array(
				'post_type'      => PostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		remove_filter( 'posts_where', $where );

		return count( $query->posts );
	}

	/**
	 * Create redirects that all share one post_date, as a bulk import does.
	 *
	 * @param int $count How many to create.
	 * @return int[] Their IDs, in creation order.
	 */
	private function create_redirects_with_one_date( int $count ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$redirect_id = $this->create_redirect( "/same-second-$i", '/target' );
			wp_update_post(
				array(
					'ID'            => $redirect_id,
					'post_date'     => '2026-01-01 00:00:00',
					'post_date_gmt' => '2026-01-01 00:00:00',
					'edit_date'     => true,
				)
			);
			$ids[] = $redirect_id;
		}

		return $ids;
	}

	/**
	 * Test the keyset page returns enabled and disabled rows above the cursor, in ID order.
	 */
	public function test_find_after_id_pages_by_id(): void {
		$first    = $this->create_redirect( '/keyset-a', '/target-a' );
		$second   = $this->create_redirect( '/keyset-b', '/target-b' );
		$disabled = $this->create_redirect( '/keyset-c', '/target-c' );
		$trashed  = $this->create_redirect( '/keyset-d', '/target-d' );
		$last     = $this->create_redirect( '/keyset-e', '/target-e' );
		wp_update_post(
			array(
				'ID'          => $disabled,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $trashed );

		$ids = static fn( array $redirects ): array => array_map( static fn( $redirect ) => $redirect->id(), $redirects );

		$this->assertSame( array( $first, $second ), $ids( $this->query_repository()->find_after_id( 0, 2 ) ) );
		$this->assertSame( array( $disabled, $last ), $ids( $this->query_repository()->find_after_id( $second, 10 ) ) );
		$this->assertSame( array(), $this->query_repository()->find_after_id( $last, 10 ) );
	}

	/**
	 * Test external destinations page by ID with no row skipped or repeated
	 * across a page boundary, counting disabled rows but not the Trash or paths.
	 */
	public function test_external_destination_urls_page_by_id(): void {
		$expected = array();
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $letter ) {
			$id              = $this->insert_redirect_post( array( 'post_excerpt' => "https://$letter.example/" ) );
			$expected[ $id ] = "https://$letter.example/";

			$this->insert_redirect_post( array( 'post_excerpt' => "/path-$letter" ) );
		}
		$disabled              = $this->insert_redirect_post(
			array(
				'post_status'  => 'draft',
				'post_excerpt' => 'https://disabled.example/',
			)
		);
		$expected[ $disabled ] = 'https://disabled.example/';
		$this->insert_redirect_post(
			array(
				'post_status'  => 'trash',
				'post_excerpt' => 'https://trashed.example/',
			)
		);

		$seen     = array();
		$after_id = 0;
		do {
			$page = $this->query_repository()->get_external_destination_urls( $after_id, 4 );
			$this->assertEmpty( array_intersect_key( $seen, $page ), 'No row may appear on two pages.' );
			$seen    += $page;
			$after_id = (int) array_key_last( $page );
			$fetched  = count( $page );
		} while ( 4 === $fetched );

		$this->assertSame( $expected, $seen );
	}

	/**
	 * Test the destination lookup finds enabled and disabled redirects to a post, and nothing else.
	 */
	public function test_find_by_destination_post(): void {
		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$enabled  = $this->create_redirect( '/to-post-a', $post_id );
		$disabled = $this->create_redirect( '/to-post-b', $post_id );
		$this->create_redirect( '/to-other', $other );
		$this->create_redirect( '/to-path', '/somewhere' );
		wp_update_post(
			array(
				'ID'          => $disabled,
				'post_status' => 'draft',
			)
		);

		$found = $this->query_repository()->find_by_destination_post( $post_id, 10 );

		$this->assertSame( array( $enabled, $disabled ), array_map( static fn( $redirect ) => $redirect->id(), $found ) );
		$this->assertCount( 1, $this->query_repository()->find_by_destination_post( $post_id, 1 ) );
	}
}
