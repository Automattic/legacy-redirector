<?php
/**
 * AJAX handler for validating redirect destinations.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Handles AJAX requests to validate redirect destinations.
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
	 * Validator for redirect destinations.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 * @param RedirectValidator           $validator  Redirect validator.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectValidator $validator ) {
		$this->repository = $repository;
		$this->validator  = $validator;
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
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wpcom-legacy-redirector' ) ) );
		}

		$redirect_id = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;

		if ( ! $redirect_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid redirect ID.', 'wpcom-legacy-redirector' ) ) );
		}

		$redirect = $this->repository->find_by_id( $redirect_id );

		if ( null === $redirect ) {
			wp_send_json_error(
				array(
					'status'  => 'null',
					'message' => __( 'The redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' ),
				)
			);
		}

		$destination = $redirect->destination();

		// Validate the destination exists and is accessible.
		$validation_result = $this->validator->validate_destination( $destination );

		if ( $validation_result->is_invalid() ) {
			$error_code = $validation_result->error_code();

			// Map validator error codes to user-friendly messages.
			$messages = array(
				'empty-postid'   => __( 'The redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' ),
				'non-public'     => __( 'The redirect is pointing to content that is not publicly accessible.', 'wpcom-legacy-redirector' ),
				'invalid-url'    => __( 'The URL is not valid. External URLs must include the scheme (http:// or https://).', 'wpcom-legacy-redirector' ),
				'invalid-scheme' => __( 'Only http and https URLs are supported.', 'wpcom-legacy-redirector' ),
				'invalid'        => __( 'The redirect destination URL does not exist.', 'wpcom-legacy-redirector' ),
			);

			$message = $messages[ $error_code ] ?? __( 'The redirect is not valid.', 'wpcom-legacy-redirector' );

			wp_send_json_error(
				array(
					'status'  => $error_code,
					'message' => $message,
				)
			);
		}

		// Check if destination returns 404 via HTTP request.
		$http_result = $this->validator->validate_destination_not_404( $destination );

		if ( $http_result->is_invalid() ) {
			wp_send_json_error(
				array(
					'status'  => '404',
					'message' => __( 'The redirect destination returns a 404 error.', 'wpcom-legacy-redirector' ),
				)
			);
		}

		// All checks passed - redirect is valid.
		wp_send_json_success(
			array(
				'status'  => 'valid',
				'message' => __( 'Redirect is valid.', 'wpcom-legacy-redirector' ),
			)
		);
	}
}
