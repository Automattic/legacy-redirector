<?php
/**
 * Integration tests testcase
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

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
}
