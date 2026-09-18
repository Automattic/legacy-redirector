<?php
/**
 * Validate Redirects report template.
 *
 * Included from ValidatePage::render_page(), which defines:
 *
 * @var string                                              $status     Status filter ('any', 'enabled', 'disabled').
 * @var int                                                 $limit      Maximum number of redirects checked.
 * @var bool                                                $check_urls Whether URL destinations were requested over HTTP.
 * @var int                                                 $checked    How many redirects were checked.
 * @var int                                                 $problems   How many findings are problems.
 * @var int                                                 $warnings   How many findings are warnings.
 * @var \Automattic\LegacyRedirector\Domain\AuditFinding[]  $findings   The findings to render.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\ValidatePage;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Validate Redirects', 'legacy-redirector' ); ?></h1>

	<p><?php esc_html_e( 'Checks redirects for broken destinations and sources that deserve a look: the same report as the "wp legacy-redirector validate" CLI command. A problem means the redirect is broken; a warning means it works but a person should judge it.', 'legacy-redirector' ); ?></p>

	<form method="get">
		<input type="hidden" name="post_type" value="<?php echo esc_attr( PostType::POST_TYPE ); ?>" />
		<input type="hidden" name="page" value="<?php echo esc_attr( ValidatePage::PAGE_SLUG ); ?>" />
		<?php wp_nonce_field( ValidatePage::PAGE_SLUG, '_validate_report', false ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="validate-status"><?php esc_html_e( 'Status', 'legacy-redirector' ); ?></label></th>
				<td>
					<select name="status" id="validate-status">
						<option value="enabled" <?php selected( $status, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'legacy-redirector' ); ?></option>
						<option value="disabled" <?php selected( $status, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'legacy-redirector' ); ?></option>
						<option value="any" <?php selected( $status, 'any' ); ?>><?php esc_html_e( 'Any', 'legacy-redirector' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="validate-limit"><?php esc_html_e( 'Limit', 'legacy-redirector' ); ?></label></th>
				<td><input type="number" name="limit" id="validate-limit" value="<?php echo esc_attr( (string) $limit ); ?>" min="1" max="1000" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'URL checks', 'legacy-redirector' ); ?></th>
				<td>
					<label for="validate-check-urls">
						<input type="checkbox" name="check_urls" id="validate-check-urls" value="1" <?php checked( $check_urls ); ?> />
						<?php esc_html_e( 'Also request URL destinations to see whether they respond. Slow: one HTTP request per redirect.', 'legacy-redirector' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Validate', 'legacy-redirector' ) ); ?>
	</form>

	<h2>
		<?php
		printf(
			/* translators: 1: number of redirects checked, 2: number of problems, 3: number of warnings */
			esc_html__( 'Checked %1$d redirect(s): %2$d problem(s), %3$d warning(s).', 'legacy-redirector' ),
			(int) $checked,
			(int) $problems,
			(int) $warnings
		);
		?>
	</h2>

	<?php if ( array() === $findings ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'No issues found.', 'legacy-redirector' ); ?></p></div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Severity', 'legacy-redirector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'From', 'legacy-redirector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'legacy-redirector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Finding', 'legacy-redirector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'legacy-redirector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'legacy-redirector' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $findings as $finding ) : ?>
					<?php
					$redirect    = $finding->redirect();
					$destination = $redirect->destination();
					$edit_link   = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . (int) $redirect->id() );
					?>
					<tr>
						<td>
							<?php if ( $finding->is_warning() ) : ?>
								<span class="dashicons dashicons-flag" style="color: #dba617;" aria-hidden="true"></span> <?php esc_html_e( 'Warning', 'legacy-redirector' ); ?>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color: #d63638;" aria-hidden="true"></span> <?php esc_html_e( 'Problem', 'legacy-redirector' ); ?>
							<?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $redirect->source()->path() ); ?></a></td>
						<td>
							<?php
							echo esc_html(
								$destination->is_post_id()
									/* translators: %d: destination post ID */
									? sprintf( __( 'Post %d', 'legacy-redirector' ), $destination->as_post_id()->value() )
									: $destination->as_url()->value()
							);
							?>
						</td>
						<td><?php echo esc_html( $finding->label() ); ?></td>
						<td><?php echo esc_html( $finding->description() . '.' ); ?></td>
						<td><?php $redirect->is_active() ? esc_html_e( 'Enabled', 'legacy-redirector' ) : esc_html_e( 'Disabled', 'legacy-redirector' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
