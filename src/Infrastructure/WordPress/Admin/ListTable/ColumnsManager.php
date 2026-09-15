<?php
/**
 * Manages custom columns for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles column definitions, content rendering, and sorting for the redirects list table.
 */
final class ColumnsManager {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_' . PostType::POST_TYPE . '_posts_columns', array( $this, 'set_columns' ) );
		add_action( 'manage_' . PostType::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostType::POST_TYPE . '_sortable_columns', array( $this, 'set_sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_sorting' ) );
		add_filter( 'list_table_primary_column', array( $this, 'set_primary_column' ), 10, 2 );
	}

	/**
	 * Set column definitions.
	 *
	 * @return array<string, string> Column definitions.
	 */
	public function set_columns(): array {
		return array(
			'cb'     => '<input type="checkbox" />',
			'from'   => __( 'Redirect From', 'wpcom-legacy-redirector' ),
			'to'     => __( 'Redirect To', 'wpcom-legacy-redirector' ),
			'status' => __( 'Status', 'wpcom-legacy-redirector' ),
			'date'   => __( 'Date', 'wpcom-legacy-redirector' ),
		);
	}

	/**
	 * Set sortable columns.
	 *
	 * @param array<string, string> $columns Existing sortable columns.
	 * @return array<string, string> Modified sortable columns.
	 */
	public function set_sortable_columns( array $columns ): array {
		$columns['from'] = 'from';
		$columns['to']   = 'to';
		return $columns;
	}

	/**
	 * Set the primary column for row actions placement.
	 *
	 * @param string $column    Current primary column.
	 * @param string $screen_id The screen ID.
	 * @return string Primary column name.
	 */
	public function set_primary_column( string $column, string $screen_id ): string {
		if ( 'edit-' . PostType::POST_TYPE === $screen_id ) {
			return 'from';
		}
		return $column;
	}

	/**
	 * Handle custom column sorting.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function handle_sorting( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'from' === $orderby ) {
			$query->set( 'orderby', 'title' );
		}

		if ( 'to' === $orderby ) {
			$query->set( 'orderby', 'post_excerpt' );
		}
	}

	/**
	 * Render column content.
	 *
	 * @param string $column  The column name.
	 * @param int    $post_id The post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		switch ( $column ) {
			case 'from':
				$this->render_from_column( $post );
				break;
			case 'to':
				$this->render_to_column( $post );
				break;
			case 'status':
				$this->render_status_column( $post );
				break;
		}
	}

	/**
	 * Render the "from" column.
	 *
	 * @param \WP_Post $post The post object.
	 * @return void
	 */
	private function render_from_column( \WP_Post $post ): void {
		$edit_link = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $post->ID );
		printf(
			'<strong><a class="row-title" href="%1$s" aria-label="%2$s">%3$s</a></strong>',
			esc_url( $edit_link ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Edit redirect from &#8220;%s&#8221;', 'wpcom-legacy-redirector' ), get_the_title( $post->ID ) ) ),
			esc_html( get_the_title( $post->ID ) )
		);
	}

	/**
	 * Render the "to" column.
	 *
	 * @param \WP_Post $post The post object.
	 * @return void
	 */
	private function render_to_column( \WP_Post $post ): void {
		$excerpt     = get_the_excerpt( $post->ID );
		$parent_post = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;

		if ( ! empty( $excerpt ) ) {
			$this->render_excerpt_destination( $excerpt );
		} else {
			$this->render_post_id_destination( $post, $parent_post );
		}
	}

	/**
	 * Render destination when stored as excerpt (URL/path).
	 *
	 * @param string $excerpt The excerpt value.
	 * @return void
	 */
	private function render_excerpt_destination( string $excerpt ): void {
		// Check if it's the Home URL.
		if ( $this->is_home_path( $excerpt ) ) {
			$this->render_relative_path_with_prefix( $excerpt );
		} elseif ( str_starts_with( $excerpt, 'http' ) ) {
			// On multisite, use bold for consistency with relative paths.
			if ( is_multisite() ) {
				printf( '<strong>%s</strong>', esc_url( $excerpt ) );
			} else {
				echo esc_url( $excerpt );
			}
		} elseif ( 'private' === $this->check_path_publicity( $excerpt ) ) {
			$this->render_relative_path_with_prefix( $excerpt );
			echo '<br /><em>' . esc_html__( 'Warning: Redirect is not a public URL.', 'wpcom-legacy-redirector' ) . '</em>';
		} else {
			$this->render_relative_path_with_prefix( $excerpt );
		}
	}

	/**
	 * Render a relative path with the site's base URL as a grey prefix.
	 *
	 * On multisite, this helps clarify that /path resolves to the current site's
	 * base URL, not the network root. Shows the home_url prefix in grey followed
	 * by the path in bold.
	 *
	 * On single site, displays the path as plain text (no prefix or bold needed).
	 *
	 * @param string $path The relative path (e.g., "/hello-world").
	 * @return void
	 */
	private function render_relative_path_with_prefix( string $path ): void {
		if ( ! is_multisite() || ! str_starts_with( $path, '/' ) ) {
			echo esc_html( $path );
			return;
		}

		$home_url = untrailingslashit( home_url() );
		printf(
			'<span style="color: #888;">%s</span><strong>%s</strong>',
			esc_html( $home_url ),
			esc_html( $path )
		);
	}

	/**
	 * Render destination when stored as post_parent (post ID).
	 *
	 * @param \WP_Post      $post   The redirect post.
	 * @param \WP_Post|null $parent_post The parent post if exists.
	 * @return void
	 */
	private function render_post_id_destination( \WP_Post $post, ?\WP_Post $parent_post ): void {
		$status = $this->get_parent_status( $post );

		switch ( $status ) {
			case false:
				echo '<em>' . esc_html__( 'Redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' ) . '</em>';
				break;
			case 'private':
				$permalink     = $parent_post ? get_permalink( $parent_post ) : '';
				$relative_path = str_replace( home_url(), '', $permalink );
				$this->render_relative_path_with_prefix( $relative_path );
				echo '<br /><em>' . esc_html__( 'Warning: Redirect is not a public URL.', 'wpcom-legacy-redirector' ) . '</em>';
				break;
			default:
				$permalink     = $parent_post ? get_permalink( $parent_post ) : '';
				$relative_path = str_replace( home_url(), '', $permalink );
				$this->render_relative_path_with_prefix( $relative_path );
		}
	}

	/**
	 * Check if the excerpt path is the home URL.
	 *
	 * @param string $excerpt The excerpt value (path or URL).
	 * @return bool True if the excerpt represents the home URL.
	 */
	private function is_home_path( string $excerpt ): bool {
		return '/' === $excerpt || home_url() === $excerpt;
	}

	/**
	 * Check if a path points to a public post.
	 *
	 * @param string $excerpt The path to check.
	 * @return string|null 'private' if the post exists but isn't published, null otherwise.
	 */
	private function check_path_publicity( string $excerpt ): ?string {
		$post_types = get_post_types();
		$post_obj   = get_page_by_path( $excerpt, OBJECT, $post_types );

		if ( null !== $post_obj && 'publish' !== get_post_status( $post_obj->ID ) ) {
			return 'private';
		}

		return null;
	}

	/**
	 * Get the status of a redirect's parent (destination) post.
	 *
	 * @param \WP_Post $post The redirect post.
	 * @return string|false Parent post slug if valid, 'private' if not published, false if not found.
	 */
	private function get_parent_status( \WP_Post $post ) {
		$parent_post = get_post( $post->post_parent );

		if ( ! $parent_post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' !== get_post_status( $parent_post ) ) {
			return 'private';
		}

		return $parent_post->post_name;
	}

	/**
	 * Render the "status" column.
	 *
	 * @param \WP_Post $post The post object.
	 * @return void
	 */
	private function render_status_column( \WP_Post $post ): void {
		if ( 'publish' === $post->post_status ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . esc_attr__( 'Enabled', 'wpcom-legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Enabled', 'wpcom-legacy-redirector' );
		} else {
			echo '<span class="dashicons dashicons-no" style="color: #dc3232;" title="' . esc_attr__( 'Disabled', 'wpcom-legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Disabled', 'wpcom-legacy-redirector' );
		}
	}
}
