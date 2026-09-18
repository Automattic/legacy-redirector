<?php
/**
 * Integration tests testcase
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Yoast\WPTestUtils\WPIntegration\TestCase as WPTestUtilsTestCase;

/**
 * Integrations test testcase class
 */
abstract class TestCase extends WPTestUtilsTestCase {
	use RedirectTestHelper;

	/**
	 * Makes sure the foundational stuff is sorted so tests work.
	 */
	public function set_up() {
		parent::set_up();

		// We need to trick the plugin into thinking it's run by WP-CLI.
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		// We need to trick the plugin into thinking we're in admin.
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		$this->allow_fixture_hosts();
	}

	/**
	 * Undoes any capability registration a test triggered.
	 *
	 * Production code never removes the capability, so tests that let
	 * Capability::register() run need this to get back to a clean slate.
	 */
	public function tear_down() {
		$this->reset_manage_redirects_capability();

		parent::tear_down();
	}

	/**
	 * Strips the manage_redirects capability and its version option.
	 *
	 * @return void
	 */
	protected function reset_manage_redirects_capability(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof \WP_Role ) {
				$role->remove_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
			}
		}

		// Mirrors Capability::VERSION_OPTION_KEY, which is private.
		delete_option( Capability::MANAGE_REDIRECTS_CAPABILITY . '_capability_version' );
	}
}
