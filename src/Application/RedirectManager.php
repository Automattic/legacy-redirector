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
 * Persistence and cache invalidation are the repository's concern: wire
 * this service with CachingRedirectRepository so writes invalidate stale
 * lookups.
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
	 * Created lazily: most manager operations (delete, enable, disable,
	 * unvalidated creation) never need it.
	 *
	 * @var RedirectValidator|null
	 */
	private ?RedirectValidator $validator;

	/**
	 * The internal destination normaliser.
	 *
	 * @var InternalDestinationNormaliser
	 */
	private InternalDestinationNormaliser $normaliser;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 * @param RedirectValidator|null      $validator  The redirect validator (optional, created on first use if not provided).
	 */
	public function __construct( RedirectRepositoryInterface $repository, ?RedirectValidator $validator = null ) {
		$this->repository = $repository;
		$this->validator  = $validator;
		$this->normaliser = new InternalDestinationNormaliser();
	}

	/**
	 * Get the redirect validator, creating it on first use.
	 *
	 * @return RedirectValidator The validator.
	 */
	private function validator(): RedirectValidator {
		return $this->validator ??= new RedirectValidator( $this->repository );
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

		$redirect = Redirect::create( $source, $destination );

		if ( $validate ) {
			$validation = $this->validator()->validate( $redirect );
			if ( $validation->is_invalid() ) {
				return RedirectCreationResult::from_validation( $validation );
			}
		}

		$redirect = $this->with_normalised_destination( $redirect );

		// Apply custom status if provided.
		if ( null !== $status ) {
			$redirect = $redirect->with_status( $status );
		}

		try {
			$saved = $this->repository->save( $redirect );
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

		return $this->persist( $updated );
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
	 * @param bool        $validate    Whether to validate the update (default true).
	 * @return bool True on success, false on failure or when validation rejects the update.
	 */
	public function update_destination( int $redirect_id, Destination $destination, ?string $new_status = null, bool $validate = true ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

		$updated = $redirect->with_destination( $destination );

		if ( $validate && $this->validator()->validate( $updated )->is_invalid() ) {
			return false;
		}

		$updated = $this->with_normalised_destination( $updated );

		if ( null !== $new_status ) {
			$updated = $updated->with_status( $new_status );
		}

		return $this->persist( $updated );
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
	 * @param bool        $validate     Whether to validate the update (default true).
	 * @return bool True on success, false on failure or when validation rejects the update.
	 */
	public function update_redirect( int $redirect_id, string $new_source, Destination $destination, ?string $new_status = null, bool $validate = true ): bool {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return false;
		}

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

		if ( $validate && $this->validator()->validate( $updated )->is_invalid() ) {
			return false;
		}

		$updated = $this->with_normalised_destination( $updated );

		if ( null !== $new_status ) {
			$updated = $updated->with_status( $new_status );
		}

		return $this->persist( $updated );
	}

	/**
	 * Delete a redirect by its source URL.
	 *
	 * Matches redirects in any status, so a disabled redirect can be deleted.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return bool True if deleted, false if not found or deletion failed.
	 */
	public function delete_by_source( SourceUrl $source ): bool {
		$redirect = $this->find_any_by_source( $source );
		if ( null === $redirect ) {
			return false;
		}

		return $this->repository->delete( $redirect );
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

		return $this->repository->delete( $redirect );
	}

	/**
	 * Update a redirect's destination by source URL.
	 *
	 * If the redirect exists, updates it. Used for bulk updates via CSV.
	 *
	 * Matches redirects in any status, so a disabled redirect can be re-pointed
	 * rather than falling through to the create path and duplicating.
	 *
	 * @param SourceUrl   $source      The source URL to find.
	 * @param Destination $destination The new destination.
	 * @param string|null $status      Optional new status ('publish' or 'draft'). If null, preserves existing.
	 * @param bool        $validate    Whether to validate the update (default true).
	 * @return bool True if updated, false if not found, invalid, or the update failed.
	 */
	public function update_by_source( SourceUrl $source, Destination $destination, ?string $status = null, bool $validate = true ): bool {
		$redirect = $this->find_any_by_source( $source );
		if ( null === $redirect ) {
			return false;
		}

		$updated = $redirect->with_destination( $destination );

		if ( $validate && $this->validator()->validate( $updated )->is_invalid() ) {
			return false;
		}

		$updated = $this->with_normalised_destination( $updated );

		// Apply status change if provided.
		if ( null !== $status ) {
			$updated = $updated->with_status( $status );
		}

		return $this->persist( $updated );
	}

	/**
	 * Find a redirect by source URL regardless of its status.
	 *
	 * `RedirectRepositoryInterface::find_by_source()` is publish-only, which is
	 * what the front-end resolver wants but not what management operations do:
	 * a disabled redirect is still a redirect you can edit or delete. Mirrors
	 * the lookup RedirectFetcher uses for the same reason.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return Redirect|null The redirect in any status, or null if none exists.
	 */
	private function find_any_by_source( SourceUrl $source ): ?Redirect {
		$redirect_id = $this->repository->get_id_by_source( $source );

		return $redirect_id > 0 ? $this->repository->find_by_id( $redirect_id ) : null;
	}

	/**
	 * Canonicalise a redirect's destination for storage.
	 *
	 * Validation runs against the destination as entered - an absolute URL is
	 * validated as a URL, not routed into the published-post check that
	 * relative paths get - so only the stored form is canonicalised, and only
	 * after validation has had its say.
	 *
	 * @param Redirect $redirect The redirect to canonicalise.
	 * @return Redirect The redirect with its destination in stored form.
	 */
	private function with_normalised_destination( Redirect $redirect ): Redirect {
		return $redirect->with_destination( $this->normaliser->normalise( $redirect->destination() ) );
	}

	/**
	 * Persist an updated redirect, swallowing persistence failures.
	 *
	 * @param Redirect $updated The redirect to save.
	 * @return bool True on success, false on failure.
	 */
	private function persist( Redirect $updated ): bool {
		try {
			$this->repository->save( $updated );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
