<?php
/**
 * Handles validation notices and the non-AJAX validation action.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles validation notices and the fallback validation action.
 */
final class ValidationNotices {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Redirect validator.
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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display_validation_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_validation_action' ) );
		add_filter( 'removable_query_args', array( $this, 'add_removable_args' ) );
	}

	/**
	 * Add query args that should be removed after displaying notices.
	 *
	 * @param array<string> $args Existing removable args.
	 * @return array<string> Modified args.
	 */
	public function add_removable_args( array $args ): array {
		$args[] = 'validate';
		$args[] = 'ids';
		return $args;
	}

	/**
	 * Display validation notices.
	 *
	 * @return void
	 */
	public function display_validation_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		if ( ! isset( $_GET['validate'] ) ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		// Get redirect details for context in the notice.
		$redirect_context = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		if ( isset( $_GET['ids'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
			$post = get_post( absint( $_GET['ids'] ) );
			if ( $post instanceof \WP_Post && PostType::POST_TYPE === $post->post_type ) {
				$redirect_context = sprintf(
					/* translators: %s: source URL path */
					' ' . __( 'for %s', 'wpcom-legacy-redirector' ),
					'<code>' . esc_html( $post->post_title ) . '</code>'
				);
			}
		}

		$redirect_not_valid_text = __( 'Redirect is not valid', 'wpcom-legacy-redirector' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		switch ( $_GET['validate'] ) {
			case 'invalid':
				echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html( $redirect_not_valid_text ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html__( 'If you are doing an external redirect, make sure you safelist the domain using the "allowed_redirect_hosts" filter.', 'wpcom-legacy-redirector' ) . '</p></div>';
				break;
			case '404':
				echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html( $redirect_not_valid_text ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html__( 'Redirect is pointing to a page with the HTTP status of 404.', 'wpcom-legacy-redirector' ) . '</p></div>';
				break;
			case 'valid':
				/* translators: %s: context showing which redirect (e.g. "for /old-page") */
				$message = sprintf( __( 'Redirect is valid%s.', 'wpcom-legacy-redirector' ), $redirect_context );
				echo '<div id="message" class="updated notice is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
				break;
			case 'private':
				echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html( $redirect_not_valid_text ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html__( 'The redirect is pointing to content that is not publicly accessible.', 'wpcom-legacy-redirector' ) . '</p></div>';
				break;
			case 'null':
				echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html( $redirect_not_valid_text ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html__( 'The redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' ) . '</p></div>';
				break;
		}
	}

	/**
	 * Handle the non-AJAX validation action.
	 *
	 * @return void
	 */
	public function handle_validation_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET['action'] ) || 'validate' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET['post'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		$post_id = absint( $_GET['post'] );
		if ( ! $post_id ) {
			return;
		}

		if ( ! isset( $_REQUEST['_validate_redirect'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_validate_redirect'] ) ), 'validate_vip_legacy_redirect' ) ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to validate redirects.', 'wpcom-legacy-redirector' ) );
		}

		$redirect = $this->repository->find_by_id( $post_id );

		if ( null === $redirect ) {
			$this->redirect_with_result( 'null', $post_id );
			return;
		}

		$destination = $redirect->destination();

		// Validate the destination exists and is accessible.
		$validation_result = $this->validator->validate_destination( $destination );

		if ( $validation_result->is_invalid() ) {
			$error_code = $validation_result->error_code();

			// Map validator error codes to UI status codes.
			$status_map = array(
				'empty-postid'             => 'null',
				'non-public'               => 'private',
				'external-url-not-allowed' => 'invalid',
				'invalid'                  => 'invalid',
			);

			$ui_status = $status_map[ $error_code ] ?? 'invalid';
			$this->redirect_with_result( $ui_status, $post_id );
			return;
		}

		// Check if destination returns 404 via HTTP request.
		$http_result = $this->validator->validate_destination_not_404( $destination );

		if ( $http_result->is_invalid() ) {
			$this->redirect_with_result( '404', $post_id );
			return;
		}

		// All checks passed - redirect is valid.
		$this->redirect_with_result( 'valid', $post_id );
	}

	/**
	 * Redirect back with validation result.
	 *
	 * @param string $validate Result status code.
	 * @param int    $post_id  The post ID.
	 * @return void
	 */
	private function redirect_with_result( string $validate, int $post_id ): void {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );
		}
		$sendback = remove_query_arg( array( 'validate', 'ids' ), $referer );

		wp_safe_redirect(
			add_query_arg(
				array(
					'validate' => $validate,
					'ids'      => $post_id,
				),
				$sendback
			)
		);
		exit();
	}
}
