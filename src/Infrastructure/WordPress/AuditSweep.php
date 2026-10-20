<?php
/**
 * Background audit sweep.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;

/**
 * Walks the whole redirect table, flagging each row, a time-budgeted slice at a time.
 *
 * The one runner behind the background check, the admin "Validate all
 * redirects" button and `wp legacy-redirector validate`. They share a
 * keyset cursor (the last audited ID), so a pass costs the same at row 100
 * as at row 5,000,000, and a row deleted mid-pass cannot shift the next
 * batch; and they share a lock, so two runners never audit the same batch.
 *
 * In the background, a cron event every five minutes spends a fixed slice
 * of server time (the legacy_redirector_background_audit_seconds filter)
 * and exits. A new pass starts at most once a day, or straight after the
 * last one when a pass takes longer than that, so a small site is checked
 * daily and a large one as often as its budget allows. It runs the
 * auditor's stored-data checks only: requesting URLs in the background
 * would mostly report timeouts and throttling, not broken redirects.
 */
final class AuditSweep {

	/**
	 * The cron hook.
	 *
	 * @var string
	 */
	public const string HOOK = 'legacy_redirector_scheduled_audit';

	/**
	 * The cron schedule the sweep runs on.
	 *
	 * @var string
	 */
	public const string INTERVAL = 'legacy_redirector_five_minutes';

	/**
	 * The option holding the walk: cursor, progress, pass start and measured rate.
	 *
	 * Not autoloaded: only the sweep and the list screen read it.
	 *
	 * @var string
	 */
	public const string STATE_OPTION = 'legacy_redirector_audit_sweep';

	/**
	 * The option row held while a batch runs; see lock().
	 *
	 * @var string
	 */
	public const string LOCK_OPTION = 'legacy_redirector_audit_lock';

	/**
	 * The filter giving the seconds each background tick may spend.
	 *
	 * @var string
	 */
	public const string BUDGET_FILTER = 'legacy_redirector_background_audit_seconds';

	/**
	 * Rows audited per batch.
	 *
	 * Small enough that a batch - including the loop detector's per-row
	 * lookups - finishes well inside a web request's time limits.
	 *
	 * @var int
	 */
	public const int BATCH_SIZE = 100;

	/**
	 * Seconds a tick spends by default: about 7% of one PHP worker.
	 *
	 * @var int
	 */
	private const int DEFAULT_BUDGET = 20;

	/**
	 * Seconds between cron ticks.
	 *
	 * @var int
	 */
	private const int TICK_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Seconds after a lock was taken that it counts as abandoned.
	 *
	 * Far longer than any batch runs, so only a runner that died leaves one
	 * this old.
	 *
	 * @var int
	 */
	private const int LOCK_TIMEOUT = 5 * MINUTE_IN_SECONDS;

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
	 * The value of the lock row this runner holds, if any.
	 *
	 * @var string|null
	 */
	private ?string $lock_value = null;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param AuditFlags                       $flags            The per-row audit flags.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		AuditFlags $flags
	) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->flags            = $flags;
	}

	/**
	 * Register hooks.
	 *
	 * Registered in every context: cron events fire wherever WordPress loads.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Five minutes is the point: each tick is a short, budgeted slice.
		add_action( self::HOOK, array( $this, 'tick' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
	}

	/**
	 * Add the five-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules The cron schedules.
	 * @return array<string, array{interval: int, display: string}> The schedules with ours added.
	 */
	public function add_interval( array $schedules ): array {
		// Anything may ask for the schedules, at any point, and translating
		// before init makes WordPress warn that the text domain loaded too
		// early. The label is only read on screens that list cron events.
		$display = 'Every five minutes (Legacy Redirector background check)';
		if ( did_action( 'init' ) ) {
			$display = __( 'Every five minutes (Legacy Redirector background check)', 'legacy-redirector' );
		}

		$schedules[ self::INTERVAL ] = array(
			'interval' => self::TICK_SECONDS,
			'display'  => $display,
		);

		return $schedules;
	}

	/**
	 * Schedule the tick if it is not already scheduled on the right interval.
	 *
	 * Self-healing rather than activation-hooked, so the schedule survives
	 * anything that clears cron events, and an event left on an older
	 * interval is moved onto this one.
	 *
	 * @return void
	 */
	public function maybe_schedule(): void {
		if ( self::INTERVAL === wp_get_schedule( self::HOOK ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time(), self::INTERVAL, self::HOOK );
	}

	/**
	 * Remove the scheduled tick.
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
	 * The seconds each background tick may spend; 0 turns the sweep off.
	 *
	 * @return float The budget in seconds.
	 */
	public function budget(): float {
		/**
		 * Filters the seconds of server time each background check tick may spend.
		 *
		 * The background check runs every five minutes and audits redirects
		 * until this budget is spent, so the time a full pass takes grows with
		 * the number of redirects. Return 0 to turn it off, for example on a
		 * very large site that runs `wp legacy-redirector validate` off-peak instead.
		 *
		 * @param int|float $seconds Seconds per tick. Default 20.
		 */
		return max( 0.0, (float) apply_filters( self::BUDGET_FILTER, self::DEFAULT_BUDGET ) );
	}

	/**
	 * Run one background tick: audit batches until the budget is spent.
	 *
	 * Does nothing while another runner holds the lock, while the budget is
	 * zero, or while the last pass began less than a day ago and has finished.
	 *
	 * @return void
	 */
	public function tick(): void {
		$budget = $this->budget();

		// Checked before locking too, so an idle tick costs no writes.
		if ( $budget <= 0.0 || $this->resting() || ! $this->lock( 0 ) ) {
			return;
		}

		try {
			// Again under the lock: another runner may have finished a pass.
			if ( $this->resting() ) {
				return;
			}

			if ( ! $this->state()['in_progress'] ) {
				$this->start_pass();
			}

			$start = microtime( true );

			do {
				$batch = $this->run_batch();
			} while ( ! $batch['done'] && microtime( true ) - $start < $budget );
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Whether the last pass has finished and began less than a day ago.
	 *
	 * @return bool True when the background should wait for the next pass.
	 */
	private function resting(): bool {
		$state = $this->state();

		return ! $state['in_progress'] && time() - $state['started_at'] < DAY_IN_SECONDS;
	}

	/**
	 * Start a fresh pass from the lowest ID.
	 *
	 * The caller must hold the lock; see lock().
	 *
	 * @return void
	 */
	public function start_pass(): void {
		$state                = $this->state();
		$state['cursor']      = 0;
		$state['checked']     = 0;
		$state['started_at']  = time();
		$state['in_progress'] = true;

		$this->save_state( $state );
	}

	/**
	 * Audit the next batch after the cursor and flag each row.
	 *
	 * Covers enabled and disabled redirects alike: a disabled row is one
	 * click from live, so its problems matter too. A batch shorter than
	 * BATCH_SIZE means the walk reached the end: the pass is recorded as
	 * complete, with the flag counts the menu badge shows, and the cursor
	 * goes back to the start. The caller must hold the lock; see lock().
	 *
	 * @return array{audited: int, done: bool} How many rows this batch audited, and whether the pass is complete.
	 */
	public function run_batch(): array {
		$state = $this->state();

		// Another runner finished the pass between this runner's batches.
		if ( ! $state['in_progress'] ) {
			return array(
				'audited' => 0,
				'done'    => true,
			);
		}

		$start     = microtime( true );
		$redirects = $this->query_repository->find_after_id( $state['cursor'], self::BATCH_SIZE );

		foreach ( $redirects as $redirect ) {
			$id = (int) $redirect->id();

			$this->flags->record_row( $id, $this->auditor->audit( $redirect ) );
			$state['cursor'] = $id;
		}

		$audited           = count( $redirects );
		$state['checked'] += $audited;
		$done              = $audited < self::BATCH_SIZE;
		$state             = $this->with_rate( $state, $audited, microtime( true ) - $start );

		if ( $done ) {
			$state['cursor']      = 0;
			$state['in_progress'] = false;

			$this->flags->mark_run_complete();
			$this->flags->store_counts();
		}

		$this->save_state( $state );

		return array(
			'audited' => $audited,
			'done'    => $done,
		);
	}

	/**
	 * Validate the given redirects now, flag each, and recount the badge.
	 *
	 * The targeted counterpart of a full pass: `validate <redirect>` and the
	 * validate ability with named redirects. It needs no lock, because it
	 * does not move the cursor; it only rewrites these rows' flags, so
	 * re-checking one redirect after fixing something no event announces
	 * (a host allowed in code, a page published at a path) clears its flag.
	 *
	 * @param Redirect[] $redirects The redirects to validate.
	 * @return AuditFinding[] Every finding, in the order the redirects were given.
	 */
	public function validate_redirects( array $redirects ): array {
		$findings = array();

		foreach ( $redirects as $redirect ) {
			$found = $this->auditor->audit( $redirect );
			$this->flags->record_row( (int) $redirect->id(), $found );
			array_push( $findings, ...$found );
		}

		if ( array() !== $redirects ) {
			$this->flags->store_counts();
		}

		return $findings;
	}

	/**
	 * Where the sweep stands, for display.
	 *
	 * The estimate is how long a full pass takes in the background, worked
	 * out from the redirect count, the measured rate and the budget; null
	 * until a batch has measured a rate, or when the budget is zero.
	 *
	 * @return array{in_progress: bool, checked: int, total: int, checked_at: int|null, estimate: int|null} The sweep status.
	 */
	public function status(): array {
		$state  = $this->state();
		$total  = $this->total();
		$budget = $this->budget();

		$estimate = null;
		if ( $state['rate'] > 0.0 && $budget > 0.0 ) {
			$working_seconds = $total / $state['rate'];
			$estimate        = (int) ceil( $working_seconds / $budget ) * self::TICK_SECONDS;
		}

		return array(
			'in_progress' => $state['in_progress'],
			'checked'     => min( $state['checked'], $total ),
			'total'       => $total,
			'checked_at'  => $this->flags->checked_at(),
			'estimate'    => $estimate,
		);
	}

	/**
	 * How many redirects a pass covers.
	 *
	 * From core's post counts, which are cached until a post changes status,
	 * rather than a count query: the list screen asks on every load and the
	 * Validate all button after every batch, and each batch's flag writes
	 * would invalidate a cached query. The migration publishes drafts without
	 * clearing those counts, which moves rows between the two but leaves
	 * their sum alone.
	 *
	 * @return int The count of enabled and disabled redirects.
	 */
	public function total(): int {
		$counts = wp_count_posts( PostType::POST_TYPE );

		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
	}

	/**
	 * Take the lock, waiting up to a number of seconds for it.
	 *
	 * The row is created with INSERT IGNORE, which only one caller can do,
	 * where add_option() would quietly overwrite another's. A lock older than
	 * LOCK_TIMEOUT was left by a runner that died, and is taken over; the
	 * delete is conditional on its age, so two callers cannot both do so.
	 * Ages go by the database's clock, which every web server shares. Once
	 * held, the cached state is dropped: another runner may have moved the
	 * walk on while this one waited.
	 *
	 * @param int $wait Seconds to wait for another runner to finish a batch.
	 * @return bool Whether the lock was taken.
	 */
	public function lock( int $wait ): bool {
		global $wpdb;

		$deadline = microtime( true ) + $wait;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must go to the database; the options API caches and overwrites.
		while ( true ) {
			$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, UNIX_TIMESTAMP(), 'off')", self::LOCK_OPTION ) );

			if ( 1 === $taken ) {
				$this->lock_value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
				break;
			}

			$freed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < UNIX_TIMESTAMP() - %d", self::LOCK_OPTION, self::LOCK_TIMEOUT ) );

			if ( false === $taken || false === $freed ) {
				return false;
			}

			if ( 0 === $freed ) {
				if ( microtime( true ) >= $deadline ) {
					return false;
				}

				usleep( 250000 );
			}
		}
		// phpcs:enable

		wp_cache_delete( self::STATE_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	/**
	 * Release the lock, if this runner still holds it.
	 *
	 * A lock held past its age may have been taken over; deleting that one
	 * would let a third runner in beside the second.
	 *
	 * @return void
	 */
	public function unlock(): void {
		global $wpdb;

		if ( null === $this->lock_value ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock row lock() created.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $this->lock_value ) );
		$this->lock_value = null;
	}

	/**
	 * Fold a batch's measured rate into the rolling average.
	 *
	 * Every runner's batches count, so a pass run from the browser or the
	 * CLI gives the background estimate a rate before any tick has run.
	 *
	 * @param array{cursor: int, checked: int, started_at: int, in_progress: bool, rate: float} $state   The state.
	 * @param int                                                                               $rows    Rows the batch audited.
	 * @param float                                                                             $seconds Seconds it spent.
	 * @return array{cursor: int, checked: int, started_at: int, in_progress: bool, rate: float} The state with the rate updated.
	 */
	private function with_rate( array $state, int $rows, float $seconds ): array {
		if ( $rows < 1 || $seconds <= 0.0 ) {
			return $state;
		}

		$sample        = $rows / $seconds;
		$state['rate'] = $state['rate'] > 0.0 ? 0.8 * $state['rate'] + 0.2 * $sample : $sample;

		return $state;
	}

	/**
	 * The stored walk state.
	 *
	 * @return array{cursor: int, checked: int, started_at: int, in_progress: bool, rate: float} The state.
	 */
	private function state(): array {
		$stored = get_option( self::STATE_OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'cursor'      => (int) ( $stored['cursor'] ?? 0 ),
			'checked'     => (int) ( $stored['checked'] ?? 0 ),
			'started_at'  => (int) ( $stored['started_at'] ?? 0 ),
			'in_progress' => (bool) ( $stored['in_progress'] ?? false ),
			'rate'        => (float) ( $stored['rate'] ?? 0.0 ),
		);
	}

	/**
	 * Store the walk state.
	 *
	 * @param array{cursor: int, checked: int, started_at: int, in_progress: bool, rate: float} $state The state.
	 * @return void
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}
}
