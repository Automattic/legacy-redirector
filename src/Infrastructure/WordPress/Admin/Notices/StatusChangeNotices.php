<?php
/**
 * Status change notices for redirects.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Displays admin notices after redirect status changes (enable/disable).
 */
final class StatusChangeNotices {

	/**
	 * Notice arguments shared by every status change notice.
	 *
	 * The `message` ID is the identifier core's list tables have always used
	 * for this slot, so it is kept for anything styling or scripting against it.
	 *
	 * @var array<string, bool|string>
	 */
	private const array NOTICE_ARGS = array(
		'type'        => 'success',
		'id'          => 'message',
		'dismissible' => true,
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display' ) );
	}

	/**
	 * Display admin notices for status changes.
	 *
	 * @return void
	 */
	public function display(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}

		// Get the redirect source if provided.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		$redirect_source = isset( $_GET['redirect_source'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['redirect_source'] ) ) ) : '';

		// Single redirect enabled.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		if ( isset( $_GET['redirect_enabled'] ) && '1' === $_GET['redirect_enabled'] ) {
			$this->display_single_enabled_notice( $redirect_source );
		}

		// Single redirect disabled.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		if ( isset( $_GET['redirect_disabled'] ) && '1' === $_GET['redirect_disabled'] ) {
			$this->display_single_disabled_notice( $redirect_source );
		}

		// Bulk redirects enabled.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		if ( isset( $_GET['bulk_redirects_enabled'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
			$count = absint( $_GET['bulk_redirects_enabled'] );
			$this->display_bulk_enabled_notice( $count );
		}

		// Bulk redirects disabled.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		if ( isset( $_GET['bulk_redirects_disabled'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
			$count = absint( $_GET['bulk_redirects_disabled'] );
			$this->display_bulk_disabled_notice( $count );
		}
	}

	/**
	 * Display notice for single redirect enabled.
	 *
	 * @param string $redirect_source The redirect source path.
	 * @return void
	 */
	private function display_single_enabled_notice( string $redirect_source ): void {
		if ( '' === $redirect_source ) {
			wp_admin_notice( esc_html__( 'Redirect enabled.', 'legacy-redirector' ), self::NOTICE_ARGS );
			return;
		}

		$message = sprintf(
			/* translators: %s: redirect source path */
			__( 'Redirect from %s enabled.', 'legacy-redirector' ),
			'<code>' . esc_html( $redirect_source ) . '</code>'
		);
		wp_admin_notice( wp_kses( $message, array( 'code' => array() ) ), self::NOTICE_ARGS );
	}

	/**
	 * Display notice for single redirect disabled.
	 *
	 * @param string $redirect_source The redirect source path.
	 * @return void
	 */
	private function display_single_disabled_notice( string $redirect_source ): void {
		if ( '' === $redirect_source ) {
			wp_admin_notice( esc_html__( 'Redirect disabled.', 'legacy-redirector' ), self::NOTICE_ARGS );
			return;
		}

		$message = sprintf(
			/* translators: %s: redirect source path */
			__( 'Redirect from %s disabled.', 'legacy-redirector' ),
			'<code>' . esc_html( $redirect_source ) . '</code>'
		);
		wp_admin_notice( wp_kses( $message, array( 'code' => array() ) ), self::NOTICE_ARGS );
	}

	/**
	 * Display notice for bulk redirects enabled.
	 *
	 * @param int $count Number of redirects enabled.
	 * @return void
	 */
	private function display_bulk_enabled_notice( int $count ): void {
		wp_admin_notice(
			esc_html(
				sprintf(
					/* translators: %d: number of redirects enabled */
					_n( '%d redirect enabled.', '%d redirects enabled.', $count, 'legacy-redirector' ),
					$count
				)
			),
			self::NOTICE_ARGS
		);
	}

	/**
	 * Display notice for bulk redirects disabled.
	 *
	 * @param int $count Number of redirects disabled.
	 * @return void
	 */
	private function display_bulk_disabled_notice( int $count ): void {
		wp_admin_notice(
			esc_html(
				sprintf(
					/* translators: %d: number of redirects disabled */
					_n( '%d redirect disabled.', '%d redirects disabled.', $count, 'legacy-redirector' ),
					$count
				)
			),
			self::NOTICE_ARGS
		);
	}
}
