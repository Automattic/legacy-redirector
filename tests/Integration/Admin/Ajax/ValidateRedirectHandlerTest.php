<?php
/**
 * ValidateRedirectHandler AJAX integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler;
use WPAjaxDieStopException;

/**
 * Integration tests for the redirect validation AJAX endpoint.
 *
 * The response shapes asserted here are the exact contract consumed by the
 * inline list table script in RowActionsManager, which reads
 * response.success and response.data.message (plus data.status on errors).
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\ValidateRedirectHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ValidateRedirectHandlerTest extends AjaxHandlerTestCase {

	/**
	 * Default HTTP mock marking every destination reachable.
	 *
	 * @var callable
	 */
	private $http_ok;

	/**
	 * Set up the handler under test and mock outbound HTTP.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new ValidateRedirectHandler( $this->repository(), $this->auditor() ) )->register();

		// The reachability check would otherwise make a real request. Default
		// to reachable; the 404 test adds its own filter, which runs later
		// and wins.
		$this->http_ok = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $this->http_ok );
	}

	/**
	 * Clean up the HTTP mock.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http_ok );

		parent::tear_down();
	}

	/**
	 * Test a request without a nonce is rejected before any validation runs.
	 */
	public function test_request_without_nonce_is_rejected(): void {
		$this->login_as_redirect_manager();
		$_POST = array( 'redirect_id' => '1' );

		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		$this->_handleAjax( ValidateRedirectHandler::get_action() );
	}

	/**
	 * Test a valid nonce without the manage capability is rejected.
	 */
	public function test_request_without_capability_is_rejected(): void {
		$this->login_without_manage_capability();

		$response = $this->dispatch( ValidateRedirectHandler::get_action(), array( 'redirect_id' => '1' ) );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Permission denied.' ),
			),
			$response
		);
	}

	/**
	 * Test a missing redirect ID is rejected.
	 */
	public function test_missing_redirect_id_is_rejected(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( ValidateRedirectHandler::get_action() );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Invalid redirect ID.' ),
			),
			$response
		);
	}

	/**
	 * Test an ID with no redirect behind it reports the not-found status.
	 */
	public function test_unknown_redirect_id_reports_not_found_status(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( ValidateRedirectHandler::get_action(), array( 'redirect_id' => '999999' ) );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array(
					'status'  => 'not-found',
					'message' => 'Redirect not found.',
				),
			),
			$response
		);
	}

	/**
	 * Test a redirect to a reachable destination reports valid.
	 */
	public function test_reachable_destination_reports_valid(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/validated-page', 'https://example.com/destination' );

		$response = $this->dispatch( ValidateRedirectHandler::get_action(), array( 'redirect_id' => (string) $redirect_id ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'status'  => 'valid',
					'message' => 'Redirect is valid.',
				),
			),
			$response
		);
	}

	/**
	 * Test a destination returning HTTP 404 reports the 404 status.
	 */
	public function test_unreachable_destination_reports_404(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/broken-page', 'https://example.com/gone' );

		$respond_404 = static function () {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond_404 );

		try {
			$response = $this->dispatch( ValidateRedirectHandler::get_action(), array( 'redirect_id' => (string) $redirect_id ) );
		} finally {
			remove_filter( 'pre_http_request', $respond_404 );
		}

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array(
					'status'  => 'url_not_found',
					'message' => 'The destination URL returns a 404 Not Found response.',
				),
			),
			$response
		);
	}
}
