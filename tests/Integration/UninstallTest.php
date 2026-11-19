<?php
/**
 * Uninstall tests
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Deleting the plugin clears the background check's bookkeeping, and nothing else.
 *
 * The uninstall file runs without the plugin loaded, so it names its options as
 * literals; these tests read the names from the classes that own them, so a
 * renamed option fails here rather than being left behind on uninstall.
 *
 * The uninstalling cases define WP_UNINSTALL_PLUGIN, which cannot be undefined
 * again, so the case that runs without it comes first and skips once it is set.
 *
 * @coversNothing
 */
final class UninstallTest extends TestCase {

	/**
	 * Options the background check writes, which uninstall must remove.
	 *
	 * @var string[]
	 */
	private const array DERIVED_OPTIONS = array(
		AuditFlags::COUNTS_OPTION,
		AuditFlags::CHECKED_AT_OPTION,
		AuditSweep::STATE_OPTION,
		AuditSweep::LOCK_OPTION,
	);

	/**
	 * The upgrader's transients that stand web batches down: the WP-CLI lock and the web backoff.
	 *
	 * @var string[]
	 */
	private const array UPGRADER_TRANSIENTS = array( 'wpcom_legacy_redirector_upgrade_cli', 'wpcom_legacy_redirector_upgrade_backoff' );

	/**
	 * The option recording that the capability has been granted.
	 *
	 * @var string
	 */
	private const string CAPABILITY_VERSION_OPTION = Capability::MANAGE_REDIRECTS_CAPABILITY . '_capability_version';

	/**
	 * Test that including uninstall.php outside an uninstall changes nothing.
	 */
	public function test_does_nothing_outside_an_uninstall(): void {
		if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			$this->markTestSkipped( 'WP_UNINSTALL_PLUGIN is already defined in this process.' );
		}

		$this->seed_derived_state();

		$this->run_uninstall();

		foreach ( self::DERIVED_OPTIONS as $option ) {
			$this->assertNotFalse( get_option( $option ), "{$option} should survive." );
		}
		$this->assertNotFalse( wp_next_scheduled( AuditSweep::HOOK ) );
	}

	/**
	 * Test that uninstall removes the derived state and keeps the redirects.
	 */
	public function test_clears_derived_state_and_keeps_redirects(): void {
		$this->seed_derived_state();

		$redirect_id = self::factory()->post->create(
			array(
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '/old',
			)
		);
		update_post_meta( $redirect_id, AuditFlags::META_KEY, 'problem' );
		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION );
		update_option( self::CAPABILITY_VERSION_OPTION, 1 );
		get_role( 'administrator' )->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );

		$this->uninstall();

		foreach ( self::DERIVED_OPTIONS as $option ) {
			$this->assertFalse( get_option( $option ), "{$option} should be deleted." );
		}
		$this->assertFalse( wp_next_scheduled( AuditSweep::HOOK ) );
		$this->assertFalse( wp_next_scheduled( DestinationPostWatcher::HOOK, array( 123 ) ) );
		foreach ( self::UPGRADER_TRANSIENTS as $transient ) {
			$this->assertFalse( get_transient( $transient ), "{$transient} should be deleted." );
		}

		$this->assertSame( PostType::POST_TYPE, get_post_type( $redirect_id ) );
		$this->assertSame( 'problem', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
		$this->assertSame( Upgrader::DB_VERSION, (int) get_option( Upgrader::VERSION_OPTION ) );
		$this->assertNotFalse( get_option( self::CAPABILITY_VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capability::MANAGE_REDIRECTS_CAPABILITY ) );
	}

	/**
	 * Test that on a network, uninstall clears every site, not just the current one.
	 */
	public function test_clears_every_site_on_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$this->seed_derived_state();
		restore_current_blog();
		$this->seed_derived_state();

		$this->uninstall();

		foreach ( array( get_current_blog_id(), $site_id ) as $id ) {
			switch_to_blog( $id );
			foreach ( self::DERIVED_OPTIONS as $option ) {
				$this->assertFalse( get_option( $option ), "{$option} should be deleted on site {$id}." );
			}
			$this->assertFalse( wp_next_scheduled( AuditSweep::HOOK ), "The sweep should be unscheduled on site {$id}." );
			restore_current_blog();
		}
	}

	/**
	 * Write everything the background check and the upgrader leave behind.
	 */
	private function seed_derived_state(): void {
		foreach ( self::DERIVED_OPTIONS as $option ) {
			update_option( $option, 1, false );
		}
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, AuditSweep::HOOK );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, DestinationPostWatcher::HOOK, array( 123 ) );
		foreach ( self::UPGRADER_TRANSIENTS as $transient ) {
			set_transient( $transient, 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Include uninstall.php as WordPress does when the plugin is deleted.
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wpcom-legacy-redirector/wpcom-legacy-redirector.php' );
		}

		$this->run_uninstall();
	}

	/**
	 * Include uninstall.php.
	 */
	private function run_uninstall(): void {
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
