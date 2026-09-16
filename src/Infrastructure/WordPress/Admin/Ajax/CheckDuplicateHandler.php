<?php
/**
 * AJAX handler for checking duplicate source URLs.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Handles AJAX requests to check if a source URL already has a redirect.
 */
final class CheckDuplicateHandler {

	private const ACTION = 'check_redirect_duplicate';

	/**
	 * Repository for redirect lookups.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 */
	public function __construct( RedirectRepositoryInterface $repository ) {
		$this->repository = $repository;
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

		$redirect_from = isset( $_POST['redirect_from'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_from'] ) ) : '';
		$exclude_id    = isset( $_POST['exclude_id'] ) ? absint( $_POST['exclude_id'] ) : 0;

		if ( empty( $redirect_from ) ) {
			wp_send_json_success( array( 'exists' => false ) );
		}

		try {
			$source   = SourceUrl::from_string( $redirect_from, HomePath::current() );
			$existing = $this->repository->get_id_by_source( $source );

			$exists = $existing > 0 && $existing !== $exclude_id;
			wp_send_json_success( array( 'exists' => $exists ) );
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_success( array( 'exists' => false ) );
		}
	}
}
