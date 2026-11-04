<?php
/**
 * The "Validate all redirects" button on the redirects list screen.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Renders a toolbar button that validates every redirect in batches against
 * the validate REST route, with a native progress element, then reloads so the "Has
 * issues" view and its counts reflect the finished pass. Beside it, a status
 * line says where the background check stands, so nobody has to press the
 * button to learn whether the site has been checked.
 *
 * "Validate" (stored details only, no requests) is deliberately a different
 * word from the Test row action (requests the destination and source live),
 * matching `wp legacy-redirector validate` and `test`: validation is the
 * partial, safe check, and the button must not read as the complete one.
 *
 * Lives in the list table's top toolbar, to the right of the bulk actions
 * and date filter controls, where the other whole-table operations are.
 */
final class ValidateAllButton {

	/**
	 * The audit sweep.
	 *
	 * @var AuditSweep
	 */
	private AuditSweep $sweep;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param AuditSweep $sweep The audit sweep.
	 * @param AuditFlags $flags The per-row audit flags.
	 */
	public function __construct( AuditSweep $sweep, AuditFlags $flags ) {
		$this->sweep = $sweep;
		$this->flags = $flags;
	}

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
		$status = $this->sweep->status();
		$counts = $this->flags->stored_counts();

		// A pass already under way, in the background or from another visit,
		// is continued rather than restarted, so pressing the button never
		// throws away progress.
		$label      = $status['in_progress']
			? __( 'Continue validating', 'legacy-redirector' )
			: __( 'Validate all redirects', 'legacy-redirector' );
		$background = $this->sweep->budget() > 0.0;

		if ( $status['in_progress'] ) {
			$title = $background
				? __( 'Validation is already under way in the background. Continue it here to finish sooner; you can leave this page at any time.', 'legacy-redirector' )
				/* translators: %s: a WP-CLI command, wp legacy-redirector validate; do not translate it. */
				: sprintf( __( 'Validation is under way but the background check is turned off, so continue it here, or with %s.', 'legacy-redirector' ), 'wp legacy-redirector validate' );
		} else {
			$title = $background
				? __( 'Checks every redirect’s stored details for problems, without requesting any URLs. You can leave this page at any time: validation carries on in the background. Use the Test action on a row to check live behavior.', 'legacy-redirector' )
				: __( 'Checks every redirect’s stored details for problems, without requesting any URLs. The background check is turned off, so stay on this page until it finishes. Use the Test action on a row to check live behavior.', 'legacy-redirector' );
		}

		$show_flagged = ! $status['in_progress'] && null !== $status['checked_at'] && $counts['problem'] + $counts['warning'] > 0;
		$flagged_url  = add_query_arg(
			array(
				'post_type'     => PostType::POST_TYPE,
				'audit_flagged' => 1,
			),
			admin_url( 'edit.php' )
		);
		?>
		<div class="alignleft actions" id="legacy-redirector-validate-all">
			<button type="button" class="button" data-restart="<?php echo $status['in_progress'] ? '0' : '1'; ?>" title="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $label ); ?></button>
			<progress max="1" value="0" hidden aria-label="<?php esc_attr_e( 'Validation progress', 'legacy-redirector' ); ?>"></progress>
			<span role="status"><?php echo esc_html( $this->status_text( $status, $counts ) ); ?></span>
			<?php if ( $show_flagged ) : ?>
				<a href="<?php echo esc_url( $flagged_url ); ?>"><?php esc_html_e( 'Show flagged redirects', 'legacy-redirector' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Describe where validation stands, and what the last full pass found.
	 *
	 * @param array{in_progress: bool, checked: int, total: int, checked_at: int|null, estimate: int|null} $status The sweep status.
	 * @param array{problem: int, warning: int}                                                            $counts The flagged-row counts.
	 * @return string The status line.
	 */
	private function status_text( array $status, array $counts ): string {
		if ( $status['in_progress'] ) {
			$text = self::progress_text( $status['checked'], $status['total'], $this->sweep->budget() > 0.0 );
		} elseif ( null !== $status['checked_at'] ) {
			$text = self::sentences(
				self::sentences(
					/* translators: %s: time since the last full check, e.g. "3 hours" */
					sprintf( __( 'Every redirect was last checked %s ago.', 'legacy-redirector' ), human_time_diff( $status['checked_at'] ) ),
					/* translators: %s: number of redirects with problems */
					sprintf( _n( '%s redirect has a problem.', '%s redirects have problems.', $counts['problem'], 'legacy-redirector' ), number_format_i18n( $counts['problem'] ) )
				),
				/* translators: %s: number of redirects with warnings */
				sprintf( _n( '%s redirect has a warning.', '%s redirects have warnings.', $counts['warning'], 'legacy-redirector' ), number_format_i18n( $counts['warning'] ) )
			);
		} else {
			$text = __( 'Not every redirect has been checked yet.', 'legacy-redirector' );
		}

		if ( $this->sweep->budget() <= 0.0 ) {
			return self::sentences( $text, __( 'The background check is turned off.', 'legacy-redirector' ) );
		}

		if ( null !== $status['estimate'] ) {
			$text = self::sentences(
				$text,
				/* translators: %s: estimated time for a full check, e.g. "2 days" */
				sprintf( __( 'A full check in the background takes about %s.', 'legacy-redirector' ), human_time_diff( 0, $status['estimate'] ) )
			);
		}

		return $text;
	}

	/**
	 * Put one sentence after another, as the language spaces them.
	 *
	 * @param string $first  The first sentence.
	 * @param string $second The sentence after it.
	 * @return string
	 */
	private static function sentences( string $first, string $second ): string {
		/* translators: 1: a sentence; 2: the sentence after it. Translate the space between them as your language separates sentences. */
		return sprintf( _x( '%1$s %2$s', 'two sentences, one after the other', 'legacy-redirector' ), $first, $second );
	}

	/**
	 * Say how far a pass has got, and whether leaving the page is fine.
	 *
	 * Shared with the REST route behind the button, so the line reads the
	 * same whether the page rendered it or a batch request returned it, and
	 * follows the language's plural rules either way. With the background
	 * check turned off, nothing carries the pass on, so it doesn't say so.
	 *
	 * @param int  $checked    Redirects checked so far.
	 * @param int  $total      Redirects in the pass.
	 * @param bool $background Whether the background check will carry the pass on.
	 * @return string The progress line.
	 */
	public static function progress_text( int $checked, int $total, bool $background ): string {
		if ( ! $background ) {
			return sprintf(
				/* translators: 1: redirects checked so far, 2: total redirects */
				_n( 'Validation in progress: %1$s of %2$s redirect checked. Stay on this page until it finishes.', 'Validation in progress: %1$s of %2$s redirects checked. Stay on this page until it finishes.', $total, 'legacy-redirector' ),
				number_format_i18n( $checked ),
				number_format_i18n( $total )
			);
		}

		return sprintf(
			/* translators: 1: redirects checked so far, 2: total redirects */
			_n(
				'Validation in progress: %1$s of %2$s redirect checked. It carries on in the background if you leave this page.',
				'Validation in progress: %1$s of %2$s redirects checked. It carries on in the background if you leave this page.',
				$total,
				'legacy-redirector'
			),
			number_format_i18n( $checked ),
			number_format_i18n( $total )
		);
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
			'path'   => '/legacy-redirector/v1/validate',
			'failed' => __( 'Validation stopped; the redirects validated so far are flagged. Reload and try again.', 'legacy-redirector' ),
		);
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var config = <?php echo wp_json_encode( $config ); ?>;
			var box = document.getElementById('legacy-redirector-validate-all');
			if (!box) {
				return;
			}

			var button = box.querySelector('button');
			var bar = box.querySelector('progress');
			var status = box.querySelector('span');

			// The message comes phrased from the server, which knows the
			// language's plural rules.
			function report(data) {
				bar.max = Math.max(data.total, 1);
				bar.value = data.checked;
				status.textContent = data.message;
			}

			function runBatch(restart) {
				return wp.apiFetch({
					path: config.path,
					method: 'POST',
					data: { restart: restart }
				});
			}

			button.addEventListener('click', async function () {
				button.disabled = true;
				bar.hidden = false;
				// A pass already under way is continued, not restarted.
				var restart = '1' === button.dataset.restart;

				try {
					for (;;) {
						var data = await runBatch(restart);
						// From here on a retry continues this pass, even after
						// a failed batch.
						restart = false;
						button.dataset.restart = '0';
						report(data);
						if (data.done) {
							window.location.reload();
							return;
						}
					}
				} catch (e) {
					// The server's own message says why, such as another
					// check holding the lock.
					status.textContent = ( e && e.message ) || config.failed;
					button.disabled = false;
				}
			});
		});
		</script>
		<?php
	}
}
