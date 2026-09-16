<?php
/**
 * Custom post type registration service.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

/**
 * Manages the custom post type for storing redirects.
 *
 * Redirects are stored as a custom post type with the following field mapping:
 * - post_name: MD5 hash of the "from" path (indexed for fast queries)
 * - post_title: Original "from" path
 * - post_parent: Target post ID (for internal redirects to posts)
 * - post_excerpt: Target URL (for redirects to external/arbitrary URLs)
 */
final class PostType {

	/**
	 * The redirect custom post type slug.
	 */
	public const POST_TYPE = 'vip-legacy-redirect';

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type( self::POST_TYPE, $this->get_args() );
		add_filter( 'bulk_post_updated_messages', array( $this, 'bulk_post_updated_messages' ), 10, 2 );
		add_filter( 'ep_indexable_post_types', array( $this, 'exclude_from_elasticpress' ) );
	}

	/**
	 * Exclude redirect post type from ElasticPress/VIP Search indexing.
	 *
	 * Redirects are internal data that should not appear in search results.
	 *
	 * @see https://docs.wpvip.com/enterprise-search/indexing/post-types/#1-excluding-post-types-from-the-allow-list
	 *
	 * @param array<string, string> $post_types Indexable post types.
	 * @return array<string, string> Filtered post types.
	 */
	public function exclude_from_elasticpress( array $post_types ): array {
		unset( $post_types[ self::POST_TYPE ] );
		return $post_types;
	}

	/**
	 * Customize bulk action messages for redirects.
	 *
	 * @param array<string, array<string, string>> $bulk_messages Arrays of messages, each keyed by the corresponding post type.
	 * @param array<string, int>                   $bulk_counts   Array of item counts for each message, used to build the messages.
	 * @return array<string, array<string, string>> Modified bulk messages.
	 */
	public function bulk_post_updated_messages( array $bulk_messages, array $bulk_counts ): array {
		// Get the trashed redirect source for single item messages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for message display.
		$redirect_source = isset( $_GET['redirect_source'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['redirect_source'] ) ) ) : '';

		// Build the trashed message - use specific source for single items if available.
		if ( 1 === $bulk_counts['trashed'] && $redirect_source ) {
			$trashed_message = sprintf(
				/* translators: %s: redirect source path */
				__( 'Redirect from %s moved to the Trash.', 'wpcom-legacy-redirector' ),
				'<code>' . esc_html( $redirect_source ) . '</code>'
			);
		} else {
			/* translators: %s: Number of redirects. */
			$trashed_message = _n(
				'%s redirect moved to the Trash.',
				'%s redirects moved to the Trash.',
				$bulk_counts['trashed'],
				'wpcom-legacy-redirector'
			);
		}

		$bulk_messages[ self::POST_TYPE ] = array(
			/* translators: %s: Number of redirects. */
			'updated'   => _n(
				'%s redirect updated.',
				'%s redirects updated.',
				$bulk_counts['updated'],
				'wpcom-legacy-redirector'
			),
			'locked'    => ( 1 === $bulk_counts['locked'] )
				? __( '1 redirect not updated, somebody is editing it.', 'wpcom-legacy-redirector' )
				/* translators: %s: Number of redirects. */
				: _n(
					'%s redirect not updated, somebody is editing it.',
					'%s redirects not updated, somebody is editing them.',
					$bulk_counts['locked'],
					'wpcom-legacy-redirector'
				),
			/* translators: %s: Number of redirects or redirect source path. */
			'deleted'   => _n(
				'%s redirect permanently deleted.',
				'%s redirects permanently deleted.',
				$bulk_counts['deleted'],
				'wpcom-legacy-redirector'
			),
			/* translators: %s: Number of redirects or redirect source path. */
			'trashed'   => $trashed_message,
			/* translators: %s: Number of redirects. */
			'untrashed' => _n(
				'%s redirect restored from the Trash.',
				'%s redirects restored from the Trash.',
				$bulk_counts['untrashed'],
				'wpcom-legacy-redirector'
			),
		);

		return $bulk_messages;
	}

	/**
	 * Get the post type labels.
	 *
	 * @return array<string, string>
	 */
	private function get_labels(): array {
		return array(
			'name'                  => _x( 'Redirects Manager', 'Post type general name', 'wpcom-legacy-redirector' ),
			'singular_name'         => _x( 'Redirect', 'Post type singular name', 'wpcom-legacy-redirector' ),
			'menu_name'             => _x( 'Redirects Manager', 'Admin Menu text', 'wpcom-legacy-redirector' ),
			'name_admin_bar'        => _x( 'Redirect', 'Add New on Toolbar', 'wpcom-legacy-redirector' ),
			'add_new'               => __( 'Add Redirect', 'wpcom-legacy-redirector' ),
			'add_new_item'          => __( 'Add Redirect', 'wpcom-legacy-redirector' ),
			'new_item'              => __( 'New Redirect', 'wpcom-legacy-redirector' ),
			'edit_item'             => __( 'Edit Redirect', 'wpcom-legacy-redirector' ),
			'view_item'             => __( 'View Redirect', 'wpcom-legacy-redirector' ),
			'all_items'             => __( 'All Redirects', 'wpcom-legacy-redirector' ),
			'search_items'          => __( 'Search Redirects', 'wpcom-legacy-redirector' ),
			'not_found'             => __( 'No redirects found.', 'wpcom-legacy-redirector' ),
			'not_found_in_trash'    => __( 'No redirects found in Trash.', 'wpcom-legacy-redirector' ),
			'filter_items_list'     => _x( 'Filter redirects list', 'Screen reader text for the filter links heading on the post type listing screen. Default "Filter posts list"/"Filter pages list". Added in 4.4', 'wpcom-legacy-redirector' ),
			'items_list_navigation' => _x( 'Redirect list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default "Posts list navigation"/"Pages list navigation". Added in 4.4', 'wpcom-legacy-redirector' ),
			'items_list'            => _x( 'Redirects list', 'Screen reader text for the items list heading on the post type listing screen. Default "Posts list"/"Pages list". Added in 4.4', 'wpcom-legacy-redirector' ),
		);
	}

	/**
	 * Get the post type arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function get_args(): array {
		return array(
			'labels'             => $this->get_labels(),
			'public'             => false,
			// Must stay false: redirect posts are internal records. Were they
			// queryable, /?post_type=vip-legacy-redirect&name=<md5-of-source-path>
			// would render the record publicly and bypass the 404 gate.
			'publicly_queryable' => false,
			'show_ui'            => true,
			'rewrite'            => false,
			'query_var'          => false,
			'hierarchical'       => false,
			'menu_position'      => 100,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'map_meta_cap'       => true,
			'menu_icon'          => 'dashicons-randomize',
			// No standard supports - we handle everything via custom admin pages.
			'supports'           => array( '' ),
			// Map every primitive capability to manage_redirects so the list
			// screen (and everything else) is limited to redirect managers.
			// Authors/Contributors must not read the redirect map: it can leak
			// unpublished slugs and internal structure. create_posts stays
			// do_not_allow to hide WordPress's auto-generated "Add New" submenu;
			// we provide our own "Add Redirect" page via add_submenu_page().
			'capabilities'       => array(
				'edit_posts'             => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'edit_others_posts'      => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'edit_private_posts'     => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'edit_published_posts'   => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'publish_posts'          => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'read_private_posts'     => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'delete_posts'           => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'delete_others_posts'    => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'delete_private_posts'   => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'delete_published_posts' => Capability::MANAGE_REDIRECTS_CAPABILITY,
				'create_posts'           => 'do_not_allow',
			),
		);
	}
}
