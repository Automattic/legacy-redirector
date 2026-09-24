<?php
/**
 * Admin notice listing the duplicate sources the 2.0 upgrade left to settle.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Tells admins about redirects the upgrade disabled as duplicate sources, and how to settle them.
 *
 * A site that migrated on ordinary page loads never sees the migrate
 * command's report, so without this the disabled rows would only be found
 * by someone happening to open the right view. Shown on the plugin's screens
 * while any remain; on the Duplicate sources view itself it gives the
 * choices instead of the link.
 */
final class DuplicateSourcesNotice {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display' ) );
	}

	/**
	 * Display the notice on the plugin's screens while duplicates remain.
	 *
	 * @return void
	 */
	public function display(): void {
		$screen = get_current_screen();
		if ( null === $screen || PostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		$count = Upgrader::duplicate_count();
		if ( 0 === $count ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display.
		$message = isset( $_GET['duplicate_sources'] )
			? __( 'For each redirect listed here, decide which destination is right. To keep the live redirect\'s, trash the disabled one. To keep the disabled one\'s, edit the live redirect (linked in the Status column) to use it, then trash the disabled one. A disabled duplicate cannot be enabled while the live redirect has its source.', 'legacy-redirector' )
			: sprintf(
				/* translators: 1: number of redirects, 2: link to the Duplicate sources view */
				_n(
					'The 2.0 upgrade disabled %1$s redirect because it has the same source as another redirect but a different destination, and only one redirect can answer a source. %2$s',
					'The 2.0 upgrade disabled %1$s redirects because each has the same source as another redirect but a different destination, and only one redirect can answer a source. %2$s',
					$count,
					'legacy-redirector'
				),
				number_format_i18n( $count ),
				sprintf( '<a href="%s">%s</a>', esc_url( ViewFilters::duplicate_sources_url() ), esc_html__( 'Review duplicate sources', 'legacy-redirector' ) )
			);

		wp_admin_notice(
			wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ),
			array(
				'type'        => 'warning',
				'dismissible' => true,
			)
		);
	}
}
