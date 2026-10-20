<?php
/**
 * Background audit sweep integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\LoopDetector;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Integration tests for the sweep, its schedule, and the menu badge it feeds.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class AuditSweepTest extends TestCase {

	/**
	 * IDs the recording auditor has audited, in order.
	 *
	 * @var int[]
	 */
	private array $audited = array();

	/**
	 * Clean up the sweep's state, the badge and the schedule.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array( AuditSweep::STATE_OPTION, AuditSweep::LOCK_OPTION, AuditFlags::CHECKED_AT_OPTION, AuditFlags::COUNTS_OPTION ) as $option ) {
			delete_option( $option );
		}

		remove_all_filters( AuditSweep::BUDGET_FILTER );
		unset( $GLOBALS['menu'] );
		AuditSweep::unschedule();

		parent::tear_down();
	}

	/**
	 * Build a sweep whose auditor records every row it audits.
	 *
	 * @return AuditSweep
	 */
	private function sweep(): AuditSweep {
		$audited = &$this->audited;
		$auditor = new class( new LoopDetector( $this->repository() ), $audited ) extends RedirectAuditor {

			/**
			 * Audited IDs, shared with the test.
			 *
			 * @var int[]
			 */
			private array $log;

			/**
			 * Constructor.
			 *
			 * @param LoopDetector $loop_detector The loop detector.
			 * @param int[]        $log           Audited IDs, by reference.
			 */
			public function __construct( LoopDetector $loop_detector, array &$log ) {
				parent::__construct( $loop_detector );
				$this->log = &$log;
			}

			/**
			 * Record the row, then audit it.
			 *
			 * @param Redirect $redirect          The redirect.
			 * @param bool     $check_destination Whether to request the destination.
			 * @return \Automattic\LegacyRedirector\Domain\AuditFinding[] The findings.
			 */
			public function audit( Redirect $redirect, bool $check_destination = false ): array {
				$this->log[] = (int) $redirect->id();

				return parent::audit( $redirect, $check_destination );
			}
		};

		return new AuditSweep( $this->query_repository(), $auditor, new AuditFlags() );
	}

	/**
	 * Create redirects to a post that does not exist, so each is a problem.
	 *
	 * @param int    $count  How many to create.
	 * @param string $prefix Source path prefix.
	 * @return int[] Their IDs, ascending.
	 */
	private function create_broken_redirects( int $count, string $prefix ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->insert_redirect_post(
				array(
					'post_title'   => "/{$prefix}-{$i}",
					'post_excerpt' => '',
					'post_parent'  => 987654321,
				)
			);
		}

		return $ids;
	}

	/**
	 * Run batches under the lock until the pass completes.
	 *
	 * @param AuditSweep $sweep   The sweep.
	 * @param bool       $restart Whether to start a fresh pass first.
	 * @return int How many batches ran.
	 */
	private function run_pass( AuditSweep $sweep, bool $restart = true ): int {
		$this->assertTrue( $sweep->lock( 0 ) );

		try {
			if ( $restart ) {
				$sweep->start_pass();
			}

			$batches = 0;
			do {
				$batch = $sweep->run_batch();
				++$batches;
			} while ( ! $batch['done'] );
		} finally {
			$sweep->unlock();
		}

		return $batches;
	}

	/**
	 * Test a pass audits every enabled and disabled row exactly once, in ID order.
	 */
	public function test_a_pass_audits_every_row_once_in_id_order(): void {
		$ids      = $this->create_broken_redirects( AuditSweep::BATCH_SIZE * 2 + 5, 'sweep-all' );
		$disabled = $this->insert_redirect_post(
			array(
				'post_title'  => '/sweep-disabled',
				'post_status' => 'draft',
			)
		);
		$trashed  = $this->insert_redirect_post(
			array(
				'post_title'  => '/sweep-trashed',
				'post_status' => 'trash',
			)
		);
		$ids[]    = $disabled;

		$batches = $this->run_pass( $this->sweep() );

		sort( $ids );
		$this->assertSame( 3, $batches );
		$this->assertSame( $ids, $this->audited );
		$this->assertNotContains( $trashed, $this->audited );
		$this->assertSame( 'problem', get_post_meta( $ids[0], AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test rows deleted mid-pass cannot make the walk skip a row.
	 *
	 * An offset walk skips one row for each row deleted behind it; a keyset
	 * walk carries on from the last ID it saw.
	 */
	public function test_rows_deleted_mid_pass_do_not_cause_a_skip(): void {
		$ids   = $this->create_broken_redirects( AuditSweep::BATCH_SIZE + 10, 'sweep-delete' );
		$sweep = $this->sweep();

		$this->assertTrue( $sweep->lock( 0 ) );
		$sweep->start_pass();
		$sweep->run_batch();
		$sweep->unlock();

		// One row the walk has passed, and one it has yet to reach.
		wp_delete_post( $ids[0], true );
		wp_delete_post( $ids[ AuditSweep::BATCH_SIZE + 1 ], true );

		$this->run_pass( $sweep, false );

		$expected = array_values( array_diff( $ids, array( $ids[ AuditSweep::BATCH_SIZE + 1 ] ) ) );
		$this->assertSame( $expected, $this->audited );
	}

	/**
	 * Test the final short batch completes the pass: run time, badge counts, cursor reset.
	 */
	public function test_a_completed_pass_records_its_time_and_badge_counts(): void {
		$this->create_broken_redirects( 3, 'sweep-complete' );
		$sweep = $this->sweep();

		$this->run_pass( $sweep );

		$status = $sweep->status();
		$this->assertFalse( $status['in_progress'] );
		$this->assertNotNull( $status['checked_at'] );
		$this->assertSame( 3, ( new AuditFlags() )->problem_count() );

		// The next pass starts from the lowest ID again.
		$this->audited = array();
		$this->run_pass( $sweep );
		$this->assertCount( 3, $this->audited );
	}

	/**
	 * Test a tick stops once its budget is spent, leaving the pass to continue.
	 */
	public function test_a_tick_stops_when_its_budget_is_spent(): void {
		$this->create_broken_redirects( AuditSweep::BATCH_SIZE + 10, 'sweep-budget' );
		add_filter( AuditSweep::BUDGET_FILTER, static fn() => 0.000001 );

		$sweep = $this->sweep();
		$sweep->tick();

		$status = $sweep->status();
		$this->assertTrue( $status['in_progress'] );
		$this->assertSame( AuditSweep::BATCH_SIZE, $status['checked'] );

		// The next tick carries on from the cursor.
		$sweep->tick();
		$this->assertCount( AuditSweep::BATCH_SIZE + 10, $this->audited );
		$this->assertFalse( $sweep->status()['in_progress'] );
	}

	/**
	 * Test the total counts enabled and disabled redirects, not the Trash,
	 * and a batch's flag writes leave it cached.
	 */
	public function test_total_counts_enabled_and_disabled_and_survives_flag_writes(): void {
		global $wpdb;

		$enabled = $this->insert_redirect_post();
		$this->insert_redirect_post( array( 'post_status' => 'draft' ) );
		$this->insert_redirect_post( array( 'post_status' => 'trash' ) );

		$sweep = $this->sweep();
		$this->assertSame( 2, $sweep->total() );

		// A batch's flag write, which moves the posts last-changed time on.
		update_post_meta( $enabled, AuditFlags::META_KEY, 'problem' );

		$queries = $wpdb->num_queries;
		$this->assertSame( 2, $sweep->total() );
		$this->assertSame( $queries, $wpdb->num_queries, 'The total must come from the cache.' );
	}

	/**
	 * Test a budget of zero turns the background sweep off.
	 */
	public function test_a_zero_budget_turns_the_sweep_off(): void {
		$this->create_broken_redirects( 2, 'sweep-off' );
		add_filter( AuditSweep::BUDGET_FILTER, '__return_zero' );

		$sweep = $this->sweep();
		$sweep->tick();

		$this->assertSame( array(), $this->audited );
		$this->assertNull( $sweep->status()['estimate'] );
	}

	/**
	 * Test the next pass waits until a day after the previous one began.
	 */
	public function test_a_new_pass_waits_a_day_after_the_last_began(): void {
		$this->create_broken_redirects( 2, 'sweep-gate' );
		$sweep = $this->sweep();

		$sweep->tick();
		$this->assertCount( 2, $this->audited );

		$sweep->tick();
		$this->assertCount( 2, $this->audited, 'A pass that began under a day ago is not followed by another.' );

		$state               = get_option( AuditSweep::STATE_OPTION );
		$state['started_at'] = time() - DAY_IN_SECONDS - 1;
		update_option( AuditSweep::STATE_OPTION, $state, false );

		$sweep->tick();
		$this->assertCount( 4, $this->audited );
	}

	/**
	 * Test a tick does nothing while another runner holds the lock.
	 */
	public function test_a_tick_skips_while_another_runner_holds_the_lock(): void {
		$this->create_broken_redirects( 2, 'sweep-locked' );

		$holder = $this->sweep();
		$this->assertTrue( $holder->lock( 0 ) );

		$this->sweep()->tick();
		$this->assertSame( array(), $this->audited );
		$this->assertFalse( $this->sweep()->lock( 0 ) );

		$holder->unlock();
		$this->assertTrue( $this->sweep()->lock( 0 ) );
	}

	/**
	 * Test an abandoned lock is taken over.
	 */
	public function test_an_abandoned_lock_is_taken_over(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture: a lock row left by a runner that died.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, UNIX_TIMESTAMP() - 3600, 'off')", AuditSweep::LOCK_OPTION ) );

		$this->assertTrue( $this->sweep()->lock( 0 ) );
	}

	/**
	 * Test a tick measures its rate, from which the status estimates a full pass.
	 */
	public function test_status_estimates_a_full_pass_from_the_measured_rate(): void {
		$this->create_broken_redirects( 5, 'sweep-rate' );
		$sweep = $this->sweep();

		$this->assertNull( $sweep->status()['estimate'] );

		$sweep->tick();

		$status = $sweep->status();
		$this->assertSame( 5, $status['total'] );
		$this->assertIsInt( $status['estimate'] );
		$this->assertGreaterThanOrEqual( 5 * MINUTE_IN_SECONDS, $status['estimate'] );
	}

	/**
	 * Test the tick is scheduled on the five-minute interval, moving an older schedule onto it.
	 */
	public function test_maybe_schedule_moves_the_tick_onto_its_interval(): void {
		$sweep = $this->sweep();
		$sweep->register();

		AuditSweep::unschedule();
		wp_schedule_event( time(), 'daily', AuditSweep::HOOK );

		$sweep->maybe_schedule();
		$this->assertSame( AuditSweep::INTERVAL, wp_get_schedule( AuditSweep::HOOK ) );

		$first = wp_next_scheduled( AuditSweep::HOOK );
		$sweep->maybe_schedule();
		$this->assertSame( $first, wp_next_scheduled( AuditSweep::HOOK ) );

		AuditSweep::unschedule();
		$this->assertFalse( wp_next_scheduled( AuditSweep::HOOK ) );
	}

	/**
	 * Prime the menu global with the Redirects entry.
	 *
	 * @return int The menu index of the entry.
	 */
	private function prime_menu(): int {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored in tear_down; the badge writes to the real menu global.
		$GLOBALS['menu'] = array(
			5 => array( 'Redirects', 'manage_redirects', 'edit.php?post_type=' . PostType::POST_TYPE ),
		);

		return 5;
	}

	/**
	 * Test the badge shows the flagged problem count, and drops when a flagged row is saved.
	 */
	public function test_menu_badge_counts_flagged_problems_and_drops_on_save(): void {
		$ids = $this->create_broken_redirects( 3, 'sweep-badge' );
		$this->run_pass( $this->sweep() );

		$index = $this->prime_menu();
		( new MenuBadge( new AuditFlags() ) )->add_badge();

		$this->assertStringContainsString( 'awaiting-mod count-3', $GLOBALS['menu'][ $index ][0] );
		$this->assertStringContainsString( '3 redirects with problems', $GLOBALS['menu'][ $index ][0] );

		wp_update_post(
			array(
				'ID'          => $ids[0],
				'post_status' => 'draft',
			)
		);

		// The save's recount runs at the end of the request; run it here.
		( new AuditFlags() )->store_counts();

		$this->assertSame( 2, ( new AuditFlags() )->problem_count() );
	}

	/**
	 * Test the badge stays away before any pass, and when no row is a problem.
	 */
	public function test_menu_badge_absent_without_problems(): void {
		$index = $this->prime_menu();
		( new MenuBadge( new AuditFlags() ) )->add_badge();
		$this->assertSame( 'Redirects', $GLOBALS['menu'][ $index ][0] );

		$this->create_redirect( '/sweep-clean', 'https://example.com/fine' );
		$this->run_pass( $this->sweep() );

		( new MenuBadge( new AuditFlags() ) )->add_badge();
		$this->assertSame( 'Redirects', $GLOBALS['menu'][ $index ][0] );
	}
}
