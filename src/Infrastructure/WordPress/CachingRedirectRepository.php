<?php
/**
 * Caching redirect repository decorator.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Repository decorator that adds caching to redirect lookups.
 *
 * Uses WordPress object cache (wp_cache_*) to cache redirect post IDs.
 * The full Redirect entity is not cached, only the ID mapping.
 *
 * A cache entry answers "which post holds this source", enabled or disabled, and 0
 * means no post holds it at all. find_by_source() applies its publish-only
 * filter to the loaded redirect, never to the cached ID, so the entry stays
 * usable by management lookups that must see disabled redirects.
 *
 * Cache invalidation:
 * - save() invalidates the cache for the source URL
 * - delete() invalidates the cache for the source URL
 * - PostType::invalidate_lookup_cache() covers core's own trash, restore,
 *   status changes and deletes, which bypass this class
 */
final class CachingRedirectRepository implements RedirectRepositoryInterface {

	/**
	 * The cache group for redirect lookups.
	 *
	 * Version suffix allows cache busting when schema changes.
	 */
	public const string CACHE_GROUP = 'vip-legacy-redirect-3';

	/**
	 * Expiry, in seconds, for negative ("no redirect exists") cache entries.
	 *
	 * Positive entries are cached indefinitely and explicitly invalidated on
	 * save() and delete(). Negative entries cannot be invalidated that way,
	 * because any 404 URL a visitor requests creates one, so they expire
	 * instead. Without this, arbitrary 404 traffic would fill the object cache
	 * permanently and evict useful entries.
	 */
	public const int NEGATIVE_CACHE_TTL = 300;

	/**
	 * The inner repository to delegate to.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $inner;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $inner The repository to wrap with caching.
	 */
	public function __construct( RedirectRepositoryInterface $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Find a redirect by its source URL.
	 *
	 * Resolves the ID through get_id_by_source(), then applies the publish-only
	 * filter to the loaded redirect. Filtering after the cache rather than
	 * before it is deliberate: the cached ID is the answer to "which post holds
	 * this source", which is status-agnostic and shared with get_id_by_source().
	 * Caching the filtered result instead would store 0 for a disabled or
	 * corrupt redirect, and management lookups reading that entry would
	 * conclude no redirect exists while the repository's own duplicate guard
	 * still saw one.
	 *
	 * @param SourceUrl $source The source URL to find.
	 * @return Redirect|null The redirect if found and active, null otherwise.
	 */
	#[\Override]
	public function find_by_source( SourceUrl $source ): ?Redirect {
		$post_id = $this->get_id_by_source( $source );

		if ( 0 === $post_id ) {
			return null;
		}

		$redirect = $this->inner->find_by_id( $post_id );

		if ( null === $redirect ) {
			// Post no longer exists. Another row may still hold the source, so the
			// next lookup asks the database rather than finding a cached 0.
			$this->invalidate_cache( $source );
			return null;
		}

		// Only return if active (published) and readable.
		if ( ! $redirect->is_active() || $redirect->is_corrupt() ) {
			return null;
		}

		return $redirect;
	}

	/**
	 * Find a redirect by its ID.
	 *
	 * ID lookups are not cached as they are less frequent.
	 *
	 * @param int $id The redirect ID.
	 * @return Redirect|null The redirect if found, null otherwise.
	 */
	#[\Override]
	public function find_by_id( int $id ): ?Redirect {
		return $this->inner->find_by_id( $id );
	}

	/**
	 * Check if a redirect exists for the given source URL.
	 *
	 * @param SourceUrl $source The source URL to check.
	 * @return bool True if a redirect exists.
	 */
	#[\Override]
	public function exists( SourceUrl $source ): bool {
		return $this->inner->exists( $source );
	}

	/**
	 * Save a redirect and invalidate cache.
	 *
	 * @param Redirect $redirect The redirect to save.
	 * @return Redirect The saved redirect with ID populated.
	 */
	#[\Override]
	public function save( Redirect $redirect ): Redirect {
		// On updates, invalidate the previously stored source too: if the
		// source changed, its positive cache entry would otherwise keep
		// serving the redirect indefinitely.
		if ( $redirect->is_persisted() ) {
			$existing = $this->inner->find_by_id( $redirect->id() );
			if ( null !== $existing && ! $existing->source()->equals( $redirect->source() ) ) {
				$this->invalidate_cache( $existing->source() );
			}
		}

		$this->invalidate_cache( $redirect->source() );

		$saved = $this->inner->save( $redirect );

		// Pre-warm cache with the new ID.
		wp_cache_set( self::cache_key( $saved->source()->hash() ), $saved->id(), self::CACHE_GROUP );

		return $saved;
	}

	/**
	 * Delete a redirect and invalidate cache.
	 *
	 * @param Redirect $redirect The redirect to delete.
	 * @return bool True if deleted successfully.
	 */
	#[\Override]
	public function delete( Redirect $redirect ): bool {
		$result = $this->inner->delete( $redirect );

		// Not cached as 0: a duplicate the 2.0 migration disabled shares its
		// source with the live redirect, which still holds the key.
		if ( $result ) {
			$this->invalidate_cache( $redirect->source() );
		}

		return $result;
	}

	/**
	 * Get the ID of a redirect for a source URL.
	 *
	 * Uses cache when available.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return int The redirect ID, or 0 if not found.
	 */
	#[\Override]
	public function get_id_by_source( SourceUrl $source ): int {
		$cache_key = self::cache_key( $source->hash() );
		$post_id   = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $post_id ) {
			return (int) $post_id;
		}

		$post_id = $this->inner->get_id_by_source( $source );
		wp_cache_add( $cache_key, $post_id, self::CACHE_GROUP, 0 === $post_id ? self::NEGATIVE_CACHE_TTL : 0 );

		return $post_id;
	}

	/**
	 * Get the IDs of the redirects marked as duplicates of a redirect.
	 *
	 * Not cached: only management commands ask.
	 *
	 * @param int $id The live redirect's ID.
	 * @return array<int, int> The duplicates' IDs, in ascending order.
	 */
	#[\Override]
	public function duplicate_ids( int $id ): array {
		return $this->inner->duplicate_ids( $id );
	}

	/**
	 * Invalidate the cache for a source URL.
	 *
	 * @param SourceUrl $source The source URL to invalidate.
	 */
	private function invalidate_cache( SourceUrl $source ): void {
		wp_cache_delete( self::cache_key( $source->hash() ), self::CACHE_GROUP );
	}

	/**
	 * Get the cache key for a source hash.
	 *
	 * Includes blog ID prefix to prevent cross-site cache contamination in multisite.
	 *
	 * Public and static because the key format has to have exactly one owner:
	 * anything that invalidates a lookup entry without holding a SourceUrl -
	 * the upgrade routine works from stored post_name hashes - must build the
	 * key the same way, or it silently deletes nothing.
	 *
	 * @param string $hash The MD5 hash of the normalized source path.
	 * @return string The cache key.
	 */
	public static function cache_key( string $hash ): string {
		return sprintf( '%d:%s', get_current_blog_id(), $hash );
	}
}
