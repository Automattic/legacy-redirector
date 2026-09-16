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
	 * Find redirects matching the given criteria.
	 *
	 * Unreadable rows are included as corrupt Redirects (see
	 * Redirect::is_corrupt()), so the result count agrees with
	 * count_matching() and corrupt rows stay visible to listings and audits.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return Redirect[] Array of matching redirects.
	 */
	public function find_matching( RedirectCriteria $criteria ): array {
		$query = new WP_Query( $this->build_query_args( $criteria ) );

		return array_map(
			array( $this, 'map_post_to_redirect' ),
			$query->posts
		);
	}

	/**
	 * Count redirects matching the given criteria.
	 *
	 * @param RedirectCriteria $criteria The query criteria (limit/offset are ignored).
	 * @return int The total count of matching redirects.
	 */
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
	 * Get destination URLs for redirects pointing at external (absolute) URLs.
	 *
	 * @param int $limit  Maximum number of URLs to return.
	 * @param int $offset Number of URLs to skip.
	 * @return string[] The destination URLs.
	 */
	public function get_external_destination_urls( int $limit, int $offset ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk query for CLI reporting; WP_Query cannot filter on post_excerpt.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_excerpt FROM $wpdb->posts WHERE post_type = %s AND post_excerpt LIKE %s ORDER BY ID ASC LIMIT %d, %d",
				PostType::POST_TYPE,
				'http%',
				$offset,
				$limit
			)
		);
	}

	/**
	 * Count redirects pointing at external (absolute) URLs.
	 *
	 * @return int The total count.
	 */
	public function count_external_destinations(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk query for CLI reporting; WP_Query cannot filter on post_excerpt.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( ID ) FROM $wpdb->posts WHERE post_type = %s AND post_excerpt LIKE %s",
				PostType::POST_TYPE,
				'http%'
			)
		);
	}

	/**
	 * Count active redirects grouped by destination kind.
	 *
	 * @return array{post_id: int, path: int, external: int} Counts by kind.
	 */
	public function count_by_destination_type(): array {
		global $wpdb;

		$post_type = PostType::POST_TYPE;

		// Count redirects to post IDs (post_parent > 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom count query.
		$post_id_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_parent > 0",
				$post_type
			)
		);

		// Count internal path redirects (relative paths starting with /).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom count query.
		$path_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_excerpt LIKE %s",
				$post_type,
				'/%'
			)
		);

		// Count external redirects. Internal absolute URLs are normalised to
		// relative paths on save (and by the v3 migration), so anything stored
		// absolute is external.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom count query.
		$external_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_excerpt LIKE %s",
				$post_type,
				'http%'
			)
		);

		return array(
			'post_id'  => $post_id_count,
			'path'     => $path_count,
			'external' => $external_count,
		);
	}

	/**
	 * Build WP_Query arguments from criteria.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return array<string, mixed> The query arguments.
	 */
	private function build_query_args( RedirectCriteria $criteria ): array {
		$args = array(
			'post_type'      => PostType::POST_TYPE,
			'post_status'    => $this->map_status_to_post_status( $criteria->status() ),
			'posts_per_page' => $criteria->limit(),
			'offset'         => $criteria->offset(),
			'orderby'        => $criteria->order_by(),
			'order'          => $criteria->order(),
		);

		// Add search filter.
		if ( $criteria->has_search() ) {
			$args['s'] = $criteria->search();
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
