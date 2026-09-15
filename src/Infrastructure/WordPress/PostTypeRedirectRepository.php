<?php
/**
 * WordPress post type redirect repository.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use DateTimeImmutable;
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

	/**
	 * The custom post type slug.
	 */
	public const POST_TYPE = 'vip-legacy-redirect';

	/**
	 * Find a redirect by its source URL.
	 *
	 * Only returns active (published) redirects.
	 *
	 * @param SourceUrl $source The source URL to find.
	 * @return Redirect|null The redirect if found and active, null otherwise.
	 */
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

		return $this->map_post_to_redirect( $post );
	}

	/**
	 * Find a redirect by its ID.
	 *
	 * @param int $id The redirect ID.
	 * @return Redirect|null The redirect if found, null otherwise.
	 */
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
	 * Includes all statuses (publish, draft, trash).
	 *
	 * @param SourceUrl $source The source URL to check.
	 * @return bool True if a redirect exists.
	 */
	public function exists( SourceUrl $source ): bool {
		return $this->get_id_by_source( $source ) > 0;
	}

	/**
	 * Save a redirect.
	 *
	 * Inserts are refused when a redirect already exists for the source, in any
	 * status. `post_name` holds the source hash and is how every lookup finds a
	 * redirect, but WordPress only uniquifies slugs for published posts, so a
	 * second draft insert would silently shadow the first and leave which one
	 * resolves up to a `LIMIT 1`.
	 *
	 * @param Redirect $redirect The redirect to save.
	 * @return Redirect The saved redirect with ID populated.
	 *
	 * @throws RedirectPersistenceException If the save fails, or an insert would duplicate an existing source.
	 */
	public function save( Redirect $redirect ): Redirect {
		$args = $this->map_redirect_to_post_args( $redirect );

		if ( $redirect->is_persisted() ) {
			$args['ID'] = $redirect->id();
			$result     = wp_update_post( $args, true );
		} else {
			if ( $this->get_id_by_source( $redirect->source() ) > 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
				throw RedirectPersistenceException::duplicate_source( $redirect->source() );
			}

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
	 * @param SourceUrl $source The source URL.
	 * @return int The redirect ID, or 0 if not found.
	 */
	public function get_id_by_source( SourceUrl $source ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Performance-critical lookup, caching handled by caller.
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s LIMIT 1",
				self::POST_TYPE,
				$source->hash()
			)
		);

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * Map a WP_Post to a Redirect entity.
	 *
	 * @param WP_Post $post The post to map.
	 * @return Redirect The redirect entity.
	 */
	private function map_post_to_redirect( WP_Post $post ): Redirect {
		$source      = SourceUrl::from_string( $post->post_title );
		$destination = $this->extract_destination_from_post( $post );
		$created_at  = $this->parse_date( $post->post_date_gmt );

		return Redirect::reconstitute(
			$post->ID,
			$source,
			$destination,
			$post->post_status,
			$created_at
		);
	}

	/**
	 * Extract the destination from a post.
	 *
	 * @param WP_Post $post The redirect post.
	 * @return Destination The destination.
	 */
	private function extract_destination_from_post( WP_Post $post ): Destination {
		// Check for internal redirect (post_parent).
		if ( $post->post_parent > 0 ) {
			return Destination::from_post_id(
				DestinationPostId::from_int( $post->post_parent )
			);
		}

		// External or relative URL (post_excerpt).
		$excerpt = trim( $post->post_excerpt );
		if ( ! empty( $excerpt ) ) {
			return Destination::from_url(
				DestinationUrl::from_string( $excerpt )
			);
		}

		// Fallback to home if no destination found.
		return Destination::from_url( DestinationUrl::home() );
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

	/**
	 * Parse a date string to DateTimeImmutable.
	 *
	 * @param string $date_string The date string (MySQL format).
	 * @return DateTimeImmutable|null The parsed date, or null if invalid.
	 */
	private function parse_date( string $date_string ): ?DateTimeImmutable {
		if ( empty( $date_string ) || '0000-00-00 00:00:00' === $date_string ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date_string );

		return $date instanceof DateTimeImmutable ? $date : null;
	}
}
