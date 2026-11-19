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
	 * Whether a post carries each slug prime() looked up, keyed by slug.
	 *
	 * @var array<string, bool>
	 */
	private array $primed = array();

	/**
	 * The site and posts state prime() looked the slugs up on.
	 *
	 * @var string|null
	 */
	private ?string $primed_on = null;

	/**
	 * The publicly viewable post at a source path, if any.
	 *
	 * @param SourceUrl $source The source to look up.
	 * @return \WP_Post|null The post, or null when nothing published lives there.
	 */
	public function occupying_post( SourceUrl $source ): ?\WP_Post {
		$post_name = $this->post_name( $source );

		// Most sources have nothing at them, and url_to_postid() runs a full
		// WP_Query to say so, halving the background sweep. One indexed query
		// rules them out first. ponytail: a permalink without the slug last
		// (%post_id%, a paged or feed URL) is skipped; the live test sees it.
		if ( null === $post_name || ! $this->slug_exists( $post_name ) ) {
			return null;
		}

		$post = PostPathResolver::post( PostPathResolver::slug( $source->path() ) );

		// A draft, a private post or a non-public post type 404s for a
		// visitor, so the redirect still fires there.
		return null !== $post && is_post_publicly_viewable( $post ) ? $post : null;
	}

	/**
	 * Look up the slugs of many sources at once, ahead of asking about each.
	 *
	 * The background sweep asks about a batch of rows in turn, and one query
	 * for the batch replaces one per row. The answers hold until any post
	 * changes, or until the next call.
	 *
	 * @param SourceUrl[] $sources The sources about to be asked about.
	 * @return void
	 */
	public function prime( array $sources ): void {
		global $wpdb;

		$names = array_values( array_unique( array_filter( array_map( array( $this, 'post_name' ), $sources ), 'is_string' ) ) );

		$this->primed    = array();
		$this->primed_on = null;

		if ( array() === $names ) {
			return;
		}

		// A refused read is not an empty answer, and nothing is primed: each
		// source is then probed on its own. query()'s return value says so;
		// last_error may still hold an earlier query's error, or none.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One existence probe for the batch; the interpolated fragment is only %s placeholders.
		if ( false === $wpdb->query(
			$wpdb->prepare(
				"SELECT DISTINCT post_name FROM {$wpdb->posts} WHERE post_name IN (" . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
				...$names
			)
		) ) {
			return;
		}
		// phpcs:enable

		$primed = array_fill_keys( $names, false );

		foreach ( $wpdb->last_result as $row ) {
			// The names asked for are lowercase, and the database matches
			// them case-insensitively, so a stored name may come back in
			// another case. The collation also folds accents and ignores
			// trailing spaces; a name that matched only that way (one written
			// outside WordPress) cannot be told apart here, so the batch is
			// left to the per-row probe, which asks the database directly.
			$name = strtolower( (string) $row->post_name );

			if ( ! isset( $primed[ $name ] ) ) {
				return;
			}

			$primed[ $name ] = true;
		}

		$this->primed    = $primed;
		$this->primed_on = self::posts_state();
	}

	/**
	 * The slug a post at the source would carry, normalized as get_page_by_path() does.
	 *
	 * Only the last segment: a hierarchical page carries its own slug, not
	 * its ancestors'.
	 *
	 * @param SourceUrl $source The source.
	 * @return string|null The slug, or null when no post can live at the source.
	 */
	private function post_name( SourceUrl $source ): ?string {
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

		$segment = substr( strrchr( '/' . $slug, '/' ), 1 );

		return sanitize_title_for_query( str_replace( '%20', ' ', rawurlencode( urldecode( $segment ) ) ) );
	}

	/**
	 * Whether any post carries this slug.
	 *
	 * @param string $post_name The slug, from post_name().
	 * @return bool
	 */
	private function slug_exists( string $post_name ): bool {
		global $wpdb;

		if ( self::posts_state() === $this->primed_on && isset( $this->primed[ $post_name ] ) ) {
			return $this->primed[ $post_name ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A per-row existence probe; caching it would cost more than the indexed lookup.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $post_name ) );
	}

	/**
	 * The current site and posts state, which any post write moves on.
	 *
	 * @return string
	 */
	private static function posts_state(): string {
		return get_current_blog_id() . ':' . wp_cache_get_last_changed( 'posts' );
	}
}
