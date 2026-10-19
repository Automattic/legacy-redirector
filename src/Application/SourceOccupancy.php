<?php
/**
 * SourceOccupancy.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Finds published content already answering a redirect's source.
 *
 * A redirect only fires on a 404, so a post or page published at its source
 * leaves it dormant. Looking the path up as a post finds the commonest case
 * without an HTTP request, so it can run on every row in the background.
 * It cannot see archives, terms, rewrite endpoints, or anything served
 * outside WordPress: only the live source probe can.
 *
 * Its own collaborator, like LoopDetector, because it reads the database.
 */
class SourceOccupancy {

	/**
	 * The publicly viewable post at a source path, if any.
	 *
	 * @param SourceUrl $source The source to look up.
	 * @return \WP_Post|null The post, or null when nothing published lives there.
	 */
	public function occupying_post( SourceUrl $source ): ?\WP_Post {
		// A reserved path is reported for what it is, and is never a post.
		if ( $source->is_reserved() ) {
			return null;
		}

		$slug = PostPathResolver::slug( $source->path() );

		// The home page has no slug to look up; get_page_by_path( '' ) would
		// match an arbitrary post with an empty post_name.
		if ( '' === $slug ) {
			return null;
		}

		// Most sources have nothing at them, and url_to_postid() runs a full
		// WP_Query to say so, halving the background sweep. One indexed query
		// rules them out first. ponytail: a permalink without the slug last
		// (%post_id%, a paged or feed URL) is skipped; the live test sees it.
		if ( ! $this->slug_exists( substr( strrchr( '/' . $slug, '/' ), 1 ) ) ) {
			return null;
		}

		$post = PostPathResolver::post( $slug );

		// A draft, a private post or a non-public post type 404s for a
		// visitor, so the redirect still fires there.
		return null !== $post && is_post_publicly_viewable( $post ) ? $post : null;
	}

	/**
	 * Whether any post carries this slug, normalized as get_page_by_path() does.
	 *
	 * @param string $segment The last segment of the source path.
	 * @return bool
	 */
	private function slug_exists( string $segment ): bool {
		global $wpdb;

		$post_name = sanitize_title_for_query( str_replace( '%20', ' ', rawurlencode( urldecode( $segment ) ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A per-row existence probe; caching it would cost more than the indexed lookup.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $post_name ) );
	}
}
