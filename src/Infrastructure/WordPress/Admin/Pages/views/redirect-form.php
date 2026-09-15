<?php
/**
 * Add/Edit Redirect form template.
 *
 * Included from RedirectFormPage::render_form_page(), which defines:
 *
 * @var bool     $is_edit             Whether editing an existing redirect.
 * @var string   $title               Page title.
 * @var int      $post_id             Redirect post ID (0 on the Add page).
 * @var string   $redirect_from       Source path value.
 * @var string   $home_prefix         This site's home URL, with a trailing slash, shown before the source field.
 * @var string   $redirect_status     'publish' or 'draft'.
 * @var string   $destination_value   Destination form value (path, URL, or post ID).
 * @var string   $destination_display Human-readable destination for the display field.
 * @var string   $message             Success message code ('created', 'updated', or '').
 * @var string   $error_message       Resolved error message, or '' if none.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

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

	<?php if ( '' !== $error_message ) : ?>
		<div id="message" class="error notice is-dismissible">
			<p><?php echo esc_html( $error_message ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="save_redirect" />
		<?php wp_nonce_field( 'save_redirect', 'redirect_nonce' ); ?>

		<?php if ( $is_edit ) : ?>
			<input type="hidden" name="redirect_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr class="form-field form-required">
					<th scope="row">
						<label for="redirect_from"><?php esc_html_e( 'Redirect From', 'wpcom-legacy-redirector' ); ?> <span class="required">*</span></label>
					</th>
					<td>
						<div style="display: inline-flex; align-items: center;">
							<span class="code" style="padding: 0 8px; background: #f0f0f1; border: 1px solid #8c8f94; border-right: 0; border-radius: 4px 0 0 4px; line-height: 28px; color: #50575e;"><?php echo esc_html( $home_prefix ); ?></span>
							<input type="text" name="redirect_from" id="redirect_from" value="<?php echo esc_attr( ltrim( $redirect_from, '/' ) ); ?>" class="regular-text code" style="border-radius: 0 4px 4px 0;" required placeholder="old-page" />
						</div>
						<p class="description">
							<?php
							printf(
								/* translators: %s: example of a full URL the entered path resolves to, e.g. https://example.com/blog/old-page. */
								esc_html__( 'The source path that should redirect, always read relative to this site. Entering old-page matches %s.', 'wpcom-legacy-redirector' ),
								'<code>' . esc_html( $home_prefix . 'old-page' ) . '</code>'
							);
							?>
						</p>
						<p id="redirect_from_error" class="notice notice-error inline" style="display: none; padding: 8px 12px;"></p>
					</td>
				</tr>
				<tr class="form-field form-required">
					<th scope="row">
						<label for="redirect_to_display"><?php esc_html_e( 'Redirect To', 'wpcom-legacy-redirector' ); ?> <span class="required">*</span></label>
					</th>
					<td style="position: relative;">
						<input type="text" id="redirect_to_display" value="<?php echo esc_attr( $destination_display ); ?>" class="regular-text" autocomplete="off" required />
						<input type="hidden" name="redirect_to" id="redirect_to" value="<?php echo esc_attr( (string) $destination_value ); ?>" />
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
