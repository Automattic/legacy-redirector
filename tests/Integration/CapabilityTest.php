<?php
/**
 * Capability tests
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use WP_User;

/**
 * CapabilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 */
final class CapabilityTest extends TestCase {

	/**
	 * Test capabilities for Capability::MANAGE_REDIRECTS_CAPABILITY.
	 *
	 * @return void
	 */
	public function test_new_admin_capability() {
		$capability = new Capability();

		// We need to force clear capabilities here as the wp_options `roles` option might not get cleared after a failed test.
		$this->reset_manage_redirects_capability();

		// in WP_User class, if multisite and user is administrator, all capabilities are allowed, so this test is not useful.
		if ( ! is_multisite() ) {
			// We check if a new admin user does not have the redirect capability.
			$user_id = 1;
			$this->assertUserNotHasRedirectCapability( $user_id );
		}

		$this->assertRoleNotHasRedirectsCapability( 'administrator' );
		$this->assertRoleNotHasRedirectsCapability( 'editor' );
		$this->assertRoleNotHasRedirectsCapability( 'subscriber' );
		$this->assertRoleNotHasRedirectsCapability( '' );

		$capability->register();

		$this->assertRoleHasRedirectsCapability( 'administrator' );
		$this->assertRoleHasRedirectsCapability( 'editor' );
		$this->assertRoleNotHasRedirectsCapability( 'subscriber' ); // Should be no change.
		$this->assertRoleNotHasRedirectsCapability( '' ); // Should be no change.
	}

	/**
	 * Test registration is skipped once the stored version is current.
	 *
	 * @return void
	 */
	public function test_capability_registration_is_idempotent() {
		$capability = new Capability();

		$this->assertTrue( $capability->register() );
		$this->assertFalse( $capability->register() );

		$this->assertRoleHasRedirectsCapability( 'administrator' );
	}

	/**
	 * Check if a specific user has Redirects capability.
	 *
	 * @param int|WP_User $user ID of the user, or WP_User object.
	 * @return bool True if the user has the redirects capability, false otherwise.
	 */
	private function assertUserHasRedirectCapability( $user ) {
		if ( is_numeric( $user ) ) {
			$user = wp_set_current_user( $user );
		}

		return $this->assertTrue( $user->has_cap( Capability::MANAGE_REDIRECTS_CAPABILITY ) );
	}

	/**
	 * Check if a specific user does NOT have Redirects capability.
	 *
	 * @param int|WP_User $user ID of the user, or WP_User object.
	 * @return bool True if the user does not have the redirects capability, false otherwise.
	 */
	private function assertUserNotHasRedirectCapability( $user ) {
		if ( is_numeric( $user ) ) {
			$user = wp_set_current_user( $user );
		}

		return $this->assertFalse( $user->has_cap( Capability::MANAGE_REDIRECTS_CAPABILITY ) );
	}

	/**
	 * Check if a role has Redirects capability.
	 *
	 * @param string $role Name of the role to check e.g. administrator.
	 * @return bool True if the role has the redirects capability, false otherwise.
	 */
	private function assertRoleHasRedirectsCapability( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$user    = wp_set_current_user( $user_id );

		return $this->assertUserHasRedirectCapability( $user );
	}

	/**
	 * Check if a role does NOT have Redirects capability.
	 *
	 * @param string $role Name of the role to check e.g. administrator.
	 * @return bool True if the role does not have the redirects capability, false otherwise.
	 */
	private function assertRoleNotHasRedirectsCapability( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$user    = wp_set_current_user( $user_id );

		return $this->assertUserNotHasRedirectCapability( $user );
	}
}
