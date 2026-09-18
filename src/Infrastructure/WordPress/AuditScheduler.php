<?php
/**
 * Scheduled daily audit.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;

/**
 * Runs a daily batch audit so the recorded summary stays fresh.
 *
 * The menu badge reads the recorded summary; without a scheduled run it only
 * updates when somebody happens to audit, which is exactly when they least
 * need reminding. Mirrors the CLI defaults - enabled redirects, up to 1000,
 * no HTTP requests - so the badge counts problems visitors can actually hit
 * without the cron run making outbound requests.
 */
final class AuditScheduler {

	/**
	 * The cron hook.
	 *
	 * @var string
	 */
	public const string HOOK = 'legacy_redirector_scheduled_audit';

	/**
	 * Largest number of redirects a scheduled run will check.
	 *
	 * @var int
	 */
	private const int LIMIT = 1000;

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
	 * @param AuditResults                     $results          The stored audit results.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		AuditResults $results
	) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->results          = $results;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily run if it is not already scheduled.
	 *
	 * Self-healing rather than activation-hooked, so the schedule survives
	 * anything that clears cron events.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled run.
	 *
	 * Called on plugin deactivation, so a deactivated plugin does not leave a
	 * cron event firing into a void.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Run the audit and record its outcome.
	 *
	 * @return void
	 */
	public function run(): void {
		$redirects = $this->query_repository->find_matching(
			new RedirectCriteria(
				'enabled',
				null, // destination_type.
				null, // search.
				'date',
				'DESC',
				self::LIMIT,
				0
			)
		);

		$this->results->record( $this->auditor->audit_batch( $redirects ), count( $redirects ), false );
	}
}
