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
	 * Run code that may redirect and return the redirect it sends, without letting it exit.
	 *
	 * Throws from x_redirect_by, the last filter wp_redirect() applies, so
	 * the code under test stops before it sends a header or reaches its exit.
	 * Any other exception the code throws is passed on.
	 *
	 * @param callable $action The code to run.
	 * @return array{location: string, status: int, by: string}|null The redirect, or null if none was sent.
	 *
	 * @throws \RuntimeException Whatever the code throws, other than the capture's own.
	 */
	protected function redirect_sent_by( callable $action ): ?array {
		$sent    = null;
		$capture = static function ( $by, $status, $location ) use ( &$sent ) {
			$sent = array(
				'location' => (string) $location,
				'status'   => (int) $status,
				'by'       => (string) $by,
			);

			throw new \RuntimeException( 'Redirect sent.' );
		};

		add_filter( 'x_redirect_by', $capture, PHP_INT_MAX, 3 );

		try {
			$action();
		} catch ( \RuntimeException $e ) {
			if ( null === $sent ) {
				throw $e;
			}
		} finally {
			remove_filter( 'x_redirect_by', $capture, PHP_INT_MAX );
		}

		return $sent;
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
