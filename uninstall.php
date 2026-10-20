<?php
/**
 * Clears the background check's bookkeeping when the plugin is deleted.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins
 * screen, without loading the plugin, so option names are written out here
 * rather than read from their classes (UninstallTest checks they still match).
 *
 * Only state the plugin can rebuild is removed. Redirects (posts, their meta
 * and their flags), the migration's options and the manage_redirects
 * capability are kept on purpose: a redirect map is expensive to recreate,
 * and reinstalling must not re-run the 1.x migration. docs/configuration.md
 * lists what is kept and how to remove it.
 *
 * Sites that load the plugin in code never run this file.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

( static function (): void {
	$clear_site = static function (): void {
		delete_option( 'legacy_redirector_audit_flag_counts' );
		delete_option( 'legacy_redirector_audit_flags_checked_at' );
		delete_option( 'legacy_redirector_audit_sweep' );
		delete_option( 'legacy_redirector_audit_lock' );
		delete_transient( 'wpcom_legacy_redirector_upgrade_cli' );
		delete_transient( 'wpcom_legacy_redirector_upgrade_backoff' );

		// Deactivation unschedules this too, but only on the site it runs on;
		// a network deactivation leaves the other sites' events behind.
		wp_clear_scheduled_hook( 'legacy_redirector_scheduled_audit' );

		// A destination re-audit still pending; carries the post ID as an
		// argument, which wp_clear_scheduled_hook() only matches exactly.
		wp_unschedule_hook( 'legacy_redirector_reaudit_destination' );
	};

	if ( ! is_multisite() ) {
		$clear_site();

		return;
	}

	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $site_id ) {
		switch_to_blog( (int) $site_id );
		$clear_site();
		restore_current_blog();
	}
} )();
