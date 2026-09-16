<?php
/**
 * Redirect repository interface.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Repository interface for Redirect entities.
 *
 * Defines the contract for redirect persistence operations.
 * Implementations handle the actual data storage (e.g., WordPress posts).
 */
interface RedirectRepositoryInterface {

	/**
	 * Find a redirect by its source URL.
	 *
	 * Only returns published redirects: this is the front-end resolution
	 * lookup. Management code that must see disabled redirects too should use
	 * get_id_by_source() and find_by_id(), which ignore status. A corrupt row
	 * (see Redirect::is_corrupt()) is treated as no redirect.
	 *
	 * @param SourceUrl $source The source URL to find.
	 * @return Redirect|null The redirect if found, null otherwise.
	 */
	public function find_by_source( SourceUrl $source ): ?Redirect;

	/**
	 * Find a redirect by its ID.
	 *
	 * An unreadable row is returned as a corrupt Redirect (see
	 * Redirect::is_corrupt()) so management surfaces can report and delete
	 * it; save() refuses such an entity.
	 *
	 * @param int $id The redirect ID.
	 * @return Redirect|null The redirect if found, null otherwise.
	 */
	public function find_by_id( int $id ): ?Redirect;

	/**
	 * Check if a redirect exists for the given source URL.
	 *
	 * Includes all statuses (publish, draft, trash).
	 *
	 * @param SourceUrl $source The source URL to check.
	 * @return bool True if a redirect exists.
	 */
	public function exists( SourceUrl $source ): bool;

	/**
	 * Save a redirect.
	 *
	 * For new redirects (no ID), this creates a new record.
	 * For existing redirects, this updates the record.
	 *
	 * @param Redirect $redirect The redirect to save.
	 * @return Redirect The saved redirect (with ID populated if new).
	 *
	 * @throws RedirectPersistenceException If the save fails or the redirect is corrupt.
	 */
	public function save( Redirect $redirect ): Redirect;

	/**
	 * Delete a redirect permanently.
	 *
	 * @param Redirect $redirect The redirect to delete.
	 * @return bool True if deleted successfully.
	 */
	public function delete( Redirect $redirect ): bool;

	/**
	 * Get the ID of a redirect for a source URL without loading the full entity.
	 *
	 * Useful for cache lookups and existence checks.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return int The redirect ID, or 0 if not found.
	 */
	public function get_id_by_source( SourceUrl $source ): int;
}
