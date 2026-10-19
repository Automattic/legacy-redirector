<?php
/**
 * Status change notices for redirects.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading URL param for notice display; escaped on output, where sanitize_text_field() would delete %xx sequences.
		$redirect_source = isset( $_GET['redirect_source'] ) ? wp_unslash( $_GET['redirect_source'] ) : '';

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

		// Rows a bulk action could not change, and why the first one failed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading URL params for notice display.
		$failed = isset( $_GET['bulk_redirects_failed'] ) ? absint( $_GET['bulk_redirects_failed'] ) : 0;
		$reason = isset( $_GET['bulk_redirects_reason'] ) && 'duplicate' === $_GET['bulk_redirects_reason'] ? 'duplicate' : 'failed';

		// Bulk redirects enabled.
		if ( isset( $_GET['bulk_redirects_enabled'] ) ) {
			$count = absint( $_GET['bulk_redirects_enabled'] );
			$this->display_bulk_enabled_notice( $count, $failed, $reason );
		}

		// Bulk redirects disabled.
		if ( isset( $_GET['bulk_redirects_disabled'] ) ) {
			$count = absint( $_GET['bulk_redirects_disabled'] );
			$this->display_bulk_disabled_notice( $count, $failed, $reason );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
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
	 * @param int    $count  Number of redirects enabled.
	 * @param int    $failed Number of selected redirects that could not be enabled.
	 * @param string $reason Why the first of them failed: 'duplicate' or 'failed'.
	 * @return void
	 */
	private function display_bulk_enabled_notice( int $count, int $failed, string $reason ): void {
		if ( $failed > 0 ) {
			$this->display_bulk_refusal_notice(
				sprintf(
					/* translators: 1: number of redirects enabled, 2: number selected, 3: number that could not be enabled */
					_n( 'Enabled %1$d of %2$d redirect. %3$d could not be enabled.', 'Enabled %1$d of %2$d redirects. %3$d could not be enabled.', $count + $failed, 'legacy-redirector' ),
					$count,
					$count + $failed,
					$failed
				),
				$failed,
				$reason
			);
			return;
		}

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
	 * @param int    $count  Number of redirects disabled.
	 * @param int    $failed Number of selected redirects that could not be disabled.
	 * @param string $reason Why the first of them failed: 'duplicate' or 'failed'.
	 * @return void
	 */
	private function display_bulk_disabled_notice( int $count, int $failed, string $reason ): void {
		if ( $failed > 0 ) {
			$this->display_bulk_refusal_notice(
				sprintf(
					/* translators: 1: number of redirects disabled, 2: number selected, 3: number that could not be disabled */
					_n( 'Disabled %1$d of %2$d redirect. %3$d could not be disabled.', 'Disabled %1$d of %2$d redirects. %3$d could not be disabled.', $count + $failed, 'legacy-redirector' ),
					$count,
					$count + $failed,
					$failed
				),
				$failed,
				$reason
			);
			return;
		}

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

	/**
	 * Display a warning for a bulk action that could not change every row.
	 *
	 * @param string $summary How many changed out of how many selected.
	 * @param int    $failed  Number of selected redirects that could not be changed.
	 * @param string $reason  Why the first of them failed: 'duplicate' or 'failed'.
	 * @return void
	 */
	private function display_bulk_refusal_notice( string $summary, int $failed, string $reason ): void {
		if ( 'duplicate' === $reason ) {
			$why = sprintf(
				/* translators: %s: link to the Duplicate sources view */
				esc_html( _n( 'It shares its source with a live redirect, and a source can answer for only one: see %s.', 'The first shares its source with a live redirect, and a source can answer for only one: see %s.', $failed, 'legacy-redirector' ) ),
				'<a href="' . esc_url( ViewFilters::duplicate_sources_url() ) . '">' . esc_html__( 'Duplicate sources', 'legacy-redirector' ) . '</a>'
			);
		} else {
			$why = esc_html( _n( 'It could not be saved.', 'The first could not be saved.', $failed, 'legacy-redirector' ) );
		}

		wp_admin_notice(
			wp_kses( esc_html( $summary ) . ' ' . $why, array( 'a' => array( 'href' => array() ) ) ),
			array( 'type' => 'warning' ) + self::NOTICE_ARGS
		);
	}
}
