<?php
/**
 * WordPress post type redirect repository.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use WP_Post;

/**
 * Repository implementation using WordPress custom post type.
 *
 * Stores redirects as vip-legacy-redirect posts with:
 * - post_name: MD5 hash of source URL (for indexed lookups)
 * - post_title: Original source URL (human-readable)
 * - post_parent: Destination post ID (for internal redirects)
 * - post_excerpt: Destination URL (for external/relative redirects)
 */
final class PostTypeRedirectRepository implements RedirectRepositoryInterface {

	use RedirectPostMapper;

	/**
	 * The custom post type slug.
	 */
	public const string POST_TYPE = PostType::POST_TYPE;

	/**
	 * Find a redirect by its source URL.
	 *
	 * Only returns active (published) redirects. A corrupt row (see
	 * Redirect::is_corrupt()) is treated as no redirect: its placeholder
	 * values must never be served to a visitor.
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

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// Only return published redirects.
		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		$redirect = $this->map_post_to_redirect( $post );

		return $redirect->is_corrupt() ? null : $redirect;
	}

	/**
	 * Find a redirect by its ID.
	 *
	 * Unlike find_by_source(), an unreadable row is returned as a corrupt
	 * Redirect (see Redirect::is_corrupt()) so management surfaces can
	 * report and delete it.
	 *
	 * @param int $id The redirect ID.
	 * @return Redirect|null The redirect if found, null otherwise.
	 */
	#[\Override]
	public function find_by_id( int $id ): ?Redirect {
		$post = get_post( $id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->map_post_to_redirect( $post );
	}

	/**
	 * Check if a redirect exists for the given source URL.
	 *
	 * Draft or published; the trash holds no source. See get_id_by_source().
	 *
	 * @param SourceUrl $source The source URL to check.
	 * @return bool True if a redirect exists.
	 */
	#[\Override]
	public function exists( SourceUrl $source ): bool {
		return $this->get_id_by_source( $source ) > 0;
	}

	/**
	 * Save a redirect.
	 *
	 * Inserts are refused when a redirect already exists for the source,
	 * outside the trash. `post_name` holds the source hash and is how every lookup finds a
	 * redirect, but WordPress only uniquifies slugs for published posts, so a
	 * second draft insert would silently shadow the first and leave which one
	 * resolves up to a `LIMIT 1`.
	 *
	 * @param Redirect $redirect The redirect to save.
	 * @return Redirect The saved redirect with ID populated.
	 *
	 * @throws RedirectPersistenceException If the save fails, an insert would duplicate an existing source, an update would move onto a source another redirect has, or the redirect is corrupt.
	 */
	#[\Override]
	public function save( Redirect $redirect ): Redirect {
		// A corrupt redirect holds placeholder values; saving it would
		// overwrite the stored row with those placeholders.
		if ( $redirect->is_corrupt() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw RedirectPersistenceException::corrupt_redirect( $redirect->id() );
		}

		// wp_insert_post() unslashes what it is given, which would take the
		// backslash out of a destination such as '/a\b'.
		$args = wp_slash( $this->map_redirect_to_post_args( $redirect ) );

		if ( $redirect->is_persisted() ) {
			// An update re-derives the key from the source, so a redirect
			// sharing its source with another - a duplicate the 2.0 upgrade
			// disabled - would land on the other's key with any save, even an
			// enable. The lookup then answers with whichever row it finds
			// first, and the live redirect can stop working.
			$holder = $this->other_holder( $redirect );
			if ( $holder > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
				throw RedirectPersistenceException::source_taken( $redirect->source(), $holder );
			}

			$this->move_trash_aside( $redirect );

			$args['ID'] = $redirect->id();
			$result     = wp_update_post( $args, true );
		} else {
			if ( $this->get_id_by_source( $redirect->source() ) > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
				throw RedirectPersistenceException::duplicate_source( $redirect->source() );
			}

			$this->move_trash_aside( $redirect );

			$result = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $result ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw RedirectPersistenceException::save_failed(
				$redirect->source(),
				$result->get_error_message()
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $redirect->is_persisted()
			? $redirect
			: $redirect->with_id( $result );
	}

	/**
	 * Delete a redirect permanently.
	 *
	 * @param Redirect $redirect The redirect to delete.
	 * @return bool True if deleted successfully.
	 */
	#[\Override]
	public function delete( Redirect $redirect ): bool {
		if ( ! $redirect->is_persisted() ) {
			return false;
		}

		$result = wp_delete_post( $redirect->id(), true );

		return false !== $result;
	}

	/**
	 * Get the ID of a redirect for a source URL.
	 *
	 * A row in the trash holds no source, as core means by moving its slug
	 * aside; one the 2.0 migration re-keyed keeps a bare key, and is ignored
	 * all the same. Where more than one other row has the key, the live one
	 * answers: the published row, then the oldest. Core never renames a
	 * draft's slug, so restoring a redirect after its source was given to a
	 * new one puts two rows on one key, and an unordered pick could answer
	 * with the disabled row while the live redirect stopped firing.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return int The redirect ID, or 0 if not found.
	 */
	#[\Override]
	public function get_id_by_source( SourceUrl $source ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance-critical lookup, caching handled by caller.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s AND post_status <> 'trash' ORDER BY post_status = 'publish' DESC, ID LIMIT 1",
				self::POST_TYPE,
				$source->hash()
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * Move any trashed row off the key a redirect is about to be saved on.
	 *
	 * Core does this itself when a post takes a slug a trashed post has, but
	 * finds them by querying post type 'any', which leaves out a type hidden
	 * from search, as this one is. A trashed row keeps a bare key where the
	 * 2.0 migration re-keyed it, or where it was trashed before WordPress 4.5,
	 * and publishing onto it would give the new redirect the key '<md5>-2',
	 * which no request produces.
	 *
	 * @param Redirect $redirect The redirect about to be saved.
	 * @return void
	 */
	private function move_trash_aside( Redirect $redirect ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A write-path check that must see the database as it is.
		$trashed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s AND post_status = 'trash' AND ID <> %d",
				self::POST_TYPE,
				$redirect->source()->hash(),
				(int) $redirect->id()
			)
		);

		foreach ( $trashed as $id ) {
			wp_add_trashed_suffix_to_post_name_for_post( (int) $id );
		}
	}

	/**
	 * Another redirect holding a persisted redirect's source key, if any.
	 *
	 * The trash is left out: a trashed row answers no request.
	 *
	 * @param Redirect $redirect A persisted redirect.
	 * @return int The other redirect's ID, or 0 when none holds the key.
	 */
	private function other_holder( Redirect $redirect ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A write-path check that must see the database as it is.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s AND ID <> %d AND post_status <> 'trash' LIMIT 1",
				self::POST_TYPE,
				$redirect->source()->hash(),
				$redirect->id()
			)
		);
	}

	/**
	 * Map a Redirect entity to post args for wp_insert_post/wp_update_post.
	 *
	 * @param Redirect $redirect The redirect to map.
	 * @return array<string, mixed> The post args.
	 */
	private function map_redirect_to_post_args( Redirect $redirect ): array {
		$args = array(
			'post_type'   => self::POST_TYPE,
			'post_name'   => $redirect->source()->hash(),
			'post_title'  => $redirect->source()->path(),
			'post_status' => $redirect->status(),
		);

		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			$args['post_parent']  = $destination->as_post_id()->value();
			$args['post_excerpt'] = '';
		} else {
			$args['post_parent']  = 0;
			$args['post_excerpt'] = $destination->as_url()->value();
		}

		return $args;
	}
}
