<?php
/**
 * Refuses core's generic post editing for redirects.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

/**
 * Keeps core's own post editing away from redirects.
 *
 * Every redirect write is meant to go through RedirectManager, which
 * validates the source and destination, keys the row by its source and keeps
 * the status to publish, draft or trash. The admin hides Quick Edit, Bulk Edit
 * and the classic edit screen, but core still accepts a crafted request to
 * each, and to XML-RPC, and those save the post fields as given: a key that
 * is not the source's, a status such as pending or private, an unchecked
 * destination.
 *
 * The guard sits on those entry points rather than on wp_insert_post_data,
 * because core's trash, restore and delete, and the plugin's own repository,
 * write through wp_insert_post() too and must keep working.
 */
final class CoreEditGuard {

	/**
	 * The post.php actions a redirect may take: the row links' trash, restore and delete.
	 *
	 * @var string[]
	 */
	private const array ALLOWED_POST_ACTIONS = array( 'trash', 'untrash', 'delete' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Before core's wp_ajax_inline_save(), registered at the default priority.
		add_action( 'wp_ajax_inline-save', array( $this, 'refuse_quick_edit' ), 0 );
		// After RedirectFormPage::redirect_post_edit() has sent a plain edit
		// link to the plugin's own page.
		add_action( 'load-post.php', array( $this, 'refuse_edit_screen' ), 11 );
		add_action( 'load-edit.php', array( $this, 'refuse_bulk_edit' ) );

		// Every XML-RPC method that changes a post checks edit_post first.
		// Only added there, so other requests do not pay for it on every
		// capability check.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			add_filter( 'map_meta_cap', array( $this, 'refuse_xmlrpc_edit' ), 10, 4 );
		}
	}

	/**
	 * Refuse a Quick Edit save of a redirect.
	 *
	 * @return void
	 */
	public function refuse_quick_edit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Refusing before core checks the nonce; nothing is saved here.
		if ( self::is_redirect( $_POST['post_ID'] ?? 0 ) ) {
			self::refuse();
		}
	}

	/**
	 * Refuse any post.php action on a redirect except trash, restore and delete.
	 *
	 * Core's edit screen action is read from $_REQUEST, and a delete button or
	 * preview overrides it, as core does. The post can come from any of the
	 * fields core and edit_post() read, and a new post from its post type.
	 *
	 * @return void
	 */
	public function refuse_edit_screen(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Refusing before core checks the nonce; nothing is saved here.
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( isset( $_POST['deletepost'] ) ) {
			$action = 'delete';
		} elseif ( isset( $_POST['wp-preview'] ) && 'dopreview' === $_POST['wp-preview'] ) {
			$action = 'preview';
		}

		if ( in_array( $action, self::ALLOWED_POST_ACTIONS, true ) ) {
			return;
		}

		$targets_redirect = ( isset( $_REQUEST['post_type'] ) && PostType::POST_TYPE === sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) )
			|| self::is_redirect( $_GET['post'] ?? 0 )
			|| self::is_redirect( $_GET['post_ID'] ?? 0 )
			|| self::is_redirect( $_POST['post_ID'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( $targets_redirect ) {
			self::refuse();
		}
	}

	/**
	 * Refuse a Bulk Edit that includes a redirect.
	 *
	 * Core's bulk_edit_posts() runs from any post list screen and takes the
	 * IDs as given, so a redirect can be in a list for another post type.
	 *
	 * @return void
	 */
	public function refuse_bulk_edit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Refusing before core checks the nonce; nothing is saved here.
		if ( ! isset( $_REQUEST['bulk_edit'], $_REQUEST['post'] ) ) {
			return;
		}

		foreach ( (array) $_REQUEST['post'] as $post_id ) {
			if ( self::is_redirect( $post_id ) ) {
				self::refuse();
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Deny edit_post on a redirect to XML-RPC clients.
	 *
	 * @param string[] $caps    The primitive capabilities the meta capability maps to.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user ID.
	 * @param mixed[]  $args    The capability's arguments; for edit_post, the post.
	 * @return string[] The primitive capabilities.
	 */
	public function refuse_xmlrpc_edit( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'edit_post' === $cap && isset( $args[0] ) && PostType::POST_TYPE === get_post_type( $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * Whether a requested post ID is a redirect.
	 *
	 * @param mixed $post_id A post ID from the request.
	 * @return bool
	 */
	private static function is_redirect( mixed $post_id ): bool {
		$post_id = is_scalar( $post_id ) ? (int) $post_id : 0;

		return 0 < $post_id && PostType::POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * End the request with a 403.
	 *
	 * @return void
	 */
	private static function refuse(): void {
		wp_die(
			esc_html__( 'Redirects can only be edited from the Redirects screens.', 'legacy-redirector' ),
			'',
			array( 'response' => 403 )
		);
	}
}
