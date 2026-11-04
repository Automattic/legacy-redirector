<?php
/**
 * PostPathResolver.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * Finds the post a site-relative path serves, if any.
 *
 * The one rule for it, shared by the destination check (does this path
 * still lead to published content?) and the source check (is published
 * content already answering this path?).
 */
final class PostPathResolver {

	/**
	 * What the last post() call was asked, and on which state of the posts.
	 *
	 * @var string|null
	 */
	private static ?string $last_key = null;

	/**
	 * What the last post() call answered.
	 *
	 * @var \WP_Post|null
	 */
	private static ?\WP_Post $last_post = null;

	/**
	 * The slug form of a relative path, ready for a post lookup.
	 *
	 * A query string or fragment can never be part of a slug match.
	 *
	 * @param string $path The relative path.
	 * @return string The path with surrounding slashes, query, and fragment removed.
	 */
	public static function slug( string $path ): string {
		return trim( substr( $path, 0, strcspn( $path, '?#' ) ), '/' );
	}

	/**
	 * Resolve a slug to the post it serves, if any.
	 *
	 * Only viewable post types are searched. Templates, navigation menus,
	 * redirects and other internal types are never served at a path, so one
	 * of them must not stand in for a missing page, nor shadow a real page
	 * that shares its slug.
	 *
	 * The last answer is remembered, because an audit asks about the same
	 * destination twice in a row (the finding, then whether only HTTP can
	 * judge it), and each resolution is a page lookup plus a full WP_Query
	 * through url_to_postid(): queries when nothing is cached, a string of
	 * object cache round trips when it is. Saving any post moves the posts
	 * last-changed time, so a remembered answer never outlives a change.
	 * ponytail: one entry only, as the background sweep runs this over every
	 * row and a growing memo would hold them all; a source that a post's slug
	 * matches is looked up in between, so that row resolves its destination
	 * twice.
	 *
	 * @param string $slug The path with surrounding slashes, query, and fragment removed.
	 * @return \WP_Post|null The post, or null if the path resolves to none.
	 */
	public static function post( string $slug ): ?\WP_Post {
		$key = get_current_blog_id() . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . $slug;

		if ( self::$last_key !== $key ) {
			self::$last_post = self::resolve( $slug );
			self::$last_key  = $key;
		}

		return self::$last_post;
	}

	/**
	 * Resolve a slug to the post it serves, without the memo.
	 *
	 * @param string $slug The path with surrounding slashes, query, and fragment removed.
	 * @return \WP_Post|null The post, or null if the path resolves to none.
	 */
	private static function resolve( string $slug ): ?\WP_Post {
		$post = get_page_by_path( $slug, OBJECT, self::viewable_post_types() );

		// get_page_by_path() only walks hierarchical slugs; url_to_postid()
		// resolves anything matching the site's permalink structure, such as
		// dated permalinks. Its rewrite rules, or a filter on it, can still
		// lead to any post type.
		if ( null === $post ) {
			$post_id = url_to_postid( home_url( '/' . $slug ) );
			$post    = 0 !== $post_id ? get_post( $post_id ) : null;
		}

		return null !== $post && is_post_type_viewable( $post->post_type ) ? $post : null;
	}

	/**
	 * The post types a visitor can view at a path.
	 *
	 * @return string[] The post type names.
	 */
	public static function viewable_post_types(): array {
		return array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );
	}
}
