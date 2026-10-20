<?php
/**
 * WordPress post type redirect query repository.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use WP_Query;

/**
 * Query repository implementation using WordPress custom post type.
 *
 * Handles listing, searching, and filtering redirects via WP_Query.
 * Separated from the command repository (PostTypeRedirectRepository)
 * following CQRS principles.
 */
final class PostTypeRedirectQueryRepository implements RedirectQueryRepositoryInterface {

	use RedirectPostMapper;

	/**
	 * LIKE pattern matching internal relative-path destinations.
	 */
	private const string PATH_LIKE = '/%';

	/**
	 * LIKE pattern matching external (absolute URL) destinations.
	 *
	 * Internal absolute URLs are normalized to relative paths on save (and by
	 * the v3 migration), so anything stored absolute is external.
	 */
	private const string EXTERNAL_LIKE = 'http%';

	/**
	 * Build the SQL WHERE fragment classifying redirects by destination kind.
	 *
	 * The single owner of the storage-level classification rule: post-ID
	 * destinations live in post_parent, URL destinations in post_excerpt.
	 * Used here for counting and by the admin list table's view filters.
	 *
	 * @param string $type Destination kind: 'post_id', 'path', or 'external'.
	 * @return string SQL fragment starting with ' AND ', or '' for an unknown kind.
	 */
	public static function destination_type_where( string $type ): string {
		$condition = self::destination_type_condition( $type );

		return '' === $condition ? '' : " AND $condition";
	}

	/**
	 * Build the bare SQL condition matching one destination kind.
	 *
	 * @param string $type Destination kind: 'post_id', 'path', or 'external'.
	 * @return string Prepared SQL condition, or '' for an unknown kind.
	 */
	private static function destination_type_condition( string $type ): string {
		global $wpdb;

		switch ( $type ) {
			case 'post_id':
				return "{$wpdb->posts}.post_parent > 0";
			case 'path':
				return $wpdb->prepare( "{$wpdb->posts}.post_excerpt LIKE %s", self::PATH_LIKE );
			case 'external':
				return $wpdb->prepare( "{$wpdb->posts}.post_excerpt LIKE %s", self::EXTERNAL_LIKE );
			default:
				return '';
		}
	}

	/**
	 * Find redirects matching the given criteria.
	 *
	 * Unreadable rows are included as corrupt Redirects (see
	 * Redirect::is_corrupt()), so the result count agrees with
	 * count_matching() and corrupt rows stay visible to listings and audits.
	 *
	 * The rows are read in one query and kept out of the object cache. Under
	 * a persistent object cache WP_Query otherwise splits the query and
	 * primes the post cache with every row, whatever cache_results says, so
	 * an export of the whole table would push the site's live entries out of
	 * memcache. Every caller is a listing that maps the rows and moves on,
	 * so a cached copy would never be read.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return Redirect[] Array of matching redirects.
	 */
	#[\Override]
	public function find_matching( RedirectCriteria $criteria ): array {
		$args                  = $this->build_query_args( $criteria );
		$args['cache_results'] = false;

		$query   = new WP_Query();
		$unsplit = static fn( bool $split, WP_Query $current ): bool => $current !== $query && $split;

		add_filter( 'split_the_query', $unsplit, 10, 2 );
		try {
			$query->query( $args );
		} finally {
			remove_filter( 'split_the_query', $unsplit, 10 );
		}

		return array_map(
			array( $this, 'map_post_to_redirect' ),
			$query->posts
		);
	}

	/**
	 * Find the IDs of the redirects find_matching() would return.
	 *
	 * An ID query is never split, and with cache_results off it stores
	 * nothing either.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return int[] The matching redirect IDs, in the same order.
	 */
	#[\Override]
	public function find_matching_ids( RedirectCriteria $criteria ): array {
		$args                  = $this->build_query_args( $criteria );
		$args['fields']        = 'ids';
		$args['cache_results'] = false;

		return ( new WP_Query( $args ) )->posts;
	}

	/**
	 * Count redirects matching the given criteria.
	 *
	 * @param RedirectCriteria $criteria The query criteria (limit/offset are ignored).
	 * @return int The total count of matching redirects.
	 */
	#[\Override]
	public function count_matching( RedirectCriteria $criteria ): int {
		// Build query args for counting (no pagination).
		$args = $this->build_query_args( $criteria );

		// For count, we just need the total without fetching all posts.
		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Find enabled and disabled redirects with an ID above a cursor, in ID order.
	 *
	 * Reads the rows directly rather than through WP_Query, which has no
	 * "ID greater than" argument. get_post() on a row sanitizes it without
	 * adding it to the object cache, where a pass over millions of rows would
	 * only evict the posts the site is actually serving.
	 *
	 * @param int $after_id Only rows with an ID above this.
	 * @param int $limit    Maximum rows to return.
	 * @return Redirect[] The redirects, lowest ID first.
	 */
	#[\Override]
	public function find_after_id( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset page over the primary key; WP_Query cannot express ID > n, and caching a full-table walk would evict live entries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft') AND ID > %d ORDER BY ID ASC LIMIT %d",
				PostType::POST_TYPE,
				$after_id,
				$limit
			)
		);

		return array_map(
			fn( object $row ): Redirect => $this->map_post_to_redirect( get_post( $row ) ),
			$rows
		);
	}

	/**
	 * Find enabled and disabled redirects whose destination is a given post.
	 *
	 * A post-ID destination is stored in post_parent, which is indexed, so
	 * this costs one index lookup however large the table is.
	 *
	 * @param int $post_id The destination post ID.
	 * @param int $limit   Maximum rows to return.
	 * @return Redirect[] The redirects, lowest ID first.
	 */
	#[\Override]
	public function find_by_destination_post( int $post_id, int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'post_parent'            => $post_id,
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( array( $this, 'map_post_to_redirect' ), $query->posts );
	}

	/**
	 * Get destination URLs for redirects pointing at external (absolute) URLs.
	 *
	 * Disabled redirects count, since re-enabling one needs its host allowed;
	 * the Trash does not.
	 *
	 * Pages by keyset: pass the last ID of one page as $after_id for the next,
	 * so each page costs the same however deep into the table it starts.
	 *
	 * @param int $after_id Return only redirects with an ID above this; 0 to start.
	 * @param int $limit    Maximum number of URLs to return.
	 * @return array<int, string> The destination URLs, keyed by redirect ID, lowest first.
	 */
	#[\Override]
	public function get_external_destination_urls( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk query for CLI reporting; WP_Query cannot filter on post_excerpt.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_excerpt FROM $wpdb->posts WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_excerpt LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				PostType::POST_TYPE,
				self::EXTERNAL_LIKE,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return array_column( $rows, 'post_excerpt', 'ID' );
	}

	/**
	 * Count redirects pointing at external (absolute) URLs, outside the Trash.
	 *
	 * @return int The total count.
	 */
	#[\Override]
	public function count_external_destinations(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk query for CLI reporting; WP_Query cannot filter on post_excerpt.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( ID ) FROM $wpdb->posts WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_excerpt LIKE %s",
				PostType::POST_TYPE,
				self::EXTERNAL_LIKE
			)
		);
	}

	/**
	 * Count active redirects grouped by destination kind.
	 *
	 * One scan of the table for all three counts, since the list screen asks
	 * on every load. A row can match more than one kind (a post ID with a
	 * path left in post_excerpt) and counts in each, as its list filters show it.
	 *
	 * The scan reads every row, since post_excerpt has no index, so the
	 * result is cached against the posts last-changed time: any post write,
	 * through core or the migration, moves it on and the next load recounts.
	 *
	 * @return array{post_id: int, path: int, external: int} Counts by kind.
	 */
	#[\Override]
	public function count_by_destination_type(): array {
		global $wpdb;

		$cache_key = CachingRedirectRepository::cache_key( 'destination-type-counts:' . wp_cache_get_last_changed( 'posts' ) );
		$cached    = wp_cache_get( $cache_key, CachingRedirectRepository::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post_id  = self::destination_type_condition( 'post_id' );
		$path     = self::destination_type_condition( 'path' );
		$external = self::destination_type_condition( 'external' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom count query; the conditions are prepared in destination_type_condition().
		$row = $wpdb->get_row(
			"SELECT COUNT( CASE WHEN $post_id THEN 1 END ) AS post_id, COUNT( CASE WHEN $path THEN 1 END ) AS path, COUNT( CASE WHEN $external THEN 1 END ) AS external FROM {$wpdb->posts} "
			. $wpdb->prepare( "WHERE post_type = %s AND post_status IN ('publish', 'draft')", PostType::POST_TYPE ),
			ARRAY_A
		);
		// phpcs:enable

		$counts = array(
			'post_id'  => (int) ( $row['post_id'] ?? 0 ),
			'path'     => (int) ( $row['path'] ?? 0 ),
			'external' => (int) ( $row['external'] ?? 0 ),
		);

		// A failed read is not cached as zero counts.
		if ( null !== $row ) {
			// Expires so the keys earlier last-changed times left behind go.
			wp_cache_set( $cache_key, $counts, CachingRedirectRepository::CACHE_GROUP, DAY_IN_SECONDS );
		}

		return $counts;
	}

	/**
	 * Build WP_Query arguments from criteria.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return array<string, mixed> The query arguments.
	 */
	private function build_query_args( RedirectCriteria $criteria ): array {
		$order_by = $criteria->order_by();
		$order    = $criteria->order();

		// Non-unique orderings need an ID tiebreaker: an import creates hundreds
		// of rows in the same second, and ties ordered arbitrarily by the
		// database let paging repeat one row and skip another. The admin list
		// does the same in ColumnsManager::handle_sorting().
		if ( ! in_array( $order_by, array( 'ID', 'rand' ), true ) ) {
			$order_by = array(
				$order_by => $order,
				'ID'      => $order,
			);
		}

		$args = array(
			'post_type'              => PostType::POST_TYPE,
			'post_status'            => $this->map_status_to_post_status( $criteria->status() ),
			'posts_per_page'         => $criteria->limit(),
			'offset'                 => $criteria->offset(),
			'orderby'                => $order_by,
			'order'                  => $order,
			// The mapper reads no meta or terms.
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		// Search source paths only; destinations live in post_excerpt.
		if ( $criteria->has_search() ) {
			$args['s']              = $criteria->search();
			$args['search_columns'] = array( 'post_title' );
		}

		// The background check's per-row flag, as the admin "Has issues" view
		// reads it. Only flagged rows carry the key, so the join stays
		// proportional to the flagged rows, not the table.
		$issues = $criteria->issues();
		if ( null !== $issues ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only flagged rows carry the key.
			$args['meta_key'] = AuditFlags::META_KEY;

			if ( 'any' !== $issues ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				$args['meta_value'] = $issues;
			}
		}

		// Add destination type filter.
		$dest_type = $criteria->destination_type();
		if ( 'post' === $dest_type ) {
			$args['post_parent__not_in'] = array( 0 );
		} elseif ( 'url' === $dest_type ) {
			$args['post_parent'] = 0;
		}

		return $args;
	}

	/**
	 * Map status filter to WordPress post status.
	 *
	 * @param string|null $status The status filter ('enabled', 'disabled', or null).
	 * @return string|string[] The post status(es).
	 */
	private function map_status_to_post_status( ?string $status ) { // phpcs:ignore NeutronStandard.Functions.TypeHint.NoReturnType -- Mixed return.
		if ( 'enabled' === $status ) {
			return 'publish';
		}

		if ( 'disabled' === $status ) {
			return 'draft';
		}

		// Return both publish and draft for 'any' or null.
		return array( 'publish', 'draft' );
	}
}
