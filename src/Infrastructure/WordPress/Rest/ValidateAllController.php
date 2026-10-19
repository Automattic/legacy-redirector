<?php
/**
 * REST route behind the "Validate all redirects" button.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Rest;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Drives the audit sweep from the browser, one batch per request.
 *
 * The list screen's "Validate all redirects" button calls this repeatedly: the first call
 * restarts the pass from the lowest ID, later ones continue it, ignoring the
 * background budget. It is the same runner, cursor and lock as the
 * background check (AuditSweep), so if the browser stops part-way the
 * background carries on from where it left off.
 *
 * HTTP checks stay out: firing requests at customer URLs in bulk from an
 * admin session is slow and hard to bound, so this runs the auditor's
 * stored-data checks only.
 */
final class ValidateAllController {

	private const string ROUTE_NAMESPACE = 'legacy-redirector/v1';

	/**
	 * Seconds a request waits for a background tick to finish its batch.
	 *
	 * A tick holds the lock for its budget plus one batch; waiting this long
	 * covers the default budget without nearing a request's time limit.
	 *
	 * @var int
	 */
	private const int LOCK_WAIT = 25;

	/**
	 * The audit sweep.
	 *
	 * @var AuditSweep
	 */
	private AuditSweep $sweep;

	/**
	 * Seconds to wait for the lock.
	 *
	 * @var int
	 */
	private int $lock_wait;

	/**
	 * Constructor.
	 *
	 * @param AuditSweep $sweep     The audit sweep.
	 * @param int        $lock_wait Seconds to wait for the lock.
	 */
	public function __construct( AuditSweep $sweep, int $lock_wait = self::LOCK_WAIT ) {
		$this->sweep     = $sweep;
		$this->lock_wait = $lock_wait;
	}

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_batch_request' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'restart' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may manage redirects.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Handle one batch request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{checked: int, total: int, done: bool}|\WP_Error The pass progress, or an error when the sweep stayed busy.
	 */
	public function run_batch_request( \WP_REST_Request $request ) { // phpcs:ignore NeutronStandard.Functions.TypeHint.NoReturnType -- Array or WP_Error.
		return $this->run_batch( (bool) $request->get_param( 'restart' ) );
	}

	/**
	 * Audit one batch of the pass, restarting the pass first if asked.
	 *
	 * The progress is the pass's, counted server-side, so it stays right
	 * when a background tick has audited batches between requests.
	 *
	 * @param bool $restart Whether to start a fresh pass from the lowest ID.
	 * @return array{checked: int, total: int, done: bool}|\WP_Error The pass progress, or an error when the sweep stayed busy.
	 */
	public function run_batch( bool $restart ) { // phpcs:ignore NeutronStandard.Functions.TypeHint.NoReturnType -- Array or WP_Error.
		if ( ! $this->sweep->lock( $this->lock_wait ) ) {
			return new \WP_Error(
				'legacy_redirector_validate_busy',
				__( 'Another check is running. Try again in a minute.', 'legacy-redirector' ),
				array( 'status' => 409 )
			);
		}

		try {
			if ( $restart ) {
				$this->sweep->start_pass();
			}

			$batch = $this->sweep->run_batch();
		} finally {
			$this->sweep->unlock();
		}

		$status = $this->sweep->status();

		return array(
			'checked' => $batch['done'] ? $status['total'] : $status['checked'],
			'total'   => $status['total'],
			'done'    => $batch['done'],
		);
	}
}
