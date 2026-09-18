<?php
/**
 * AJAX handler for validating redirect destinations.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Handles AJAX requests to validate redirect destinations.
 *
 * Reads from the auditor, so this action, the To column, and the `validate`
 * CLI command report the same findings for the same redirect.
 */
final class ValidateRedirectHandler {

	private const string ACTION = 'validate_redirect';

	/**
	 * Repository for redirect lookups.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Auditor for redirect health.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 * @param RedirectAuditor             $auditor    Redirect auditor.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectAuditor $auditor ) {
		$this->repository = $repository;
		$this->auditor    = $auditor;
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'legacy-redirector' ) ) );
		}

		$redirect_id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;

		if ( ! $redirect_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid redirect ID.', 'legacy-redirector' ) ) );
		}

		$redirect = $this->repository->find_by_id( $redirect_id );

		if ( null === $redirect ) {
			wp_send_json_error(
				array(
					'status'  => 'not-found',
					'message' => __( 'Redirect not found.', 'legacy-redirector' ),
				)
			);
		}

		$findings = $this->auditor->audit( $redirect, true );
		$problems = array();
		$warnings = array();

		foreach ( $findings as $finding ) {
			if ( $finding->is_warning() ) {
				$warnings[] = array(
					'label'       => $finding->label(),
					'description' => $finding->description() . '.',
				);
			} else {
				$problems[] = $finding;
			}
		}

		// A warning does not fail the redirect - it works, but a person should
		// look - so it rides along rather than turning the result red.
		if ( array() !== $problems ) {
			$finding = reset( $problems );

			wp_send_json_error(
				array(
					'status'   => $finding->type()->value,
					'message'  => $finding->description() . '.',
					'warnings' => $warnings,
				)
			);
		}

		wp_send_json_success(
			array(
				'status'   => 'valid',
				'message'  => __( 'Redirect is valid.', 'legacy-redirector' ),
				'warnings' => $warnings,
			)
		);
	}
}
