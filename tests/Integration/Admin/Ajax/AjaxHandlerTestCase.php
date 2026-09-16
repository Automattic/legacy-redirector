<?php
/**
 * Base test case for admin AJAX handler integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Tests\Integration\RedirectTestHelper;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;

/**
 * Shared fixture for exercising wp_ajax_* handlers end to end.
 *
 * WP_Ajax_UnitTestCase converts the AJAX wp_die() calls that end every
 * wp_send_json_*() response into exceptions, so the JSON body each handler
 * emits can be captured and asserted without ending the PHP process.
 */
abstract class AjaxHandlerTestCase extends WP_Ajax_UnitTestCase {
	use RedirectTestHelper;

	/**
	 * Set up capability registration.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();

		// A WP core older than the container's PHP emits engine deprecations
		// (e.g. implicit-nullable parameters in the bundled Requests library)
		// that land in the AJAX output buffer and corrupt the captured JSON.
		// The parent already drops E_WARNING for the same reason, and its
		// tear_down() restores the original level.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Test-only, mirroring the parent class; nothing is disclosed.
		error_reporting( error_reporting() & ~E_DEPRECATED );

		$_POST = array();
	}

	/**
	 * Clean up request superglobals, user and capabilities.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = array();

		wp_set_current_user( 0 );
		( new Capability() )->unregister();

		parent::tear_down();
	}

	/**
	 * Sign in as a user able to manage redirects.
	 *
	 * @return void
	 */
	protected function login_as_redirect_manager(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Sign in as a user without the manage redirects capability.
	 *
	 * @return void
	 */
	protected function login_without_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	/**
	 * Dispatch an AJAX request with a valid nonce and return the JSON response.
	 *
	 * @param string               $action The AJAX action.
	 * @param array<string, mixed> $post   Additional POST fields.
	 * @return array<string, mixed> The decoded JSON response, exactly as admin JS receives it.
	 */
	protected function dispatch( string $action, array $post = array() ): array {
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( $action ) ), $post );

		// _last_response accumulates across dispatches within a test.
		$this->_last_response = '';

		try {
			$this->_handleAjax( $action );
			$this->fail( 'Expected the handler to send a JSON response.' );
		} catch ( WPAjaxDieContinueException $e ) {
			// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_*() ends in wp_die( '' ), which the AJAX test die handler converts to this exception; reaching here is the success path.
			unset( $e );
		}

		try {
			return json_decode( $this->_last_response, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			$this->fail( "Handler emitted invalid JSON:\n" . $this->_last_response );
		}
	}
}
