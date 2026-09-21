<?php
/**
 * The "Check all" button on the redirects list screen.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Renders a page-title button that audits every redirect in paged batches
 * against the check-all REST route, with a native progress element, then
 * reloads so the "Has issues" view and its counts reflect the finished run.
 */
final class CheckAllButton {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 1: after ListScreenSetup injects the Add New button at 0,
		// so this button lands to its right.
		add_action( 'admin_notices', array( $this, 'render' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
	 * Render the button, the progress bar, and the batch loop script.
	 *
	 * @return void
	 */
	public function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		$config = array(
			'path'       => '/legacy-redirector/v1/check-all',
			'buttonText' => __( 'Check all', 'legacy-redirector' ),
			/* translators: 1: redirects checked so far, 2: total redirects */
			'progress'   => __( 'Checked %1$s of %2$s redirects…', 'legacy-redirector' ),
			'failed'     => __( 'Checking failed; the flags cover the redirects checked so far. Reload and try again.', 'legacy-redirector' ),
		);
		?>
		<div id="legacy-redirector-check-all-progress" hidden>
			<progress max="1" value="0"></progress>
			<span role="status"></span>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var config = <?php echo wp_json_encode( $config ); ?>;
			var title = document.querySelector('.wp-heading-inline');
			var box = document.getElementById('legacy-redirector-check-all-progress');
			if (!title || !box) {
				return;
			}

			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'page-title-action';
			button.textContent = config.buttonText;
			// After the Add New button when ListScreenSetup has injected it.
			var actions = title.parentNode.querySelectorAll('.page-title-action');
			var anchor = actions.length ? actions[actions.length - 1] : title;
			anchor.parentNode.insertBefore(button, anchor.nextSibling);

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
				box.hidden = false;
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
