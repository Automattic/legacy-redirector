<?php
/**
 * Admin notice shown while the background data migration is still running.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Surfaces the otherwise-silent background data migration on the plugin's
 * admin screens.
 *
 * The migration runs itself in batches on `init`, so no action is required.
 * This notice exists so that a site with a very large redirect set can see
 * why 1.x redirects may not fire yet, and how to finish the job immediately
 * via WP-CLI. It deliberately shows no remaining count: counting pending
 * work walks the whole redirect set (see Upgrader::count_pending()), which
 * is exactly what a screen aimed at large sites should not do on every load.
 */
final class UpgradeNotice {

	/**
	 * The data upgrade routine.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Constructor.
	 *
	 * @param Upgrader $upgrader The data upgrade routine.
	 */
	public function __construct( Upgrader $upgrader ) {
		$this->upgrader = $upgrader;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display' ) );
	}

	/**
	 * Display the migration-in-progress notice on the plugin's screens.
	 *
	 * @return void
	 */
	public function display(): void {
		if ( ! $this->upgrader->needs_upgrade() ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || PostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: WP-CLI command */
			__( 'Redirect data migration is in progress and completes automatically in the background. Older redirects may not work or display consistently until it finishes. To finish it now, run: %s', 'legacy-redirector' ),
			'<code>wp legacy-redirector migrate</code>'
		);
		wp_admin_notice(
			wp_kses( $message, array( 'code' => array() ) ),
			array(
				'type'        => 'info',
				'dismissible' => true,
			)
		);
	}
}
