<?php
/**
 * Status actions handler for redirects.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles single redirect enable/disable actions from row actions.
 */
final class StatusActionsHandler {

	/**
	 * Redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager Redirect manager.
	 */
	public function __construct( RedirectManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_enable_redirect', array( $this, 'handle_enable_redirect' ) );
		add_action( 'admin_post_disable_redirect', array( $this, 'handle_disable_redirect' ) );
	}

	/**
	 * Handle the enable redirect action.
	 *
	 * @return void
	 */
	public function handle_enable_redirect(): void {
		$this->handle_redirect_status_change( 'publish' );
	}

	/**
	 * Handle the disable redirect action.
	 *
	 * @return void
	 */
	public function handle_disable_redirect(): void {
		$this->handle_redirect_status_change( 'draft' );
	}

	/**
	 * Handle changing redirect status.
	 *
	 * @param string $new_status The new post status ('publish' or 'draft').
	 * @return void
	 */
	private function handle_redirect_status_change( string $new_status ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_GET['redirect_id'] ) ) {
			wp_die( esc_html__( 'No redirect specified.', 'wpcom-legacy-redirector' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$redirect_id = absint( $_GET['redirect_id'] );
		$action      = 'publish' === $new_status ? 'enable_redirect' : 'disable_redirect';

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action . '_' . $redirect_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpcom-legacy-redirector' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to modify redirects.', 'wpcom-legacy-redirector' ) );
		}

		// Get the redirect source before modifying. RedirectManager rejects posts
		// of other types too, but this handler reads the title before calling it,
		// so it checks the type itself rather than relying on that.
		$redirect_post = get_post( $redirect_id );
		if ( ! $redirect_post || PostType::POST_TYPE !== $redirect_post->post_type ) {
			wp_die( esc_html__( 'Invalid redirect.', 'wpcom-legacy-redirector' ) );
		}
		$redirect_source = $redirect_post->post_title;

		// Perform the status change.
		$success = 'publish' === $new_status
			? $this->manager->enable( $redirect_id )
			: $this->manager->disable( $redirect_id );

		if ( ! $success ) {
			wp_die( esc_html__( 'Invalid redirect.', 'wpcom-legacy-redirector' ) );
		}

		// Redirect back to the list.
		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );
		}

		$sendback = remove_query_arg(
			array( 'action', 'redirect_id', '_wpnonce', 'redirect_enabled', 'redirect_disabled', 'redirect_source', 'bulk_redirects_enabled', 'bulk_redirects_disabled' ),
			$referer
		);

		$status_label = 'publish' === $new_status ? 'enabled' : 'disabled';

		wp_safe_redirect(
			add_query_arg(
				array(
					'redirect_' . $status_label => 1,
					'redirect_source'           => rawurlencode( $redirect_source ),
				),
				$sendback
			)
		);
		exit;
	}
}
