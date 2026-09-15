<?php
/**
 * Redirect form page for add and edit operations.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckDuplicateHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\SearchPostsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles the Add and Edit redirect admin pages.
 */
final class RedirectFormPage {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

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
	 * @param RedirectManager             $manager    Redirect manager.
	 * @param RedirectValidator           $validator  Redirect validator.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectManager $manager, RedirectValidator $validator ) {
		$this->repository = $repository;
		$this->manager    = $manager;
		$this->validator  = $validator;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'load-post-new.php', array( $this, 'redirect_post_new' ) );
		add_action( 'load-post.php', array( $this, 'redirect_post_edit' ) );
		add_action( 'admin_post_save_redirect', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the form script on the Add/Edit Redirect pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$form_pages = array(
			PostType::POST_TYPE . '_page_add-redirect',
			PostType::POST_TYPE . '_page_edit-redirect',
		);

		if ( ! in_array( $hook_suffix, $form_pages, true ) ) {
			return;
		}

		wp_enqueue_script(
			'wpcom-legacy-redirector-form',
			plugins_url( 'js/admin-redirect-form.js', \Automattic\LegacyRedirector\PLUGIN_FILE ),
			array( 'jquery' ),
			\Automattic\LegacyRedirector\VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading redirect_id for the duplicate-check exclusion only.
		$redirect_id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		wp_localize_script(
			'wpcom-legacy-redirector-form',
			'wpcomLegacyRedirectorForm',
			array(
				'postId'           => $redirect_id,
				'checkAction'      => CheckDuplicateHandler::get_action(),
				'checkNonce'       => wp_create_nonce( CheckDuplicateHandler::get_action() ),
				'searchAction'     => SearchPostsHandler::get_action(),
				'searchNonce'      => wp_create_nonce( SearchPostsHandler::get_action() ),
				'duplicateMessage' => __( 'A redirect already exists for this source URL.', 'wpcom-legacy-redirector' ),
			)
		);
	}

	/**
	 * Register admin pages.
	 *
	 * @return void
	 */
	public function register_pages(): void {
		// Add Redirect page.
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Add Redirect', 'wpcom-legacy-redirector' ),
			__( 'Add Redirect', 'wpcom-legacy-redirector' ),
			Capability::MANAGE_REDIRECTS_CAPABILITY,
			'add-redirect',
			array( $this, 'render_add_page' )
		);

		// Edit Redirect page (hidden from menu).
		add_submenu_page(
			'',
			__( 'Edit Redirect', 'wpcom-legacy-redirector' ),
			__( 'Edit Redirect', 'wpcom-legacy-redirector' ),
			Capability::MANAGE_REDIRECTS_CAPABILITY,
			'edit-redirect',
			array( $this, 'render_edit_page' )
		);
	}

	/**
	 * Redirect from post-new.php to our Add Redirect page.
	 *
	 * @return void
	 */
	public function redirect_post_new(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking post type for redirect.
		if ( isset( $_GET['post_type'] ) && PostType::POST_TYPE === $_GET['post_type'] ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=add-redirect' ) );
			exit;
		}
	}

	/**
	 * Redirect from post.php edit to our Edit Redirect page.
	 *
	 * @return void
	 */
	public function redirect_post_edit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for redirect.
		if ( ! isset( $_GET['post'] ) || ! isset( $_GET['action'] ) || 'edit' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking post type for redirect.
		$post = get_post( absint( $_GET['post'] ) );
		if ( ! $post || PostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		wp_safe_redirect(
			admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $post->ID )
		);
		exit;
	}


	/**
	 * Render the Add Redirect page.
	 *
	 * @return void
	 */
	public function render_add_page(): void {
		$this->render_form_page( null );
	}

	/**
	 * Render the Edit Redirect page.
	 *
	 * @return void
	 */
	public function render_edit_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading redirect_id for display.
		$redirect_id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		if ( ! $redirect_id ) {
			wp_die( esc_html__( 'Invalid redirect ID.', 'wpcom-legacy-redirector' ) );
		}

		$post = get_post( $redirect_id );
		if ( ! $post || PostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Redirect not found.', 'wpcom-legacy-redirector' ) );
		}

		$this->render_form_page( $post );
	}

	/**
	 * Render the redirect form page.
	 *
	 * @param \WP_Post|null $post The post object for edit, null for add.
	 * @return void
	 */
	private function render_form_page( ?\WP_Post $post ): void {
		$is_edit = null !== $post;
		$title   = $is_edit ? __( 'Edit Redirect', 'wpcom-legacy-redirector' ) : __( 'Add Redirect', 'wpcom-legacy-redirector' );

		// Get current values.
		$redirect_from       = '';
		$redirect_status     = 'publish';
		$destination_value   = '';
		$destination_display = '';

		if ( $is_edit ) {
			// Editing existing redirect - get values from post.
			$redirect_from   = $post->post_title;
			$redirect_status = $post->post_status;
			$excerpt         = $post->post_excerpt;
			$post_parent     = $post->post_parent;

			if ( ! empty( $excerpt ) ) {
				$destination_value   = $excerpt;
				$destination_display = $excerpt;
			} elseif ( $post_parent > 0 ) {
				$destination_value = $post_parent;
				$parent_post       = get_post( $post_parent );
				if ( $parent_post ) {
					$destination_display = get_the_title( $parent_post ) . ' (ID: ' . $post_parent . ')';
				} else {
					$destination_display = (string) $post_parent;
				}
			}
		} else {
			// Adding new redirect - check for preserved values from validation error.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading preserved form values for display.
			if ( isset( $_GET['redirect_from'] ) ) {
				$redirect_from = sanitize_text_field( wp_unslash( $_GET['redirect_from'] ) );
			}
			if ( isset( $_GET['redirect_to'] ) ) {
				$destination_value   = sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) );
				$destination_display = $destination_value;
			}
			if ( isset( $_GET['redirect_status'] ) && in_array( $_GET['redirect_status'], array( 'publish', 'draft' ), true ) ) {
				$redirect_status = sanitize_text_field( wp_unslash( $_GET['redirect_status'] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		// Check for success/error messages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading message params for display.
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading error params for display.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$post_id       = $is_edit ? $post->ID : 0;
		$error_message = '' !== $error ? $this->get_error_message( $error ) : '';

		// Sources are stored relative to this site's home URL, which on a
		// subdirectory subsite is not the domain root. Showing the home URL
		// against the field makes which root the path hangs off self-evident,
		// and matches the "Test it" link, which is built the same way.
		$home_prefix = trailingslashit( home_url() );

		include __DIR__ . '/views/redirect-form.php';
	}



	/**
	 * Handle the save redirect form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		// Verify nonce.
		if ( ! isset( $_POST['redirect_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['redirect_nonce'] ) ), 'save_redirect' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpcom-legacy-redirector' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'wpcom-legacy-redirector' ) );
		}

		// Get form values.
		$redirect_id     = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		$redirect_from   = isset( $_POST['redirect_from'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_from'] ) ) : '';
		$redirect_to     = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$redirect_status = isset( $_POST['redirect_status'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_status'] ) ) : 'publish';

		$is_edit = $redirect_id > 0;

		// Validate required fields.
		if ( empty( $redirect_from ) || empty( $redirect_to ) ) {
			$this->redirect_with_error( $redirect_id, 'empty_fields', $redirect_from, $redirect_to, $redirect_status );
		}

		// Validate status.
		if ( ! in_array( $redirect_status, array( 'publish', 'draft' ), true ) ) {
			$redirect_status = 'publish';
		}

		// Create destination object.
		try {
			$destination = Destination::from_mixed(
				is_numeric( $redirect_to ) ? (int) $redirect_to : $redirect_to
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->redirect_with_error( $redirect_id, 'invalid_destination', $redirect_from, $redirect_to, $redirect_status );
		}

		// Create source URL object.
		try {
			$source = SourceUrl::from_string( $redirect_from );
		} catch ( \InvalidArgumentException $e ) {
			$this->redirect_with_error( $redirect_id, 'invalid_source', $redirect_from, $redirect_to, $redirect_status );
		}

		// Apply the full rule set - duplicate source, self-redirect loop, and
		// destination - up front, so the form can name what went wrong. The
		// manager validates again on save; that is the backstop for the
		// non-admin write paths, not this one.
		if ( $is_edit ) {
			$existing = $this->repository->find_by_id( $redirect_id );
			if ( null === $existing ) {
				$this->redirect_with_error( $redirect_id, 'save_failed', $redirect_from, $redirect_to, $redirect_status );
			}

			$proposed = $existing->with_source( $source )->with_destination( $destination );
		} else {
			$proposed = Redirect::create( $source, $destination );
		}

		$validation = $this->validator->validate( $proposed );

		if ( $validation->is_invalid() ) {
			$this->redirect_with_error( $redirect_id, self::form_error_for( $validation->error_code() ), $redirect_from, $redirect_to, $redirect_status );
		}

		// Reachability check for relative destinations only: a path with no
		// post to find (an archive, a rewrite endpoint, a mistyped slug)
		// passes the lookup above as indeterminate, so ask the site directly.
		// External URLs keep their pure format check, and this runs after the
		// local rejections above so a malformed or duplicate source never
		// pays for a network round-trip. Fails open - only an affirmative
		// 404 rejects, so a transient network error never blocks a save.
		if ( $destination->is_url() && $destination->as_url()->is_relative() && $this->should_check_reachability() ) {
			$http_validation = $this->validator->validate_destination_not_404( $destination );
			if ( $http_validation->is_invalid() ) {
				$this->redirect_with_error( $redirect_id, 'path_not_found', $redirect_from, $redirect_to, $redirect_status );
			}
		}

		if ( $is_edit ) {
			// Update existing redirect.
			$success = $this->manager->update_redirect( $redirect_id, $redirect_from, $destination, $redirect_status );

			if ( ! $success ) {
				$this->redirect_with_error( $redirect_id, 'save_failed', $redirect_from, $redirect_to, $redirect_status );
			}

			wp_safe_redirect(
				admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect_id . '&message=updated' )
			);
			exit;
		} else {
			// Create new redirect using the manager service.
			$result = $this->manager->create_redirect( $source, $destination, true, $redirect_status );

			if ( $result->is_error() ) {
				$this->redirect_with_error( 0, self::form_error_for( $result->error_code() ), $redirect_from, $redirect_to, $redirect_status );
			}

			$redirect_id = $result->redirect_id();

			wp_safe_redirect(
				admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect_id . '&message=created' )
			);
			exit;
		}
	}

	/**
	 * Whether the save flow should verify destination reachability over HTTP.
	 *
	 * @return bool True to perform the HTTP check.
	 */
	private function should_check_reachability(): bool {
		/**
		 * Filters whether the form save verifies relative destinations over HTTP.
		 *
		 * The check rejects destinations the site serves with a 404. Disable
		 * it on sites whose valid destinations 404 anonymously - members-only
		 * content, geo-gated pages, or content staged for launch.
		 *
		 * @param bool $check Whether to perform the check. Default true.
		 */
		return (bool) apply_filters( 'wpcom_legacy_redirector_check_destination_reachability', true );
	}

	/**
	 * Translate an application error code into a form error code.
	 *
	 * @param string|null $error_code The validator or manager error code.
	 * @return string The form error code.
	 */
	private static function form_error_for( ?string $error_code ): string {
		$map = array(
			'duplicate-redirect-uri' => 'duplicate',
			'invalid-values'         => 'same_source_destination',
			'empty-postid'           => 'post_not_found',
			'non-public'             => 'post_not_public',
			'insert-not-allowed'     => 'save_failed',
			'save-failed'            => 'save_failed',
		);

		return $map[ (string) $error_code ] ?? 'invalid_destination';
	}

	/**
	 * Redirect with an error message, preserving form values.
	 *
	 * @param int    $redirect_id The redirect ID (0 for add page).
	 * @param string $error       The error code.
	 * @param string $from        The redirect from value to preserve.
	 * @param string $to          The redirect to value to preserve.
	 * @param string $status      The redirect status to preserve.
	 * @return never
	 */
	private function redirect_with_error( int $redirect_id, string $error, string $from = '', string $to = '', string $status = 'publish' ): void {
		if ( $redirect_id > 0 ) {
			$url = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect_id . '&error=' . $error );
		} else {
			$url = add_query_arg(
				array(
					'post_type'       => PostType::POST_TYPE,
					'page'            => 'add-redirect',
					'error'           => $error,
					'redirect_from'   => rawurlencode( $from ),
					'redirect_to'     => rawurlencode( $to ),
					'redirect_status' => $status,
				),
				admin_url( 'edit.php' )
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Get error message for error code.
	 *
	 * @param string $error The error code.
	 * @return string The error message.
	 */
	private function get_error_message( string $error ): string {
		$messages = array(
			'empty_fields'            => __( 'Redirect From and Redirect To are required fields.', 'wpcom-legacy-redirector' ),
			'invalid_destination'     => __( 'The destination is not valid.', 'wpcom-legacy-redirector' ),
			'invalid_source'          => __( 'The source URL is not valid.', 'wpcom-legacy-redirector' ),
			'duplicate'               => __( 'A redirect already exists for this source URL.', 'wpcom-legacy-redirector' ),
			'same_source_destination' => __( 'Redirect From and Redirect To must not be the same. This would create a redirect loop.', 'wpcom-legacy-redirector' ),
			'save_failed'             => __( 'Failed to save the redirect. Please try again.', 'wpcom-legacy-redirector' ),
			'post_not_found'          => __( 'The destination post ID does not exist.', 'wpcom-legacy-redirector' ),
			'post_not_public'         => __( 'The destination post is not published.', 'wpcom-legacy-redirector' ),
			'path_not_found'          => __( 'The destination path does not exist.', 'wpcom-legacy-redirector' ),
		);

		return $messages[ $error ] ?? __( 'An error occurred.', 'wpcom-legacy-redirector' );
	}
}
