<?php
/**
 * ChecksController REST integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Rest
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Rest;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for the REST check endpoints.
 *
 * Requests are dispatched through a real WP_REST_Server, so routes,
 * argument handling, permission callbacks, and the response shapes consumed
 * by js/admin-redirect-form.js and the inline list table script are all
 * exercised end to end. The routes are registered by the plugin's own
 * rest_api_init hook, wired in PluginBootstrapper.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ChecksController
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ScanController
 */
final class ChecksControllerTest extends TestCase {

	/**
	 * Default HTTP mock marking every destination reachable.
	 *
	 * @var callable
	 */
	private $http_ok;

	/**
	 * Spin up a REST server and mock outbound HTTP.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// The reachability check and the live probe would otherwise make real
		// requests. Default to reachable; tests that need something else add
		// their own filter, which runs later and wins.
		$this->http_ok = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $this->http_ok );
	}

	/**
	 * Clean up the REST server, HTTP mock, and current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http_ok );

		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Sign in as a user able to manage redirects.
	 *
	 * @return void
	 */
	private function login_as_redirect_manager(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Dispatch a REST request and return the response.
	 *
	 * @param string               $method Request method.
	 * @param string               $route  Route, without the /wp-json prefix.
	 * @param array<string, mixed> $params Request parameters.
	 * @return \WP_REST_Response The dispatched response.
	 */
	private function do_request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test all three routes reject an anonymous request.
	 */
	public function test_anonymous_request_is_rejected(): void {
		$this->assertSame( 401, $this->do_request( 'GET', '/legacy-redirector/v1/check-source', array( 'source' => '/old-page' ) )->get_status() );
		$this->assertSame( 401, $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => '/new-page' ) )->get_status() );
		$this->assertSame( 401, $this->do_request( 'POST', '/legacy-redirector/v1/redirects/1/test' )->get_status() );
	}

	/**
	 * Test all three routes reject a user without the manage capability.
	 */
	public function test_request_without_capability_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->do_request( 'GET', '/legacy-redirector/v1/check-source', array( 'source' => '/old-page' ) )->get_status() );
		$this->assertSame( 403, $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => '/new-page' ) )->get_status() );
		$this->assertSame( 403, $this->do_request( 'POST', '/legacy-redirector/v1/redirects/1/test' )->get_status() );
	}

	/**
	 * Test a source URL with an existing redirect reports a duplicate.
	 */
	public function test_existing_source_reports_duplicate(): void {
		$this->login_as_redirect_manager();
		$this->create_redirect( '/duplicated-page', 'https://example.com/destination' );

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-source', array( 'source' => '/duplicated-page' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'exists'   => true,
				'reserved' => false,
			),
			$response->get_data()
		);
	}

	/**
	 * Test a source URL without a redirect reports no duplicate.
	 */
	public function test_unknown_source_reports_no_duplicate(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-source', array( 'source' => '/never-redirected' ) );

		$this->assertSame(
			array(
				'exists'   => false,
				'reserved' => false,
			),
			$response->get_data()
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

		$response = $this->do_request(
			'GET',
			'/legacy-redirector/v1/check-source',
			array(
				'source'     => '/edited-page',
				'exclude_id' => $redirect_id,
			)
		);

		$this->assertSame(
			array(
				'exists'   => false,
				'reserved' => false,
			),
			$response->get_data()
		);
	}

	/**
	 * Test an empty source URL reports no duplicate.
	 */
	public function test_empty_source_reports_no_duplicate(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-source' );

		$this->assertSame(
			array(
				'exists'   => false,
				'reserved' => false,
			),
			$response->get_data()
		);
	}

	/**
	 * Test a source WordPress itself serves is flagged as reserved.
	 *
	 * It is a warning, not a duplicate: the form can still save it.
	 */
	public function test_reserved_source_is_flagged(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-source', array( 'source' => 'wp-admin/' ) );

		$this->assertSame(
			array(
				'exists'   => false,
				'reserved' => true,
			),
			$response->get_data()
		);
	}

	/**
	 * Test an allowed external host reports host_allowed.
	 */
	public function test_allowed_host_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => 'https://example.com/page' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'host_allowed' => true ), $response->get_data() );
	}

	/**
	 * Test a host missing from allowed_redirect_hosts is flagged.
	 */
	public function test_disallowed_host_is_flagged(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => 'https://not-allowed.example.net/page' ) );

		$this->assertSame( array( 'host_allowed' => false ), $response->get_data() );
	}

	/**
	 * Test a relative path has no host to disallow.
	 */
	public function test_relative_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => '/new-page' ) );

		$this->assertSame( array( 'host_allowed' => true ), $response->get_data() );
	}

	/**
	 * Test a post ID destination has no host to disallow.
	 */
	public function test_post_id_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => '123' ) );

		$this->assertSame( array( 'host_allowed' => true ), $response->get_data() );
	}

	/**
	 * Test a malformed destination is left for submit-time validation.
	 */
	public function test_malformed_destination_reports_allowed(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'GET', '/legacy-redirector/v1/check-destination', array( 'destination' => 'ftp://example.com/file' ) );

		$this->assertSame( array( 'host_allowed' => true ), $response->get_data() );
	}

	/**
	 * Test an ID with no redirect behind it is a 404.
	 */
	public function test_unknown_redirect_id_is_not_found(): void {
		$this->login_as_redirect_manager();

		$response = $this->do_request( 'POST', '/legacy-redirector/v1/redirects/999999/test' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'legacy_redirector_not_found', $response->get_data()['code'] );
	}

	/**
	 * Test a redirect to a reachable destination reports valid.
	 */
	public function test_reachable_destination_reports_valid(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/validated-page', 'https://example.com/destination' );

		$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'valid'    => true,
				'status'   => 'valid',
				'message'  => 'Redirect is valid.',
				'warnings' => array(),
				// The blanket 200 mock means the probe sees the source
				// serving content rather than redirecting.
				'probe'    => array(
					'status'  => 'dormant',
					'message' => 'The source currently serves content, so the redirect lies dormant and did not fire.',
				),
			),
			$response->get_data()
		);
	}

	/**
	 * Test the probe confirms a source that live-redirects to its destination.
	 */
	public function test_probe_confirms_a_live_redirect(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/probe-source', 'https://example.com/destination' );

		$respond = static function ( $preempt, $args, $url ) {
			if ( str_contains( (string) $url, '/probe-source' ) ) {
				return array(
					'response' => array( 'code' => 301 ),
					'headers'  => array( 'location' => 'https://example.com/destination' ),
				);
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond, 20, 3 );

		try {
			$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );
		} finally {
			remove_filter( 'pre_http_request', $respond, 20 );
		}

		$data = $response->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'confirmed', $data['probe']['status'] );
		$this->assertStringContainsString( 'Confirmed live', $data['probe']['message'] );
	}

	/**
	 * Test the probe reports a redirect that is not firing.
	 */
	public function test_probe_reports_a_redirect_that_does_not_fire(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/probe-missing', 'https://example.com/destination' );

		$respond = static function ( $preempt, $args, $url ) {
			if ( str_contains( (string) $url, '/probe-missing' ) ) {
				return array( 'response' => array( 'code' => 404 ) );
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond, 20, 3 );

		try {
			$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );
		} finally {
			remove_filter( 'pre_http_request', $respond, 20 );
		}

		$data = $response->get_data();
		$this->assertSame( 'not-firing', $data['probe']['status'] );
		$this->assertStringContainsString( 'did not fire', $data['probe']['message'] );
	}

	/**
	 * Test a valid redirect with a reserved source reports success with the
	 * warning riding along, instead of hiding it behind a clean pass.
	 */
	public function test_reserved_source_reports_valid_with_warning(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/wp-admin/', 'https://example.com/destination' );

		$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );

		$data = $response->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'valid', $data['status'] );
		$this->assertCount( 1, $data['warnings'] );
		$this->assertSame( 'Reserved WordPress path', $data['warnings'][0]['label'] );
		$this->assertStringContainsString( 'path WordPress itself serves', $data['warnings'][0]['description'] );
	}

	/**
	 * Test a redirect closing a loop reports success with the loop warning.
	 */
	public function test_loop_member_reports_valid_with_warning(): void {
		$this->login_as_redirect_manager();
		$this->create_redirect( '/rest-loop-a', '/rest-loop-b' );
		$redirect_id = $this->create_redirect( '/rest-loop-b', '/rest-loop-a' );

		$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );

		$data = $response->get_data();
		$this->assertTrue( $data['valid'] );
		$this->assertCount( 1, $data['warnings'] );
		$this->assertSame( 'Possible redirect loop', $data['warnings'][0]['label'] );
		$this->assertStringContainsString( 'leads back to this one', $data['warnings'][0]['description'] );
	}

	/**
	 * Test a destination returning HTTP 404 reports the 404 status.
	 *
	 * A failing redirect is still a successful test, so this is a 200 with
	 * valid: false, not an HTTP error.
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
		add_filter( 'pre_http_request', $respond_404, 20 );

		try {
			$response = $this->do_request( 'POST', "/legacy-redirector/v1/redirects/{$redirect_id}/test" );
		} finally {
			remove_filter( 'pre_http_request', $respond_404, 20 );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'valid'    => false,
				'status'   => 'url_not_found',
				'message'  => 'The destination URL returns a 404 Not Found response.',
				'warnings' => array(),
				// The blanket 404 mock means the probed source 404s
				// without redirecting, too.
				'probe'    => array(
					'status'  => 'not-firing',
					'message' => 'The source returns a 404 without redirecting: the redirect did not fire.',
				),
			),
			$response->get_data()
		);
	}
}
