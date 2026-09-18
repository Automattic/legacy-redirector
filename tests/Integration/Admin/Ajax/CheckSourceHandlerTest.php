<?php
/**
 * CheckSourceHandler AJAX integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin\Ajax;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler;
use WPAjaxDieStopException;

/**
 * Integration tests for the source check AJAX endpoint.
 *
 * The response shapes asserted here are the exact contract consumed by
 * js/admin-redirect-form.js, which reads response.success,
 * response.data.exists and response.data.reserved.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Ajax\CheckSourceHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
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
final class CheckSourceHandlerTest extends AjaxHandlerTestCase {

	/**
	 * Set up the handler under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new CheckSourceHandler( $this->repository(), $this->auditor() ) )->register();
	}

	/**
	 * Test a request without a nonce is rejected before any lookup runs.
	 */
	public function test_request_without_nonce_is_rejected(): void {
		$this->login_as_redirect_manager();
		$_POST = array( 'redirect_from' => '/old-page' );

		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		$this->_handleAjax( CheckSourceHandler::get_action() );
	}

	/**
	 * Test a valid nonce without the manage capability is rejected.
	 */
	public function test_request_without_capability_is_rejected(): void {
		$this->login_without_manage_capability();

		$response = $this->dispatch( CheckSourceHandler::get_action(), array( 'redirect_from' => '/old-page' ) );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => array( 'message' => 'Permission denied.' ),
			),
			$response
		);
	}

	/**
	 * Test a source URL with an existing redirect reports a duplicate.
	 */
	public function test_existing_source_reports_duplicate(): void {
		$this->login_as_redirect_manager();
		$this->create_redirect( '/duplicated-page', 'https://example.com/destination' );

		$response = $this->dispatch( CheckSourceHandler::get_action(), array( 'redirect_from' => '/duplicated-page' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'exists'   => true,
					'reserved' => false,
				),
			),
			$response
		);
	}

	/**
	 * Test a source URL without a redirect reports no duplicate.
	 */
	public function test_unknown_source_reports_no_duplicate(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckSourceHandler::get_action(), array( 'redirect_from' => '/never-redirected' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'reserved' => false,
				),
			),
			$response
		);
	}

	/**
	 * Test the excluded redirect ID is not reported as its own duplicate.
	 *
	 * The edit form passes the redirect being edited as exclude_id so saving
	 * without changing the source is not flagged.
	 */
	public function test_excluded_redirect_is_not_its_own_duplicate(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/edited-page', 'https://example.com/destination' );

		$response = $this->dispatch(
			CheckSourceHandler::get_action(),
			array(
				'redirect_from' => '/edited-page',
				'exclude_id'    => (string) $redirect_id,
			)
		);

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'reserved' => false,
				),
			),
			$response
		);
	}

	/**
	 * Test an empty source URL reports no duplicate.
	 */
	public function test_empty_source_reports_no_duplicate(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckSourceHandler::get_action(), array( 'redirect_from' => '' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'reserved' => false,
				),
			),
			$response
		);
	}

	/**
	 * Test a source WordPress itself serves is flagged as reserved.
	 *
	 * It is a warning, not a duplicate: the form can still save it.
	 */
	public function test_reserved_source_is_flagged(): void {
		$this->login_as_redirect_manager();

		$response = $this->dispatch( CheckSourceHandler::get_action(), array( 'redirect_from' => 'wp-admin/' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'reserved' => true,
				),
			),
			$response
		);
	}
}
