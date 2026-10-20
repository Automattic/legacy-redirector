<?php
/**
 * Redirect form page for add and edit operations.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
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
	 * Redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 * @param RedirectManager             $manager    Redirect manager.
	 * @param RedirectValidator           $validator  Redirect validator.
	 * @param RedirectAuditor             $auditor    Redirect auditor.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectManager $manager, RedirectValidator $validator, RedirectAuditor $auditor ) {
		$this->repository = $repository;
		$this->manager    = $manager;
		$this->validator  = $validator;
		$this->auditor    = $auditor;
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
			'legacy-redirector-form',
			plugins_url( 'js/admin-redirect-form.js', \Automattic\LegacyRedirector\PLUGIN_FILE ),
			array( 'jquery', 'wp-a11y', 'wp-api-fetch' ),
			\Automattic\LegacyRedirector\VERSION,
			true
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading redirect_id for the duplicate-check exclusion only.
		$redirect_id = isset( $_GET['redirect_id'] ) ? absint( $_GET['redirect_id'] ) : 0;

		wp_localize_script(
			'legacy-redirector-form',
			'legacyRedirectorForm',
			array(
				'postId'                => $redirect_id,
				'duplicateMessage'      => __( 'A redirect already exists for this source URL.', 'legacy-redirector' ),
				'reservedMessage'       => self::reserved_source_message(),
				'occupiedMessage'       => self::occupied_source_message(),
				'hostNotAllowedMessage' => self::host_not_allowed_message(),
				'suggestionsMessage'    => __( 'Suggestions available. Use the up and down arrow keys to choose one, and Enter to select it.', 'legacy-redirector' ),
				'noSuggestionsMessage'  => __( 'No matching posts or pages.', 'legacy-redirector' ),
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
			__( 'Add Redirect', 'legacy-redirector' ),
			__( 'Add Redirect', 'legacy-redirector' ),
			Capability::MANAGE_REDIRECTS_CAPABILITY,
			'add-redirect',
			array( $this, 'render_add_page' )
		);

		// Edit Redirect page (hidden from menu).
		add_submenu_page(
			'',
			__( 'Edit Redirect', 'legacy-redirector' ),
			__( 'Edit Redirect', 'legacy-redirector' ),
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
			wp_die( esc_html__( 'Invalid redirect ID.', 'legacy-redirector' ) );
		}

		$redirect = $this->repository->find_by_id( $redirect_id );
		if ( null === $redirect ) {
			wp_die( esc_html__( 'Redirect not found.', 'legacy-redirector' ) );
		}

		if ( 'trash' === $redirect->status() ) {
			self::refuse_trashed();
		}

		$this->render_form_page( $redirect );
	}

	/**
	 * Render the redirect form page.
	 *
	 * @param Redirect|null $redirect The redirect for edit, null for add.
	 * @return void
	 */
	private function render_form_page( ?Redirect $redirect ): void {
		$is_edit = null !== $redirect;
		$title   = $is_edit ? __( 'Edit Redirect', 'legacy-redirector' ) : __( 'Add Redirect', 'legacy-redirector' );

		// Get current values.
		$redirect_from       = '';
		$redirect_status     = 'publish';
		$destination_value   = '';
		$destination_display = '';

		if ( $is_edit ) {
			// Editing existing redirect - render from the entity.
			$redirect_from   = $redirect->source()->path();
			$redirect_status = $redirect->status();
			$destination     = $redirect->destination();

			if ( $destination->is_post_id() ) {
				$destination_value = $destination->as_post_id()->value();
				$parent_post       = get_post( $destination_value );
				if ( $parent_post ) {
					$destination_display = get_the_title( $parent_post ) . ' (ID: ' . $destination_value . ')';
				} else {
					$destination_display = (string) $destination_value;
				}
			} else {
				$destination_value   = $destination->as_url()->value();
				$destination_display = $destination_value;
			}
		}

		// Values preserved from a rejected save override the stored ones, so the
		// form shows what the user submitted alongside the error it caused. The
		// nonce limits that to this user's own save: a crafted link could
		// otherwise show a different destination over a stored redirect, and a
		// save meant only to change its status would store it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the nonce.
		$form_nonce = isset( $_GET['form_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['form_nonce'] ) ) : '';
		if ( wp_verify_nonce( $form_nonce, 'redirect_form_values' ) ) {
			// The URLs are left unsanitized because sanitize_text_field() deletes
			// every %xx sequence; the view escapes them on output.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Escaped on output.
			if ( isset( $_GET['redirect_from'] ) ) {
				$redirect_from = trim( wp_unslash( $_GET['redirect_from'] ) );
			}
			if ( isset( $_GET['redirect_to'] ) ) {
				$destination_value   = trim( wp_unslash( $_GET['redirect_to'] ) );
				$destination_display = $destination_value;
			}
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( isset( $_GET['redirect_status'] ) && in_array( $_GET['redirect_status'], array( 'publish', 'draft' ), true ) ) {
				$redirect_status = sanitize_text_field( wp_unslash( $_GET['redirect_status'] ) );
			}
		}

		// Check for success/error messages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading message params for display.
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just reading error params for display.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$post_id       = $is_edit ? (int) $redirect->id() : 0;
		$error_message = '' !== $error ? $this->get_error_message( $error ) : '';

		// Accepted, but flagged every time the redirect is opened: a reserved
		// path can lock the admin out (see SourceUrl::is_reserved()), and
		// content at the source leaves the redirect dormant.
		$source_warnings = $is_edit ? $this->auditor->source_warnings( $redirect->source() ) : array();
		$source_warning  = '';
		if ( in_array( AuditFindingType::RESERVED_SOURCE, $source_warnings, true ) ) {
			$source_warning = self::reserved_source_message();
		} elseif ( in_array( AuditFindingType::SOURCE_SERVES_CONTENT, $source_warnings, true ) ) {
			$source_warning = self::occupied_source_message();
		}

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
			wp_die( esc_html__( 'Security check failed.', 'legacy-redirector' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage redirects.', 'legacy-redirector' ) );
		}

		// Get form values. The URLs reach SourceUrl and Destination as typed,
		// as they do from the CLI: those sanitize and canonicalize them, while
		// sanitize_text_field() would delete every %xx sequence first.
		$redirect_id     = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
		$redirect_status = isset( $_POST['redirect_status'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_status'] ) ) : 'publish';
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by SourceUrl and Destination.
		$redirect_from = isset( $_POST['redirect_from'] ) ? trim( wp_unslash( $_POST['redirect_from'] ) ) : '';
		$redirect_to   = isset( $_POST['redirect_to'] ) ? trim( wp_unslash( $_POST['redirect_to'] ) ) : '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$is_edit = $redirect_id > 0;

		// Validate required fields.
		if ( '' === $redirect_from || '' === $redirect_to ) {
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
			$source = SourceUrl::from_string( $redirect_from, HomePath::current() );
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

			// The form has no trash status to submit, so saving would publish it.
			if ( 'trash' === $existing->status() ) {
				self::refuse_trashed();
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
			$http_finding = $this->auditor->audit_destination( $proposed, true );
			if ( null !== $http_finding && AuditFindingType::URL_NOT_FOUND === $http_finding->type() ) {
				$this->redirect_with_error( $redirect_id, 'path_not_found', $redirect_from, $redirect_to, $redirect_status );
			}
		}

		if ( $is_edit ) {
			// Update existing redirect.
			$result = $this->manager->update_redirect( $redirect_id, $redirect_from, $destination, $redirect_status );

			if ( $result->is_error() ) {
				$this->redirect_with_error( $redirect_id, self::form_error_for( $result->error_code() ), $redirect_from, $redirect_to, $redirect_status );
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
	 * Refuse to edit a redirect in the Trash until it is restored, as core
	 * refuses to edit a trashed post.
	 *
	 * @return never
	 */
	private static function refuse_trashed(): void {
		wp_die(
			sprintf(
				'%1$s <a href="%2$s">%3$s</a>',
				esc_html__( 'This redirect is in the Trash. Restore it before editing.', 'legacy-redirector' ),
				esc_url( admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&post_status=trash' ) ),
				esc_html__( 'Go to the Trash', 'legacy-redirector' )
			)
		);
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
		return (bool) apply_filters( 'legacy_redirector_check_destination_reachability', true );
	}

	/**
	 * The warning for a source WordPress itself serves.
	 *
	 * Shown as soon as the source field loses focus, before anything is saved,
	 * and again whenever such a redirect is opened for editing.
	 *
	 * @return string The translated warning.
	 */
	private static function reserved_source_message(): string {
		return __( 'This is a path WordPress itself serves. The redirect stays dormant while that path works, but takes over if the path ever returns a 404. For wp-admin or wp-login.php, that locks you out of the dashboard. Keep it only if this is a genuine legacy URL.', 'legacy-redirector' );
	}

	/**
	 * The warning for a source that published content already answers.
	 *
	 * Shown when the source field loses focus, and every time such a
	 * redirect is opened.
	 *
	 * @return string The translated warning.
	 */
	private static function occupied_source_message(): string {
		return __( 'Published content already lives at this path. Redirects only answer 404s, so this one stays dormant while that content is there. Unpublish the content if the redirect should fire; otherwise it is a fallback for when the content goes.', 'legacy-redirector' );
	}

	/**
	 * The error for a destination host missing from allowed_redirect_hosts.
	 *
	 * Shown as soon as the destination field loses focus, and again if the
	 * save is attempted anyway.
	 *
	 * @return string The translated error.
	 */
	private static function host_not_allowed_message(): string {
		return __( 'The destination domain is not allowed, so the redirect would never run. Add the domain to the "allowed_redirect_hosts" filter first.', 'legacy-redirector' );
	}

	/**
	 * Translate an application error code into a form error code.
	 *
	 * @param string|null $error_code The validator or manager error code.
	 * @return string The form error code.
	 */
	private static function form_error_for( ?string $error_code ): string {
		$map = array(
			'duplicate-redirect-uri'   => 'duplicate',
			'invalid-values'           => 'same_source_destination',
			'empty-postid'             => 'post_not_found',
			'non-public'               => 'post_not_public',
			'insert-not-allowed'       => 'save_failed',
			'save-failed'              => 'save_failed',
			'not-found'                => 'save_failed',
			'invalid-source'           => 'invalid_source',
			'external-url-not-allowed' => 'host_not_allowed',
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
		// add_query_arg() does not encode the values it adds, so the URLs are
		// encoded here or a & # or + in them would corrupt the query string.
		$args = array(
			'post_type'       => PostType::POST_TYPE,
			'page'            => $redirect_id > 0 ? 'edit-redirect' : 'add-redirect',
			'error'           => $error,
			'redirect_from'   => rawurlencode( $from ),
			'redirect_to'     => rawurlencode( $to ),
			'redirect_status' => $status,
			'form_nonce'      => wp_create_nonce( 'redirect_form_values' ),
		);

		if ( $redirect_id > 0 ) {
			$args['redirect_id'] = $redirect_id;
		}

		$url = add_query_arg( $args, admin_url( 'edit.php' ) );

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
			'empty_fields'            => __( 'Redirect From and Redirect To are required fields.', 'legacy-redirector' ),
			'invalid_destination'     => __( 'The destination is not valid.', 'legacy-redirector' ),
			'invalid_source'          => __( 'The source URL is not valid.', 'legacy-redirector' ),
			'duplicate'               => __( 'A redirect already exists for this source URL.', 'legacy-redirector' ),
			'same_source_destination' => __( 'Redirect From and Redirect To must not be the same. This would create a redirect loop.', 'legacy-redirector' ),
			'save_failed'             => __( 'Failed to save the redirect. Please try again.', 'legacy-redirector' ),
			'post_not_found'          => __( 'The destination post ID does not exist.', 'legacy-redirector' ),
			'post_not_public'         => __( 'The destination post is not published.', 'legacy-redirector' ),
			'path_not_found'          => __( 'The destination path does not exist.', 'legacy-redirector' ),
			'host_not_allowed'        => self::host_not_allowed_message(),
		);

		return $messages[ $error ] ?? __( 'An error occurred.', 'legacy-redirector' );
	}
}
