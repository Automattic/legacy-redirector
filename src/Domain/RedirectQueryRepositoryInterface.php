<?php
/**
 * Redirect query repository interface.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Repository interface for querying redirects.
 *
 * Separate from RedirectRepositoryInterface following CQRS principles:
 * - RedirectRepositoryInterface handles commands (create, update, delete, find by ID/source)
 * - RedirectQueryRepositoryInterface handles queries (list, search, filter, count)
 *
 * This separation allows for different optimisations:
 * - Command repository can use caching for individual lookups
 * - Query repository can use efficient database queries for bulk operations
 */
interface RedirectQueryRepositoryInterface {

	/**
	 * Find redirects matching the given criteria.
	 *
	 * Unreadable rows are included as corrupt Redirects (see
	 * Redirect::is_corrupt()), so the result count agrees with
	 * count_matching().
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return Redirect[] Array of matching redirects.
	 */
	public function find_matching( RedirectCriteria $criteria ): array;

	/**
	 * Find a page of matching redirects, with the total that match.
	 *
	 * For callers that show both, in place of find_matching() followed by
	 * count_matching(), which would search twice.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return array{redirects: Redirect[], total: int} The page, and the count ignoring limit and offset.
	 */
	public function find_page( RedirectCriteria $criteria ): array;

	/**
	 * Find the IDs of the redirects find_matching() would return.
	 *
	 * @param RedirectCriteria $criteria The query criteria.
	 * @return int[] The matching redirect IDs, in the same order.
	 */
	public function find_matching_ids( RedirectCriteria $criteria ): array;

	/**
	 * Count redirects matching the given criteria.
	 *
	 * Useful for pagination - returns total count regardless of limit/offset.
	 *
	 * @param RedirectCriteria $criteria The query criteria (limit/offset are ignored).
	 * @return int The total count of matching redirects.
	 */
	public function count_matching( RedirectCriteria $criteria ): int;

	/**
	 * Find enabled and disabled redirects with an ID above a cursor, in ID order.
	 *
	 * A keyset page: it costs the same at any depth in the table, and rows
	 * deleted between pages cannot shift the next page the way an offset
	 * would. Unreadable rows are included as corrupt Redirects.
	 *
	 * @param int $after_id Only rows with an ID above this.
	 * @param int $limit    Maximum rows to return.
	 * @return Redirect[] The redirects, lowest ID first.
	 */
	public function find_after_id( int $after_id, int $limit ): array;

	/**
	 * Find enabled and disabled redirects whose destination is a given post.
	 *
	 * @param int $post_id The destination post ID.
	 * @param int $limit   Maximum rows to return.
	 * @return Redirect[] The redirects, lowest ID first.
	 */
	public function find_by_destination_post( int $post_id, int $limit ): array;

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
	public function get_external_destination_urls( int $after_id, int $limit ): array;

	/**
	 * Count redirects pointing at external (absolute) URLs, outside the Trash.
	 *
	 * @return int The total count.
	 */
	public function count_external_destinations(): int;

	/**
	 * Count active redirects grouped by destination kind.
	 *
	 * Counts published and draft redirects for each of: destinations that
	 * point at a post ID, internal relative paths, and external URLs
	 * (absolute URLs whose host is not this site's).
	 *
	 * @return array{post_id: int, path: int, external: int} Counts by kind.
	 */
	public function count_by_destination_type(): array;
}
