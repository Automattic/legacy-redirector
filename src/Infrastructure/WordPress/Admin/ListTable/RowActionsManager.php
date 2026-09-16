<?php
/**
 * Manages row actions for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles row actions for the redirects list table.
 */
final class RowActionsManager {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'modify_row_actions' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'render_validate_script' ) );
	}

	/**
	 * Modify row actions for redirect posts.
	 *
	 * @param array<string, string> $actions Default actions.
	 * @param \WP_Post              $post    The current post.
	 * @return array<string, string> Modified actions.
	 */
	public function modify_row_actions( array $actions, \WP_Post $post ): array {
		if ( PostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for admin list display.
		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) {
			return $actions;
		}

		$trash   = $actions['trash'] ?? '';
		$actions = array();

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return $actions;
		}

		// Edit link - use our custom Edit Redirect page.
		$edit_link       = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $post->ID );
		$actions['edit'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $edit_link ),
			esc_html__( 'Edit', 'wpcom-legacy-redirector' )
		);

		// Enable/Disable link based on current status.
		if ( 'publish' === $post->post_status ) {
			$disable_link       = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'disable_redirect',
						'redirect_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'disable_redirect_' . $post->ID
			);
			$actions['disable'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $disable_link ),
				esc_html__( 'Disable', 'wpcom-legacy-redirector' )
			);
		} else {
			$enable_link       = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'enable_redirect',
						'redirect_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'enable_redirect_' . $post->ID
			);
			$actions['enable'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $enable_link ),
				esc_html__( 'Enable', 'wpcom-legacy-redirector' )
			);
		}

		// Validate link (uses AJAX with PHP fallback).
		$validate_link       = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'validate',
					'post'   => $post->ID,
				),
				admin_url( 'edit.php?post_type=' . PostType::POST_TYPE )
			),
			'validate_vip_legacy_redirect',
			'_validate_redirect'
		);
		$actions['validate'] = sprintf(
			'<a href="%1$s" class="validate-redirect" data-redirect-id="%2$d" data-source="%3$s">%4$s</a>',
			esc_url( $validate_link ),
			$post->ID,
			esc_attr( $post->post_title ),
			esc_html__( 'Validate', 'wpcom-legacy-redirector' )
		);

		// Follow link - use home_url() so the stored home-relative path is
		// resolved against the site's base URL, which is not the domain root
		// on a subsite or on a single site installed at example.com/blog.
		$actions['follow'] = sprintf(
			'<a href="%1$s" target="_blank">%2$s</a>',
			esc_url( home_url( $post->post_title ) ),
			esc_html__( 'Follow', 'wpcom-legacy-redirector' )
		);

		// Re-insert trash link.
		if ( $trash ) {
			$actions['trash'] = $trash;
		}

		return $actions;
	}

	/**
	 * Render the JavaScript for AJAX validation on the list page.
	 *
	 * @return void
	 */
	public function render_validate_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}
		?>
		<style>
			.validate-redirect.validating {
				opacity: 0.5;
				pointer-events: none;
			}
			.validation-result {
				padding-left: 6px;
				border-left: 3px solid;
				font-size: 12px;
			}
			.validation-result.valid {
				border-left-color: #00a32a;
				color: #1d2327;
			}
			.validation-result.valid .dashicons {
				color: #00a32a;
			}
			.validation-result.invalid {
				border-left-color: #d63638;
				color: #1d2327;
			}
			.validation-result.invalid .dashicons {
				color: #d63638;
			}
			.validation-result .dashicons {
				font-size: 14px;
				width: 14px;
				height: 14px;
				vertical-align: middle;
				margin-right: 3px;
			}
		</style>
		<script>
		jQuery(document).ready(function($) {
			var validateNonce = '<?php echo esc_js( wp_create_nonce( ValidateRedirectHandler::get_action() ) ); ?>';

			$(document).on('click', '.validate-redirect', function(e) {
				e.preventDefault();

				var $link = $(this);
				var $row = $link.closest('tr');
				var $toColumn = $row.find('td.to');
				var redirectId = $link.data('redirect-id');

				// Remove any existing result in this row.
				$toColumn.find('.validation-result').remove();

				// Add loading state.
				$link.addClass('validating').text('<?php echo esc_js( __( 'Validating...', 'wpcom-legacy-redirector' ) ); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: '<?php echo esc_js( ValidateRedirectHandler::get_action() ); ?>',
						redirect_id: redirectId,
						nonce: validateNonce
					},
					success: function(response) {
						$link.removeClass('validating').text('<?php echo esc_js( __( 'Validate', 'wpcom-legacy-redirector' ) ); ?>');

						var resultClass = response.success ? 'valid' : 'invalid';
						var icon = response.success ? 'dashicons-yes-alt' : 'dashicons-warning';
						var message = response.data.message;

						var $result = $('<div class="validation-result ' + resultClass + '">' +
							'<span class="dashicons ' + icon + '"></span>' +
							message +
							'</div>');

						$toColumn.append($result);

						// Auto-hide success messages after 5 seconds.
						if (response.success) {
							setTimeout(function() {
								$result.fadeOut(400, function() {
									$(this).remove();
								});
							}, 5000);
						}
					},
					error: function() {
						$link.removeClass('validating').text('<?php echo esc_js( __( 'Validate', 'wpcom-legacy-redirector' ) ); ?>');

						var $result = $('<div class="validation-result invalid">' +
							'<span class="dashicons dashicons-warning"></span>' +
							'<?php echo esc_js( __( 'Validation request failed.', 'wpcom-legacy-redirector' ) ); ?>' +
							'</div>');

						$toColumn.append($result);
					}
				});
			});
		});
		</script>
		<?php
	}
}
