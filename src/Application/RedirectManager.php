<?php
/**
 * Redirect manager service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Service responsible for managing redirects in admin context.
 *
 * Handles create, enable/disable, bulk operations, and redirect updates.
 * This service works with the repository to persist changes and
 * manages cache invalidation.
 */
class RedirectManager {

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * The redirect validator.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * Cache group for redirect lookups.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'vip-legacy-redirect-3';

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 * @param RedirectValidator|null      $validator  The redirect validator (optional, created if not provided).
	 */
	public function __construct( RedirectRepositoryInterface $repository, ?RedirectValidator $validator = null ) {
		$this->repository = $repository;
		$this->validator  = $validator ?? new RedirectValidator( $repository );
	}

	/**
	 * Create a new redirect.
	 *
	 * Validates the redirect before saving. Returns the validation result
	 * which can be checked for success or error details.
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @param bool        $validate    Whether to perform full validation (default true).
	 * @param string|null $status      Optional status ('publish' or 'draft'). Defaults to 'publish'.
	 * @return RedirectCreationResult The result containing either the redirect ID or validation error.
	 */
	public function create_redirect( SourceUrl $source, Destination $destination, bool $validate = true, ?string $status = null ): RedirectCreationResult {
		if ( ! $this->insert_allowed() ) {
			return RedirectCreationResult::error(
				'insert-not-allowed',
				__( 'Redirect creation is only allowed via WP-CLI or the admin. Use the wpcom_legacy_redirector_allow_insert filter to allow it elsewhere.', 'wpcom-legacy-redirector' )
			);
		}

		if ( $validate ) {
			$validation = $this->validator->validate_for_creation( $source, $destination );
			if ( $validation->is_invalid() ) {
				return RedirectCreationResult::from_validation( $validation );
			}
		}

		$redirect = Redirect::create( $source, $destination );

		// Apply custom status if provided.
		if ( null !== $status ) {
			$redirect = $redirect->with_status( $status );
		}

		try {
			$saved = $this->repository->save( $redirect );
			$this->invalidate_cache( $source->hash() );
			return RedirectCreationResult::success( $saved->id() );
		} catch ( \Exception $e ) {
			return RedirectCreationResult::error( 'save-failed', $e->getMessage() );
		}
	}

	/**
	 * Whether redirect creation is allowed in the current context.
	 *
	 * Mirrors the 1.x `insert_legacy_redirect()` gate: creation is allowed
	 * from WP-CLI and the admin; anywhere else (e.g. the front end) it must
	 * be opted into via the `wpcom_legacy_redirector_allow_insert` filter.
	 *
	 * @return bool True if creation is allowed.
	 */
	private function insert_allowed(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		if ( is_admin() ) {
			return true;
		}

		/**
		 * Filters whether redirects may be created outside WP-CLI and the admin.
		 *
		 * @param bool $allow_insert Whether to allow creation. Default false.
		 */
		return (bool) apply_filters( 'wpcom_legacy_redirector_allow_insert', false );
	}

	/**
	 * Enable a redirect (set status to publish).
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return bool True on success, false on failure.
	 */
	public function enable( int $redirect_id ): bool {
		return $this->change_status( $redirect_id, 'publish' );
	}

	/**
	 * Disable a redirect (set status to draft).
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return bool True on success, false on failure.
	 */
	public function disable( int $redirect_id ): bool {
		return $this->change_status( $redirect_id, 'draft' );
	}

	/**
	 * Change the status of a redirect.
	 *
	 * @param int    $redirect_id The redirect ID.
	 * @param string $new_status  The new status ('publish' or 'draft').
	 * @return bool True on success, false on failure.
	 */
	public function change_status( int $redirect_id, string $new_status ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

		$updated = $redirect->with_status( $new_status );

		try {
			$this->repository->save( $updated );
			$this->invalidate_cache( $redirect->source()->hash() );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Bulk enable redirects.
	 *
	 * @param int[] $redirect_ids Array of redirect IDs.
	 * @return int Number of redirects successfully enabled.
	 */
	public function bulk_enable( array $redirect_ids ): int {
		return $this->bulk_change_status( $redirect_ids, 'publish' );
	}

	/**
	 * Bulk disable redirects.
	 *
	 * @param int[] $redirect_ids Array of redirect IDs.
	 * @return int Number of redirects successfully disabled.
	 */
	public function bulk_disable( array $redirect_ids ): int {
		return $this->bulk_change_status( $redirect_ids, 'draft' );
	}

	/**
	 * Bulk change status for multiple redirects.
	 *
	 * @param int[]  $redirect_ids Array of redirect IDs.
	 * @param string $new_status   The new status.
	 * @return int Number of redirects successfully updated.
	 */
	private function bulk_change_status( array $redirect_ids, string $new_status ): int {
		$updated = 0;

		foreach ( $redirect_ids as $redirect_id ) {
			if ( $this->change_status( (int) $redirect_id, $new_status ) ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Update a redirect's destination.
	 *
	 * @param int         $redirect_id The redirect ID.
	 * @param Destination $destination The new destination.
	 * @param string|null $new_status  Optional new status.
	 * @return bool True on success, false on failure.
	 */
	public function update_destination( int $redirect_id, Destination $destination, ?string $new_status = null ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

		$updated = $redirect->with_destination( $destination );

		if ( null !== $new_status ) {
			$updated = $updated->with_status( $new_status );
		}

		try {
			$this->repository->save( $updated );
			$this->invalidate_cache( $redirect->source()->hash() );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Update a redirect's source, destination, and status.
	 *
	 * This is the full update method used by the edit screen.
	 *
	 * @param int         $redirect_id  The redirect ID.
	 * @param string      $new_source   The new source URL path.
	 * @param Destination $destination  The new destination.
	 * @param string|null $new_status   Optional new status.
	 * @return bool True on success, false on failure.
	 */
	public function update_redirect( int $redirect_id, string $new_source, Destination $destination, ?string $new_status = null ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

		// Store old source hash for cache invalidation.
		$old_hash = $redirect->source()->hash();

		// Create new source URL.
		try {
			$source = SourceUrl::from_string( $new_source );
		} catch ( \InvalidArgumentException $e ) {
			return false;
		}

		// Build updated redirect.
		$updated = $redirect
			->with_source( $source )
			->with_destination( $destination );

		if ( null !== $new_status ) {
			$updated = $updated->with_status( $new_status );
		}

		try {
			$this->repository->save( $updated );

			// Invalidate both old and new source caches.
			$this->invalidate_cache( $old_hash );
			if ( $old_hash !== $source->hash() ) {
				$this->invalidate_cache( $source->hash() );
			}

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Delete a redirect by its source URL.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return bool True if deleted, false if not found or deletion failed.
	 */
	public function delete_by_source( SourceUrl $source ): bool {
		$redirect = $this->repository->find_by_source( $source );
		if ( null === $redirect ) {
			return false;
		}

		$deleted = $this->repository->delete( $redirect );
		if ( $deleted ) {
			$this->invalidate_cache( $source->hash() );
		}

		return $deleted;
	}

	/**
	 * Delete a redirect by its ID.
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return bool True if deleted, false if not found or deletion failed.
	 */
	public function delete_by_id( int $redirect_id ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

		$deleted = $this->repository->delete( $redirect );
		if ( $deleted ) {
			$this->invalidate_cache( $redirect->source()->hash() );
		}

		return $deleted;
	}

	/**
	 * Update a redirect's destination by source URL.
	 *
	 * If the redirect exists, updates it. Used for bulk updates via CSV.
	 *
	 * @param SourceUrl   $source      The source URL to find.
	 * @param Destination $destination The new destination.
	 * @param string|null $status      Optional new status ('publish' or 'draft'). If null, preserves existing.
	 * @return bool True if updated, false if not found or update failed.
	 */
	public function update_by_source( SourceUrl $source, Destination $destination, ?string $status = null ): bool {
		$redirect = $this->repository->find_by_source( $source );
		if ( null === $redirect ) {
			return false;
		}

		$updated = $redirect->with_destination( $destination );

		// Apply status change if provided.
		if ( null !== $status ) {
			$updated = $updated->with_status( $status );
		}

		try {
			$this->repository->save( $updated );
			$this->invalidate_cache( $source->hash() );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Invalidate the cache for a redirect.
	 *
	 * Cache key includes blog ID prefix to ensure multisite isolation.
	 *
	 * @param string $url_hash The URL hash.
	 */
	private function invalidate_cache( string $url_hash ): void {
		$cache_key = sprintf( '%d:%s', get_current_blog_id(), $url_hash );
		wp_cache_delete( $cache_key, self::CACHE_GROUP );
	}
}
