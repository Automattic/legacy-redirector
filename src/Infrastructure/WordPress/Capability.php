<?php
/**
 * Capability registration service.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

/**
 * Manages custom capability registration for redirect management.
 *
 * Registers and unregisters the manage_redirects capability on
 * administrator and editor roles, with VIP platform support.
 */
final class Capability {

	/**
	 * The capability name for managing redirects.
	 */
	public const string MANAGE_REDIRECTS_CAPABILITY = 'manage_redirects';

	/**
	 * Version number for capability registration.
	 *
	 * Increment this to force re-registration of capabilities.
	 */
	private const int CAPABILITIES_VERSION = 1;

	/**
	 * Option key for storing the capabilities version.
	 */
	private const string VERSION_OPTION_KEY = self::MANAGE_REDIRECTS_CAPABILITY . '_capability_version';

	/**
	 * Roles that should have the manage_redirects capability.
	 *
	 * @var array<string>
	 */
	private array $roles;

	/**
	 * Constructor.
	 *
	 * @param array<string> $roles Roles to grant the capability to. Defaults to administrator and editor.
	 */
	public function __construct( array $roles = array( 'administrator', 'editor' ) ) {
		$this->roles = $roles;
	}

	/**
	 * Whether the current user may manage redirects.
	 *
	 * @return bool True if the current user has the manage_redirects capability.
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Register the manage_redirects capability on configured roles.
	 *
	 * Uses VIP helper functions when available, with fallback to standard WordPress.
	 * Tracks version to avoid unnecessary re-registration on every request.
	 *
	 * @return bool True if capabilities were registered, false if already up to date.
	 */
	public function register(): bool {
		if ( $this->is_current_version() ) {
			return false;
		}

		$this->add_capabilities();
		$this->update_version();

		return true;
	}

	/**
	 * Unregister the manage_redirects capability from configured roles.
	 *
	 * Uses VIP helper functions when available, with fallback to standard WordPress.
	 *
	 * @return bool True when unregistration completes.
	 */
	public function unregister(): bool {
		$this->remove_capabilities();
		$this->delete_version();

		return true;
	}

	/**
	 * Get the capability name.
	 *
	 * @return string The manage_redirects capability name.
	 */
	public function get_capability_name(): string {
		return self::MANAGE_REDIRECTS_CAPABILITY;
	}

	/**
	 * Check if the current version is already registered.
	 *
	 * @return bool True if capabilities are current.
	 */
	private function is_current_version(): bool {
		return self::CAPABILITIES_VERSION <= (int) get_option( self::VERSION_OPTION_KEY, 0 );
	}

	/**
	 * Add capabilities to configured roles.
	 *
	 * @return void
	 */
	private function add_capabilities(): void {
		foreach ( $this->roles as $role_name ) {
			$this->add_capability_to_role( $role_name );
		}
	}

	/**
	 * Remove capabilities from configured roles.
	 *
	 * @return void
	 */
	private function remove_capabilities(): void {
		foreach ( $this->roles as $role_name ) {
			$this->remove_capability_from_role( $role_name );
		}
	}

	/**
	 * Add capability to a single role.
	 *
	 * @param string $role_name The role name.
	 * @return void
	 */
	private function add_capability_to_role( string $role_name ): void {
		if ( function_exists( 'wpcom_vip_add_role_caps' ) ) {
			wpcom_vip_add_role_caps( $role_name, self::MANAGE_REDIRECTS_CAPABILITY );
			return;
		}

		$role = get_role( $role_name );
		if ( $role instanceof \WP_Role ) {
			$role->add_cap( self::MANAGE_REDIRECTS_CAPABILITY );
		}
	}

	/**
	 * Remove capability from a single role.
	 *
	 * @param string $role_name The role name.
	 * @return void
	 */
	private function remove_capability_from_role( string $role_name ): void {
		if ( function_exists( 'wpcom_vip_remove_role_caps' ) ) {
			wpcom_vip_remove_role_caps( $role_name, self::MANAGE_REDIRECTS_CAPABILITY );
			return;
		}

		$role = get_role( $role_name );
		if ( $role instanceof \WP_Role ) {
			$role->remove_cap( self::MANAGE_REDIRECTS_CAPABILITY );
		}
	}

	/**
	 * Update the stored version number.
	 *
	 * @return void
	 */
	private function update_version(): void {
		update_option( self::VERSION_OPTION_KEY, self::CAPABILITIES_VERSION );
	}

	/**
	 * Delete the stored version number.
	 *
	 * @return void
	 */
	private function delete_version(): void {
		delete_option( self::VERSION_OPTION_KEY );
	}
}
