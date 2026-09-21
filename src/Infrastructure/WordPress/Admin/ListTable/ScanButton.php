<?php
/**
 * The "Scan for issues" button on the redirects list screen.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Renders a toolbar button that scans every redirect in paged batches
 * against the scan REST route, with a native progress element, then reloads
 * so the "Has issues" view and its counts reflect the finished run.
 *
 * "Scan" (stored data only, whole table, no requests) is deliberately a
 * different word from the Test row action (requests the source live): a
 * scan is the partial, safe check, and the button must not read as the
 * complete one.
 *
 * Lives in the list table's top toolbar, to the right of the bulk actions
 * and date filter controls, where the other whole-table operations are.
 */
final class ScanButton {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'manage_posts_extra_tablenav', array( $this, 'render' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'render_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Whether the current screen is the redirects list for a managing user.
	 *
	 * @return bool
	 */
	private function should_render(): bool {
		$screen = get_current_screen();

		return $screen
			&& 'edit-' . PostType::POST_TYPE === $screen->id
			&& current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Enqueue apiFetch, which carries the REST nonce, on the list screen.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . PostType::POST_TYPE === $screen->id ) {
			wp_enqueue_script( 'wp-api-fetch' );
		}
	}

	/**
	 * Render the button and progress bar in the top toolbar.
	 *
	 * Fires after the bulk actions and date filter controls, so the button
	 * sits to their right in the same row.
	 *
	 * @param string $which Which toolbar is rendering: 'top' or 'bottom'.
	 * @return void
	 */
	public function render( string $which ): void {
		if ( 'top' !== $which || ! $this->should_render() ) {
			return;
		}
		?>
		<div class="alignleft actions" id="legacy-redirector-scan">
			<button type="button" class="button" title="<?php esc_attr_e( 'Checks every redirect’s stored details for problems, without requesting any URLs. Use the Test action on a row to check live behavior.', 'legacy-redirector' ); ?>"><?php esc_html_e( 'Scan for issues', 'legacy-redirector' ); ?></button>
			<progress max="1" value="0" hidden></progress>
			<span role="status"></span>
		</div>
		<?php
	}

	/**
	 * Render the batch loop script.
	 *
	 * @return void
	 */
	public function render_script(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$config = array(
			'path'     => '/legacy-redirector/v1/scan',
			/* translators: 1: redirects scanned so far, 2: total redirects */
			'progress' => __( 'Scanned %1$s of %2$s redirects…', 'legacy-redirector' ),
			'failed'   => __( 'The scan failed; the flags cover the redirects scanned so far. Reload and try again.', 'legacy-redirector' ),
		);
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var config = <?php echo wp_json_encode( $config ); ?>;
			var box = document.getElementById('legacy-redirector-scan');
			if (!box) {
				return;
			}

			var button = box.querySelector('button');
			var bar = box.querySelector('progress');
			var status = box.querySelector('span');

			function report(checked, total) {
				bar.max = Math.max(total, 1);
				bar.value = checked;
				status.textContent = config.progress.replace('%1$s', checked).replace('%2$s', total);
			}

			function runBatch(offset) {
				return wp.apiFetch({
					path: config.path,
					method: 'POST',
					data: { offset: offset }
				});
			}

			button.addEventListener('click', async function () {
				button.disabled = true;
				bar.hidden = false;
				var offset = 0;

				try {
					for (;;) {
						var data = await runBatch(offset);
						offset += data.checked;
						report(Math.min(offset, data.total), data.total);
						if (data.done) {
							window.location.reload();
							return;
						}
					}
				} catch (e) {
					status.textContent = config.failed;
					button.disabled = false;
				}
			});
		});
		</script>
		<?php
	}
}
