<?php
/**
 * ScanController REST integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Rest
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Rest;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ScanController;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for the batched scan route.
 *
 * Requests are dispatched through a real WP_REST_Server, so the route,
 * argument handling, the permission callback, and the response shape
 * consumed by the ScanButton batch loop (checked, total, done) are all
 * exercised end to end. The route is registered by the plugin's own
 * rest_api_init hook, wired in PluginBootstrapper.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ScanController
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep
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
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ChecksController
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class ScanControllerTest extends TestCase {

	/**
	 * Spin up a REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Clean up the REST server, recorded run state, and current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		foreach ( array( AuditSweep::STATE_OPTION, AuditSweep::LOCK_OPTION, AuditFlags::CHECKED_AT_OPTION, AuditFlags::COUNTS_OPTION ) as $option ) {
			delete_option( $option );
		}

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
	 * Dispatch a batch request and return the response.
	 *
	 * @param bool $restart Whether to start a fresh pass.
	 * @return \WP_REST_Response The dispatched response.
	 */
	private function do_request( bool $restart = true ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/legacy-redirector/v1/scan' );
		$request->set_param( 'restart', $restart );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test the route rejects an anonymous request.
	 */
	public function test_anonymous_request_is_rejected(): void {
		$this->assertSame( 401, $this->do_request()->get_status() );
	}

	/**
	 * Test the route rejects a user without the manage capability.
	 */
	public function test_request_without_capability_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->do_request()->get_status() );
	}

	/**
	 * Test a run flags problem rows, skips clean ones, and records the
	 * completed run for the view label and the menu badge.
	 */
	public function test_run_flags_rows_and_records_the_completed_run(): void {
		$this->login_as_redirect_manager();

		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$broken_id = $this->create_redirect( '/scan-broken', $post_id );
		$clean_id  = $this->create_redirect( '/scan-clean', 'https://example.com/fine' );
		wp_delete_post( $post_id, true );

		$response = $this->do_request();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'checked' => 2,
				'total'   => 2,
				'done'    => true,
			),
			$response->get_data()
		);

		$this->assertSame( 'problem', get_post_meta( $broken_id, AuditFlags::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $clean_id, AuditFlags::META_KEY, true ) );

		$this->assertNotNull( ( new AuditFlags() )->checked_at() );

		$this->assertSame( 1, ( new AuditFlags() )->problem_count() );
	}

	/**
	 * Test disabled redirects are checked too: one click from live, so their
	 * problems matter as much as an enabled row's.
	 */
	public function test_disabled_redirects_are_checked(): void {
		$this->login_as_redirect_manager();

		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$disabled_id = $this->create_redirect( '/scan-disabled', $post_id );
		wp_update_post(
			array(
				'ID'          => $disabled_id,
				'post_status' => 'draft',
			)
		);
		wp_delete_post( $post_id, true );

		$data = $this->do_request()->get_data();

		$this->assertSame( 1, $data['checked'] );
		$this->assertSame( 'problem', get_post_meta( $disabled_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test a run larger than one batch continues where the last batch stopped
	 * and only reports done on the final batch.
	 */
	public function test_batches_continue_the_pass_until_done(): void {
		$this->login_as_redirect_manager();

		$total = AuditSweep::BATCH_SIZE + 1;
		for ( $i = 1; $i <= $total; $i++ ) {
			$this->insert_redirect_post(
				array(
					'post_title'   => "/scan-batch-{$i}",
					'post_excerpt' => 'https://example.com/fine',
				)
			);
		}

		$first = $this->do_request()->get_data();

		$this->assertSame( AuditSweep::BATCH_SIZE, $first['checked'] );
		$this->assertSame( $total, $first['total'] );
		$this->assertFalse( $first['done'] );
		$this->assertNull( ( new AuditFlags() )->checked_at() );

		$second = $this->do_request( false )->get_data();

		$this->assertSame( $total, $second['checked'] );
		$this->assertTrue( $second['done'] );
		$this->assertNotNull( ( new AuditFlags() )->checked_at() );
	}

	/**
	 * Test pressing Scan again starts the pass over from the lowest ID.
	 */
	public function test_restart_starts_the_pass_over(): void {
		$this->login_as_redirect_manager();

		for ( $i = 1; $i <= AuditSweep::BATCH_SIZE + 1; $i++ ) {
			$this->insert_redirect_post( array( 'post_title' => "/scan-restart-{$i}" ) );
		}

		$this->assertSame( AuditSweep::BATCH_SIZE, $this->do_request()->get_data()['checked'] );
		$this->assertSame( AuditSweep::BATCH_SIZE, $this->do_request()->get_data()['checked'] );
	}

	/**
	 * Test a request reports a conflict while another runner holds the lock throughout.
	 */
	public function test_busy_sweep_reports_a_conflict(): void {
		global $wpdb;

		$this->login_as_redirect_manager();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture: a lock just taken by another runner.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, UNIX_TIMESTAMP(), 'off')", AuditSweep::LOCK_OPTION ) );
		$controller = new ScanController( new AuditSweep( $this->query_repository(), $this->auditor(), new AuditFlags() ), 0 );
		$result     = $controller->run_batch( true );

		$this->assertWPError( $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}
}
