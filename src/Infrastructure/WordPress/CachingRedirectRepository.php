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
 * Cache invalidation:
 * - save() invalidates the cache for the source URL
 * - delete() invalidates the cache for the source URL
 */
final class CachingRedirectRepository implements RedirectRepositoryInterface {

	/**
	 * The cache group for redirect lookups.
	 *
	 * Version suffix allows cache busting when schema changes.
	 */
	public const CACHE_GROUP = 'vip-legacy-redirect-3';

	/**
	 * Expiry, in seconds, for negative ("no redirect exists") cache entries.
	 *
	 * Positive entries are cached indefinitely and explicitly invalidated on
	 * save() and delete(). Negative entries cannot be invalidated that way,
	 * because any 404 URL a visitor requests creates one, so they expire
	 * instead. Without this, arbitrary 404 traffic would fill the object cache
	 * permanently and evict useful entries.
	 */
	public const NEGATIVE_CACHE_TTL = 300;

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
	 * Uses cached post ID if available, otherwise delegates to inner repository.
	 *
	 * @param SourceUrl $source The source URL to find.
	 * @return Redirect|null The redirect if found and active, null otherwise.
	 */
	public function find_by_source( SourceUrl $source ): ?Redirect {
		$cache_key = $this->get_cache_key( $source );
		$post_id   = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false === $post_id ) {
			// Cache miss - get from inner repository.
			$redirect = $this->inner->find_by_source( $source );
			$post_id  = $redirect ? $redirect->id() : 0;
			wp_cache_add( $cache_key, $post_id, self::CACHE_GROUP, 0 === $post_id ? self::NEGATIVE_CACHE_TTL : 0 );

			return $redirect;
		}

		// Cache hit - 0 means "known to not exist".
		if ( 0 === $post_id || 0 === (int) $post_id ) {
			return null;
		}

		// Load the full redirect by cached ID.
		$redirect = $this->inner->find_by_id( (int) $post_id );

		if ( null === $redirect ) {
			// Post no longer exists - update cache.
			wp_cache_set( $cache_key, 0, self::CACHE_GROUP, self::NEGATIVE_CACHE_TTL );
			return null;
		}

		// Only return if active (published).
		if ( ! $redirect->is_active() ) {
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
	public function find_by_id( int $id ): ?Redirect {
		return $this->inner->find_by_id( $id );
	}

	/**
	 * Check if a redirect exists for the given source URL.
	 *
	 * @param SourceUrl $source The source URL to check.
	 * @return bool True if a redirect exists.
	 */
	public function exists( SourceUrl $source ): bool {
		return $this->inner->exists( $source );
	}

	/**
	 * Save a redirect and invalidate cache.
	 *
	 * @param Redirect $redirect The redirect to save.
	 * @return Redirect The saved redirect with ID populated.
	 */
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
		wp_cache_set( $this->get_cache_key( $saved->source() ), $saved->id(), self::CACHE_GROUP );

		return $saved;
	}

	/**
	 * Delete a redirect and invalidate cache.
	 *
	 * @param Redirect $redirect The redirect to delete.
	 * @return bool True if deleted successfully.
	 */
	public function delete( Redirect $redirect ): bool {
		$this->invalidate_cache( $redirect->source() );

		$result = $this->inner->delete( $redirect );

		if ( $result ) {
			// Mark as deleted in cache.
			wp_cache_set( $this->get_cache_key( $redirect->source() ), 0, self::CACHE_GROUP, self::NEGATIVE_CACHE_TTL );
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
	public function get_id_by_source( SourceUrl $source ): int {
		$cache_key = $this->get_cache_key( $source );
		$post_id   = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $post_id ) {
			return (int) $post_id;
		}

		$post_id = $this->inner->get_id_by_source( $source );
		wp_cache_add( $cache_key, $post_id, self::CACHE_GROUP, 0 === $post_id ? self::NEGATIVE_CACHE_TTL : 0 );

		return $post_id;
	}

	/**
	 * Invalidate the cache for a source URL.
	 *
	 * @param SourceUrl $source The source URL to invalidate.
	 */
	private function invalidate_cache( SourceUrl $source ): void {
		wp_cache_delete( $this->get_cache_key( $source ), self::CACHE_GROUP );
	}

	/**
	 * Get the cache key for a source URL.
	 *
	 * Includes blog ID prefix to prevent cross-site cache contamination in multisite.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return string The cache key.
	 */
	private function get_cache_key( SourceUrl $source ): string {
		return sprintf( '%d:%s', get_current_blog_id(), $source->hash() );
	}
}
