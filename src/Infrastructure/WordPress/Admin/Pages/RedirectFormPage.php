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
use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Domain\Destination;
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
		add_action( 'current_screen', array( $this, 'set_edit_page_title' ) );
		add_action( 'current_screen', array( $this, 'add_contextual_help' ) );
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
	 * Set the admin title for the hidden edit-redirect page.
	 *
	 * @return void
	 */
	public function set_edit_page_title(): void {
		global $title;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking page for title display.
		if ( isset( $_GET['page'] ) && 'edit-redirect' === $_GET['page'] && empty( $title ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting page title for admin screen header.
			$title = __( 'Edit Redirect', 'wpcom-legacy-redirector' );
		}
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

		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>

			<?php if ( 'created' === $message && $is_edit ) : ?>
				<div id="message" class="updated notice is-dismissible">
					<p>
						<?php esc_html_e( 'Redirect created successfully.', 'wpcom-legacy-redirector' ); ?>
						<a href="<?php echo esc_url( home_url( $redirect_from ) ); ?>" target="_blank"><?php esc_html_e( 'Test it', 'wpcom-legacy-redirector' ); ?></a>
					</p>
				</div>
			<?php elseif ( 'updated' === $message && $is_edit ) : ?>
				<div id="message" class="updated notice is-dismissible">
					<p>
						<?php esc_html_e( 'Redirect updated successfully.', 'wpcom-legacy-redirector' ); ?>
						<a href="<?php echo esc_url( home_url( $redirect_from ) ); ?>" target="_blank"><?php esc_html_e( 'Test it', 'wpcom-legacy-redirector' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div id="message" class="error notice is-dismissible">
					<p><?php echo esc_html( $this->get_error_message( $error ) ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="save_redirect" />
				<?php wp_nonce_field( 'save_redirect', 'redirect_nonce' ); ?>

				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required">
							<th scope="row">
								<label for="redirect_from"><?php esc_html_e( 'Redirect From', 'wpcom-legacy-redirector' ); ?> <span class="required">*</span></label>
							</th>
							<td>
								<div style="display: inline-flex; align-items: center;">
									<span class="code" style="padding: 0 8px; background: #f0f0f1; border: 1px solid #8c8f94; border-right: 0; border-radius: 4px 0 0 4px; line-height: 28px; color: #50575e;">/</span>
									<input type="text" name="redirect_from" id="redirect_from" value="<?php echo esc_attr( ltrim( $redirect_from, '/' ) ); ?>" class="regular-text code" style="border-radius: 0 4px 4px 0;" required placeholder="old-page" />
								</div>
								<p class="description"><?php esc_html_e( 'The source path that should redirect (e.g., old-page).', 'wpcom-legacy-redirector' ); ?></p>
								<p id="redirect_from_error" class="notice notice-error inline" style="display: none; padding: 8px 12px;"></p>
							</td>
						</tr>
						<tr class="form-field form-required">
							<th scope="row">
								<label for="redirect_to_display"><?php esc_html_e( 'Redirect To', 'wpcom-legacy-redirector' ); ?> <span class="required">*</span></label>
							</th>
							<td style="position: relative;">
								<input type="text" id="redirect_to_display" value="<?php echo esc_attr( $destination_display ); ?>" class="regular-text" autocomplete="off" required />
								<input type="hidden" name="redirect_to" id="redirect_to" value="<?php echo esc_attr( $destination_value ); ?>" />
								<p class="description"><?php esc_html_e( 'Enter a relative path (e.g., /new-page), post ID, or full URL. Start typing to search for posts.', 'wpcom-legacy-redirector' ); ?></p>
								<div id="redirect_to_suggestions" style="display: none; position: absolute; background: #fff; border: 1px solid #ddd; max-height: 200px; overflow-y: auto; z-index: 100; width: 25em; box-shadow: 0 2px 5px rgba(0,0,0,0.1);"></div>
							</td>
						</tr>
						<tr class="form-field">
							<th scope="row"><?php esc_html_e( 'Status', 'wpcom-legacy-redirector' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="redirect_status" value="publish" <?php checked( $redirect_status, 'publish' ); ?> />
										<?php esc_html_e( 'Enabled', 'wpcom-legacy-redirector' ); ?>
										<span class="description"><?php esc_html_e( '(Redirect is active)', 'wpcom-legacy-redirector' ); ?></span>
									</label>
									<br />
									<label>
										<input type="radio" name="redirect_status" value="draft" <?php checked( $redirect_status, 'draft' ); ?> />
										<?php esc_html_e( 'Disabled', 'wpcom-legacy-redirector' ); ?>
										<span class="description"><?php esc_html_e( '(Redirect is paused)', 'wpcom-legacy-redirector' ); ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<?php
					submit_button(
						$is_edit ? __( 'Update Redirect', 'wpcom-legacy-redirector' ) : __( 'Add Redirect', 'wpcom-legacy-redirector' ),
						'primary',
						'submit',
						false
					);

					if ( $is_edit ) {
						$list_url = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );
						echo ' <a href="' . esc_url( $list_url ) . '" class="button">' . esc_html__( 'Back to Redirects', 'wpcom-legacy-redirector' ) . '</a>';
					}
					?>
				</p>
			</form>
		</div>

		<?php $this->render_autocomplete_script( $is_edit ? $post->ID : null ); ?>
		<?php
	}

	/**
	 * Render the autocomplete JavaScript.
	 *
	 * @param int|null $exclude_id Post ID to exclude from duplicate check.
	 * @return void
	 */
	private function render_autocomplete_script( ?int $exclude_id ): void {
		?>
		<script>
		jQuery(document).ready(function($) {
			var originalFrom = $('#redirect_from').val();
			var postId = <?php echo (int) ( $exclude_id ?? 0 ); ?>;
			var searchTimeout;
			var selectedIndex = -1;

			// Check for duplicate source URL on blur.
			$('#redirect_from').on('blur', function() {
				var newFrom = $(this).val().trim();
				if (newFrom === originalFrom || newFrom === '') {
					$('#redirect_from_error').hide();
					return;
				}

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: '<?php echo esc_js( CheckDuplicateHandler::get_action() ); ?>',
						redirect_from: newFrom,
						exclude_id: postId,
						nonce: '<?php echo esc_js( wp_create_nonce( CheckDuplicateHandler::get_action() ) ); ?>'
					},
					success: function(response) {
						if (response.success && response.data.exists) {
							$('#redirect_from_error')
								.text('<?php echo esc_js( __( 'A redirect already exists for this source URL.', 'wpcom-legacy-redirector' ) ); ?>')
								.show();
						} else {
							$('#redirect_from_error').hide();
						}
					}
				});
			});

			// Update visual highlight for selected suggestion.
			function updateHighlight() {
				var $suggestions = $('#redirect_to_suggestions .redirect-suggestion');
				$suggestions.css('background-color', '#fff');
				if (selectedIndex >= 0 && selectedIndex < $suggestions.length) {
					$suggestions.eq(selectedIndex).css('background-color', '#f0f0f1');
				}
			}

			// Select the currently highlighted suggestion.
			function selectCurrentSuggestion() {
				var $suggestions = $('#redirect_to_suggestions .redirect-suggestion');
				if (selectedIndex >= 0 && selectedIndex < $suggestions.length) {
					var $selected = $suggestions.eq(selectedIndex);
					var id = $selected.data('id');
					var title = $selected.data('title');
					$('#redirect_to_display').val(title + ' (ID: ' + id + ')');
					$('#redirect_to').val(id);
					$('#redirect_to_suggestions').hide();
					selectedIndex = -1;
				}
			}

			// Handle keyboard navigation.
			$('#redirect_to_display').on('keydown', function(e) {
				var $suggestions = $('#redirect_to_suggestions .redirect-suggestion');
				if (!$suggestions.length || !$('#redirect_to_suggestions').is(':visible')) {
					return;
				}

				switch (e.keyCode) {
					case 40: // Down arrow
						e.preventDefault();
						selectedIndex = Math.min(selectedIndex + 1, $suggestions.length - 1);
						updateHighlight();
						break;
					case 38: // Up arrow
						e.preventDefault();
						selectedIndex = Math.max(selectedIndex - 1, -1);
						updateHighlight();
						break;
					case 13: // Enter
						if (selectedIndex >= 0) {
							e.preventDefault();
							selectCurrentSuggestion();
						}
						break;
					case 27: // Escape
						$('#redirect_to_suggestions').hide();
						selectedIndex = -1;
						break;
				}
			});

			// Sync display field to hidden field when user types directly.
			$('#redirect_to_display').on('input', function() {
				var val = $(this).val().trim();
				$('#redirect_to').val(val);
				selectedIndex = -1; // Reset selection on new input.

				// Only search if it looks like text (not a URL or path or number).
				clearTimeout(searchTimeout);
				if (val.length < 2 || /^[\/0-9]/.test(val) || /^https?:/.test(val)) {
					$('#redirect_to_suggestions').hide();
					return;
				}

				searchTimeout = setTimeout(function() {
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: '<?php echo esc_js( SearchPostsHandler::get_action() ); ?>',
							search: val,
							nonce: '<?php echo esc_js( wp_create_nonce( SearchPostsHandler::get_action() ) ); ?>'
						},
						success: function(response) {
							if (response.success && response.data.posts.length > 0) {
								// Build via DOM APIs, not string concatenation: titles and
								// type labels are attacker-influenced and must be escaped
								// in both attribute and text positions.
								var $container = $('#redirect_to_suggestions').empty();
								$.each(response.data.posts, function(i, post) {
									$('<div>', {
										'class': 'redirect-suggestion',
										'data-id': post.id,
										'data-title': post.title,
										'style': 'padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;'
									})
									.append(
										$('<strong>').text(post.title),
										'<br>',
										$('<small>').css('color', '#666').text(post.type + ' (ID: ' + post.id + ')')
									)
									.appendTo($container);
								});
								$container.show();
								selectedIndex = -1; // Reset selection when new results appear.
							} else {
								$('#redirect_to_suggestions').hide();
							}
						}
					});
				}, 300);
			});

			// Handle suggestion click.
			$(document).on('click', '.redirect-suggestion', function() {
				var id = $(this).data('id');
				var title = $(this).data('title');
				$('#redirect_to_display').val(title + ' (ID: ' + id + ')');
				$('#redirect_to').val(id);
				$('#redirect_to_suggestions').hide();
				selectedIndex = -1;
			});

			// Highlight on hover (also updates selectedIndex for consistency).
			$(document).on('mouseenter', '.redirect-suggestion', function() {
				var $suggestions = $('#redirect_to_suggestions .redirect-suggestion');
				selectedIndex = $suggestions.index(this);
				updateHighlight();
			}).on('mouseleave', '.redirect-suggestion', function() {
				// Keep highlight if using keyboard, otherwise clear.
				$(this).css('background-color', '#fff');
			});

			// Hide suggestions on click outside.
			$(document).on('click', function(e) {
				if (!$(e.target).closest('#redirect_to_display, #redirect_to_suggestions').length) {
					$('#redirect_to_suggestions').hide();
					selectedIndex = -1;
				}
			});
		});
		</script>
		<?php
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

		// Validate destination exists and is accessible.
		$destination_validation = $this->validator->validate_destination( $destination );
		if ( $destination_validation->is_invalid() ) {
			$error_code = $destination_validation->error_code();
			// Map validator error codes to form error codes.
			$error_map  = array(
				'empty-postid' => 'post_not_found',
				'non-public'   => 'post_not_public',
				'invalid'      => 'path_not_found',
			);
			$form_error = $error_map[ $error_code ] ?? 'invalid_destination';
			$this->redirect_with_error( $redirect_id, $form_error, $redirect_from, $redirect_to, $redirect_status );
		}

		// Create source URL object.
		try {
			$source = SourceUrl::from_string( $redirect_from );
		} catch ( \InvalidArgumentException $e ) {
			$this->redirect_with_error( $redirect_id, 'invalid_source', $redirect_from, $redirect_to, $redirect_status );
		}

		// Check for duplicates.
		$existing = $this->repository->get_id_by_source( $source );
		if ( $existing > 0 && $existing !== $redirect_id ) {
			$this->redirect_with_error( $redirect_id, 'duplicate', $redirect_from, $redirect_to, $redirect_status );
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
			$result = $this->manager->create_redirect( $source, $destination, false );

			if ( $result->is_error() ) {
				$this->redirect_with_error( 0, 'save_failed', $redirect_from, $redirect_to, $redirect_status );
			}

			$redirect_id = $result->redirect_id();

			// Update status if not publish.
			if ( 'publish' !== $redirect_status ) {
				$this->manager->disable( $redirect_id );
			}

			wp_safe_redirect(
				admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect_id . '&message=created' )
			);
			exit;
		}
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
			'empty_fields'        => __( 'Redirect From and Redirect To are required fields.', 'wpcom-legacy-redirector' ),
			'invalid_destination' => __( 'The destination is not valid.', 'wpcom-legacy-redirector' ),
			'invalid_source'      => __( 'The source URL is not valid.', 'wpcom-legacy-redirector' ),
			'duplicate'           => __( 'A redirect already exists for this source URL.', 'wpcom-legacy-redirector' ),
			'save_failed'         => __( 'Failed to save the redirect. Please try again.', 'wpcom-legacy-redirector' ),
			'post_not_found'      => __( 'The destination post ID does not exist.', 'wpcom-legacy-redirector' ),
			'post_not_public'     => __( 'The destination post is not published.', 'wpcom-legacy-redirector' ),
			'path_not_found'      => __( 'The destination path does not exist.', 'wpcom-legacy-redirector' ),
		);

		return $messages[ $error ] ?? __( 'An error occurred.', 'wpcom-legacy-redirector' );
	}

	/**
	 * Add contextual help to the Add/Edit Redirect pages.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 * @return void
	 */
	public function add_contextual_help( \WP_Screen $screen ): void {
		$form_pages = array(
			PostType::POST_TYPE . '_page_add-redirect',
			PostType::POST_TYPE . '_page_edit-redirect',
		);

		if ( ! in_array( $screen->id, $form_pages, true ) ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'wpcom-legacy-redirector' ),
				'content' => $this->get_form_overview_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'source',
				'title'   => __( 'Redirect From', 'wpcom-legacy-redirector' ),
				'content' => $this->get_source_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'destination',
				'title'   => __( 'Redirect To', 'wpcom-legacy-redirector' ),
				'content' => $this->get_destination_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'tips',
				'title'   => __( 'Tips', 'wpcom-legacy-redirector' ),
				'content' => $this->get_tips_help(),
			)
		);

		$screen->set_help_sidebar( $this->get_help_sidebar() );
	}

	/**
	 * Get the form overview help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_form_overview_help(): string {
		return '<p>' . __( 'Use this form to create or edit a redirect. A redirect automatically sends visitors from one URL to another.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'Redirects are only triggered when the source URL would otherwise return a 404 (Not Found) error. If content already exists at the source URL, the redirect will not activate.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the source field help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_source_help(): string {
		return '<p>' . __( 'The <strong>Redirect From</strong> field specifies the old URL path that should trigger the redirect.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'The leading forward slash is shown as a prefix, so just enter the path. For example:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>old-page</code></li>' .
			'<li><code>blog/2020/old-post</code></li>' .
			'<li><code>products/discontinued-item</code></li>' .
			'</ul>' .
			'<p>' . __( 'The path is relative to your site\'s root. Do not include the domain name.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p><strong>' . __( 'Note:', 'wpcom-legacy-redirector' ) . '</strong> ' . __( 'Each source path can only have one redirect. Duplicate sources are not allowed.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the destination field help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_destination_help(): string {
		return '<p>' . __( 'The <strong>Redirect To</strong> field specifies where visitors should be sent. You can use three formats:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<h4>' . __( '1. Relative Path', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'A path on the same site, starting with a forward slash:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>/new-page</code></li>' .
			'<li><code>/blog/2024/updated-post</code></li>' .
			'</ul>' .
			'<h4>' . __( '2. Absolute URL', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'A full URL including the domain, useful for external redirects:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>https://example.com/page</code></li>' .
			'<li><code>https://newsite.com/destination</code></li>' .
			'</ul>' .
			'<p><strong>' . __( 'Note:', 'wpcom-legacy-redirector' ) . '</strong> ' . __( 'External domains must be allowed via the <code>allowed_redirect_hosts</code> filter.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<h4>' . __( '3. Post ID', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'Select an existing post or page using the search field. The redirect will point to that content\'s permalink, which automatically updates if the slug changes.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the tips help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_tips_help(): string {
		return '<ul>' .
			'<li>' . __( '<strong>Test your redirects</strong> &mdash; After saving, use the "Test it" link or visit the source URL to verify it works.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Use Post IDs for internal content</strong> &mdash; If redirecting to a post or page on your site, selecting it by Post ID ensures the redirect stays valid even if the slug changes.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Avoid redirect chains</strong> &mdash; Don\'t create redirects where the destination is another redirect source. This slows down the user experience.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Check for existing content</strong> &mdash; Redirects only work when the source URL returns a 404. If a page exists at that URL, delete or rename it first.', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the help sidebar content.
	 *
	 * @return string Sidebar HTML.
	 */
	private function get_help_sidebar(): string {
		return '<p><strong>' . __( 'For more information:', 'wpcom-legacy-redirector' ) . '</strong></p>' .
			'<p><a href="https://github.com/Automattic/WPCOM-Legacy-Redirector" target="_blank">' . __( 'Plugin Documentation', 'wpcom-legacy-redirector' ) . '</a></p>' .
			'<p><a href="https://github.com/Automattic/WPCOM-Legacy-Redirector/issues" target="_blank">' . __( 'Report an Issue', 'wpcom-legacy-redirector' ) . '</a></p>';
	}
}
