<?php
/**
 * REST route for the batched "Check all" audit.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Rest;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Audits every redirect in paged batches, one request per batch.
 *
 * The list screen's Check all button calls this repeatedly with a rising
 * offset, so a site with 100k+ redirects is never audited in one request.
 * Each row's outcome lands in a per-row flag (AuditFlags) that the "Has
 * issues" view reads; the final batch stamps the run time and records the
 * site-wide summary the menu badge shows.
 *
 * HTTP checks stay CLI-only: firing requests at customer URLs in bulk from an
 * admin session is slow and hard to bound, so this runs the auditor's
 * non-HTTP checks only.
 */
final class CheckAllController {

	private const string ROUTE_NAMESPACE = 'legacy-redirector/v1';

	/**
	 * Rows audited per request.
	 *
	 * Small enough that a batch - including the loop detector's per-row
	 * lookups - finishes well inside a request's time limits.
	 *
	 * @var int
	 */
	public const int BATCH_SIZE = 100;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * The stored audit results.
	 *
	 * @var AuditResults
	 */
	private AuditResults $results;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param AuditFlags                       $flags            The per-row audit flags.
	 * @param AuditResults                     $results          The stored audit results.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		AuditFlags $flags,
		AuditResults $results
	) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->flags            = $flags;
		$this->results          = $results;
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
			'/check-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_batch_request' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
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
	 * @return array{checked: int, total: int, done: bool} The batch outcome.
	 */
	public function run_batch_request( \WP_REST_Request $request ): array {
		return $this->run_batch( (int) $request->get_param( 'offset' ) );
	}

	/**
	 * Audit one batch of redirects and flag each row.
	 *
	 * Covers enabled and disabled redirects alike (a disabled row is one
	 * click from live, so its problems matter too), ordered by ID so the
	 * offset pagination is stable across batches: flag writes are post meta
	 * and never move a row. Rows created or deleted mid-run can shift later
	 * offsets; the run is a snapshot, and the next run picks them up.
	 *
	 * @param int $offset How many redirects earlier batches covered.
	 * @return array{checked: int, total: int, done: bool} The batch outcome.
	 */
	public function run_batch( int $offset ): array {
		$criteria = new RedirectCriteria(
			null, // status: enabled and disabled alike.
			null, // destination_type.
			null, // search.
			'ID',
			'ASC',
			self::BATCH_SIZE,
			$offset
		);

		$redirects = $this->query_repository->find_matching( $criteria );
		$total     = $this->query_repository->count_matching( $criteria );

		foreach ( $redirects as $redirect ) {
			$id = $redirect->id();

			if ( null === $id ) {
				continue;
			}

			$this->flags->record_row( $id, $this->auditor->audit( $redirect ) );
		}

		$checked = count( $redirects );
		$done    = 0 === $checked || ( $offset + $checked ) >= $total;

		if ( $done ) {
			$this->flags->mark_run_complete();

			// The summary the menu badge reads, counted from the stored flags
			// rather than accumulated across requests, so nothing the client
			// asserts can inflate it.
			$counts = $this->flags->counts();
			$this->results->record_counts( $counts['problem'], $counts['warning'], $total, false );
		}

		return array(
			'checked' => $checked,
			'total'   => $total,
			'done'    => $done,
		);
	}
}
