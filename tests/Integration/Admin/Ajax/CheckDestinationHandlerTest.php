<?php
/**
 * CheckDestinationHandler AJAX integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckDestinationHandler;
use WPAjaxDieStopException;

/**
 * Integration tests for the destination check AJAX endpoint.
 *
 * The response shape asserted here is the exact contract consumed by
 * js/admin-redirect-form.js, which reads response.success and
 * response.data.host_allowed.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckDestinationHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class CheckDestinationHandlerTest extends AjaxHandlerTestCase {

	/**
	 * Set up the handler under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new CheckDestinationHandler( $this->auditor() ) )->register();
	}

	/**
	 * Test a request without a nonce is rejected before any check runs.
	 */
	public function test_request_without_nonce_is_rejected(): void {
		$this->login_as_redirect_manager();
		$_POST = array( 'redirect_to' => 'https://example.com/page' );

		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		$this->_handleAjax( CheckDestinationHandler::get_action() );
	}

	/**
	 * Test a valid nonce without the manage capability is rejected.
	 */
	public function test_request_without_capability_is_rejected(): void {
		$this->login_without_manage_capability();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => 'https://example.com/page' ) );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Permission denied.' ),
			),
			$response
		);
	}

	/**
	 * Test an allowed external host reports host_allowed.
	 */
	public function test_allowed_host_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => 'https://example.com/page' ) );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['host_allowed'] );
	}

	/**
	 * Test a host missing from allowed_redirect_hosts is flagged.
	 */
	public function test_disallowed_host_is_flagged(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => 'https://not-allowed.example.net/page' ) );

		$this->assertTrue( $response['success'] );
		$this->assertFalse( $response['data']['host_allowed'] );
	}

	/**
	 * Test a relative path has no host to disallow.
	 */
	public function test_relative_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => '/new-page' ) );

		$this->assertTrue( $response['data']['host_allowed'] );
	}

	/**
	 * Test a post ID destination has no host to disallow.
	 */
	public function test_post_id_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => '123' ) );

		$this->assertTrue( $response['data']['host_allowed'] );
	}

	/**
	 * Test a malformed destination is left for submit-time validation.
	 */
	public function test_malformed_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckDestinationHandler::get_action(), array( 'redirect_to' => 'ftp://example.com/file' ) );

		$this->assertTrue( $response['data']['host_allowed'] );
	}
}
