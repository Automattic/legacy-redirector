<?php
/**
 * AJAX handler for checking a redirect destination as it is entered.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Handles AJAX requests to check a destination before the form is submitted:
 * an external host missing from allowed_redirect_hosts will be refused at
 * save, so the form flags it as soon as the field loses focus.
 */
final class CheckDestinationHandler {

	private const string ACTION = 'check_redirect_destination';

	/**
	 * Auditor, which owns the destination rules.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectAuditor $auditor Redirect auditor.
	 */
	public function __construct( RedirectAuditor $auditor ) {
		$this->auditor = $auditor;
	}

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

		$redirect_to = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $redirect_to ) {
			wp_send_json_success( array( 'host_allowed' => true ) );
		}

		try {
			$destination = Destination::from_mixed(
				is_numeric( $redirect_to ) ? (int) $redirect_to : $redirect_to
			);
		} catch ( \InvalidArgumentException $e ) {
			// A malformed destination is the submit-time validation's report to
			// make; this check only answers the allowed-host question.
			wp_send_json_success( array( 'host_allowed' => true ) );
			return;
		}

		wp_send_json_success(
			array(
				'host_allowed' => ! in_array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, $this->auditor->destination_checks( $destination ), true ),
			)
		);
	}
}
