<?php
/**
 * AJAX handler for searching posts for redirect destination autocomplete.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Handles AJAX requests to search posts for redirect destination autocomplete.
 */
final class SearchPostsHandler {

	private const string ACTION = 'search_posts_for_redirect';

	/**
	 * Register the AJAX action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Get the action name for nonce creation.
	 *
	 * @return string
	 */
	public static function get_action(): string {
		return self::ACTION;
	}

	/**
	 * Handle the AJAX request.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array( 'posts' => array() ) );
		}

		$query = new \WP_Query(
			array(
				's'              => $search,
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'relevance',
			)
		);

		$posts = array();
		foreach ( $query->posts as $post ) {
			$post_type_obj = get_post_type_object( $post->post_type );
			$posts[]       = array(
				'id'    => $post->ID,
				'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'type'  => $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type,
			);
		}

		wp_send_json_success( array( 'posts' => $posts ) );
	}
}
