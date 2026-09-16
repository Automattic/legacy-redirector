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
				__( 'Redirect creation is only allowed from WP-CLI and for users who can manage redirects. Use the wpcom_legacy_redirector_allow_insert filter to allow it elsewhere.', 'wpcom-legacy-redirector' )
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
	 * Creation is allowed from WP-CLI (which runs with no user context) and
	 * for any user with the capability to manage redirects, wherever the
	 * request arrives from. Anywhere else (e.g. unauthenticated front-end
	 * code) it must be opted into via the
	 * `wpcom_legacy_redirector_allow_insert` filter.
	 *
	 * The 1.x gate allowed any admin-context request instead of checking the
	 * capability; every admin entry point checks `manage_redirects` before
	 * calling this service, so the capability is the honest form of the same
	 * rule — and one a REST or Abilities caller can also satisfy.
	 *
	 * @return bool True if creation is allowed.
	 */
	private function insert_allowed(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// Capability::MANAGE_REDIRECTS_CAPABILITY, not imported here so the
		// Application layer does not depend on an Infrastructure class.
		if ( current_user_can( 'manage_redirects' ) ) {
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
		return $this->change_status( $redirect_id, 'publish' )->is_success();
	}

	/**
	 * Disable a redirect (set status to draft).
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return bool True on success, false on failure.
	 */
	public function disable( int $redirect_id ): bool {
		return $this->change_status( $redirect_id, 'draft' )->is_success();
	}

	/**
	 * Change the status of a redirect.
	 *
	 * Refused for corrupt redirects: re-saving one would overwrite the
	 * stored row with its placeholder values. Delete it, or update it with
	 * a full new source and destination.
	 *
	 * @param int    $redirect_id The redirect ID.
	 * @param string $new_status  The new status ('publish' or 'draft').
	 * @return RedirectCreationResult The result carrying the redirect ID or the failure details.
	 */
	public function change_status( int $redirect_id, string $new_status ): RedirectCreationResult {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return $this->not_found( $redirect_id );
		}

		if ( $redirect->is_corrupt() ) {
			return $this->corrupt( $redirect_id );
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
			if ( $this->change_status( (int) $redirect_id, $new_status )->is_success() ) {
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
	 * @return RedirectCreationResult The result carrying the redirect ID or the failure details.
	 */
	public function update_destination( int $redirect_id, Destination $destination, ?string $new_status = null, bool $validate = true ): RedirectCreationResult {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return $this->not_found( $redirect_id );
		}

		// Refused for corrupt redirects: the stored source is unreadable, so
		// there is nothing trustworthy to keep. Use update_redirect() instead.
		if ( $redirect->is_corrupt() ) {
			return $this->corrupt( $redirect_id );
		}

		$updated = $redirect->with_destination( $destination );

		if ( $validate ) {
			$validation = $this->validator()->validate( $updated );
			if ( $validation->is_invalid() ) {
				return RedirectCreationResult::from_validation( $validation );
			}
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
	 * This is the full update method used by the edit screen. Because every
	 * stored field is replaced, it also repairs a corrupt redirect.
	 *
	 * @param int         $redirect_id  The redirect ID.
	 * @param string      $new_source   The new source URL path.
	 * @param Destination $destination  The new destination.
	 * @param string|null $new_status   Optional new status.
	 * @param bool        $validate     Whether to validate the update (default true).
	 * @return RedirectCreationResult The result carrying the redirect ID or the failure details.
	 */
	public function update_redirect( int $redirect_id, string $new_source, Destination $destination, ?string $new_status = null, bool $validate = true ): RedirectCreationResult {
		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			return $this->not_found( $redirect_id );
		}

		// Create new source URL.
		try {
			$source = SourceUrl::from_string( $new_source );
		} catch ( \InvalidArgumentException $e ) {
			return RedirectCreationResult::error( 'invalid-source', $e->getMessage() );
		}

		// Build updated redirect.
		$updated = $this->with_new_mapping( $redirect, $source, $destination );

		if ( $validate ) {
			$validation = $this->validator()->validate( $updated );
			if ( $validation->is_invalid() ) {
				return RedirectCreationResult::from_validation( $validation );
			}
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
	 * @return RedirectCreationResult The result carrying the redirect ID or the failure details.
	 */
	public function update_by_source( SourceUrl $source, Destination $destination, ?string $status = null, bool $validate = true ): RedirectCreationResult {
		$redirect = $this->find_any_by_source( $source );
		if ( null === $redirect ) {
			return RedirectCreationResult::error(
				'not-found',
				sprintf(
					/* translators: %s: source path. */
					__( 'No redirect found for source: %s', 'wpcom-legacy-redirector' ),
					$source->path()
				)
			);
		}

		// The caller supplies both source and destination, so this also
		// repairs a corrupt row - re-importing source data is the documented
		// recovery for a botched migration.
		$updated = $this->with_new_mapping( $redirect, $source, $destination );

		if ( $validate ) {
			$validation = $this->validator()->validate( $updated );
			if ( $validation->is_invalid() ) {
				return RedirectCreationResult::from_validation( $validation );
			}
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
	 * Apply a new source and destination to an existing redirect.
	 *
	 * For a corrupt redirect, every stored field is being replaced, so the
	 * entity is rebuilt from scratch rather than copied: this is the repair
	 * path, and it must not carry the corrupt placeholders (or the corruption
	 * flag, which save() refuses) into the new row.
	 *
	 * @param Redirect    $redirect    The existing redirect.
	 * @param SourceUrl   $source      The new source URL.
	 * @param Destination $destination The new destination.
	 * @return Redirect The redirect with the new mapping applied.
	 */
	private function with_new_mapping( Redirect $redirect, SourceUrl $source, Destination $destination ): Redirect {
		if ( $redirect->is_corrupt() ) {
			return Redirect::reconstitute(
				(int) $redirect->id(),
				$source,
				$destination,
				$redirect->status(),
				$redirect->created_at()
			);
		}

		return $redirect
			->with_source( $source )
			->with_destination( $destination );
	}

	/**
	 * Persist an updated redirect.
	 *
	 * @param Redirect $updated The redirect to save.
	 * @return RedirectCreationResult The result carrying the redirect ID or the failure details.
	 */
	private function persist( Redirect $updated ): RedirectCreationResult {
		try {
			$saved = $this->repository->save( $updated );
			return RedirectCreationResult::success( (int) $saved->id() );
		} catch ( \Exception $e ) {
			return RedirectCreationResult::error( 'save-failed', $e->getMessage() );
		}
	}

	/**
	 * Build a not-found error result for a redirect ID.
	 *
	 * @param int $redirect_id The redirect ID that did not resolve.
	 * @return RedirectCreationResult The error result.
	 */
	private function not_found( int $redirect_id ): RedirectCreationResult {
		return RedirectCreationResult::error(
			'not-found',
			sprintf(
				/* translators: %d: redirect ID. */
				__( 'No redirect found with ID %d.', 'wpcom-legacy-redirector' ),
				$redirect_id
			)
		);
	}

	/**
	 * Build a corrupt-redirect error result for a redirect ID.
	 *
	 * @param int $redirect_id The corrupt redirect's ID.
	 * @return RedirectCreationResult The error result.
	 */
	private function corrupt( int $redirect_id ): RedirectCreationResult {
		return RedirectCreationResult::error(
			'corrupt-redirect',
			sprintf(
				/* translators: %d: redirect ID. */
				__( 'Redirect %d is corrupt and cannot be re-saved. Delete it, or update it with a full new source and destination.', 'wpcom-legacy-redirector' ),
				$redirect_id
			)
		);
	}
}
