<?php
/**
 * Data migration CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;
use WP_CLI;
use WP_CLI_Command;

/**
 * Migrate redirect data created by version 1.x.
 */
final class MigrateCommand extends WP_CLI_Command {

	/**
	 * Redirects processed per batch.
	 *
	 * Larger than the web-request batch size: WP-CLI has no request timeout to
	 * worry about, and the round trips dominate on big redirect sets. The cap
	 * is a replication concern, not a timeout one - each batch's bulk publish
	 * is a single UPDATE of at most this many rows, kept small enough to
	 * replicate in well under a second so replicas never fall behind.
	 */
	private const int BATCH_SIZE = 2000;

	/**
	 * Pause between batches, in microseconds.
	 *
	 * No replica-lag reading is available to application code, so instead of
	 * feedback throttling the loop paces itself at a fixed conservative rate,
	 * giving replicas a beat to apply each batch before the next lands.
	 */
	private const int BATCH_PAUSE_US = 250000;

	/**
	 * Conflicts listed in full before the rest are summarized.
	 *
	 * A messy site can have thousands, printed just above the summary line.
	 */
	private const int LIST_LIMIT = 20;

	/**
	 * The upgrade routine.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Constructor.
	 *
	 * @param Upgrader $upgrader The upgrade routine.
	 */
	public function __construct( Upgrader $upgrader ) {
		$this->upgrader = $upgrader;
	}

	/**
	 * Migrate redirect data created by version 1.x to the 2.0 format.
	 *
	 * Version 1.x stored every redirect as a draft, and wherever the site is
	 * not at the domain root it stored source paths with that base path
	 * included - on a subsite, and equally on a single site installed at
	 * example.com/blog. Version 2.0 only serves published redirects, and looks
	 * them up by their site-relative path. Until this has run, redirects
	 * created under 1.x do not fire.
	 *
	 * This runs automatically in small batches on ordinary page loads, but
	 * never on WP-CLI commands. Running it here completes the whole job in one
	 * pass, which is the better option for sites with large redirect sets.
	 *
	 * It is safe to run more than once: redirects you have disabled since
	 * upgrading are left alone.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything. This walks the
	 * entire redirect set, printing its progress as it goes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Migrate 1.x redirect data.
	 *     $ wp legacy-redirector migrate
	 *
	 *     # Migrate every site on a network.
	 *     $ wp site list --field=url | xargs -I % wp --url=% legacy-redirector migrate
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! $this->upgrader->needs_upgrade() ) {
			WP_CLI::success( 'Redirect data is already up to date; nothing to migrate.' );
			return;
		}

		$position = $this->upgrader->position();

		if ( isset( $assoc_args['dry-run'] ) ) {
			$this->dry_run( $position['total'] );
			return;
		}

		$this->migrate( $position['done'], $position['total'] );
	}

	/**
	 * Report what a run would do, without writing anything.
	 *
	 * @param int $total The number of redirects the walk will check.
	 * @return void
	 */
	private function dry_run( int $total ): void {
		WP_CLI::line( 'Dry run - no changes will be made.' );

		$pending = $this->upgrader->count_pending( $this->progress_reporter( 'Checked', 0, $total ) );

		WP_CLI::line(
			sprintf(
				'%s redirect(s) would be inspected: %s would change, %s need no change, and %s would be left alone because they were edited after the upgrade began.',
				number_format( $pending['total'] ),
				number_format( $pending['changed'] ),
				number_format( $pending['unchanged'] ),
				number_format( $pending['skipped'] )
			)
		);
		WP_CLI::line(
			sprintf(
				'Of those changing, %s would be published, %s would have their source path rewritten, %s would be trashed as duplicates, and %s would have their destination made relative.',
				number_format( $pending['published'] ),
				number_format( $pending['repathed'] ),
				number_format( $pending['deduped'] ),
				number_format( $pending['normalized'] )
			)
		);

		if ( array() !== $pending['conflicts'] ) {
			WP_CLI::warning( sprintf( '%s source path(s) would collide with a redirect pointing somewhere else:', number_format( count( $pending['conflicts'] ) ) ) );
			$this->list_capped( $pending['conflicts'], 'Run again with --debug=legacy-redirector to list them all.' );
		}
	}

	/**
	 * Run the migration to the end, reporting progress and the outcome.
	 *
	 * @param int $done  Redirects an earlier, interrupted run already got through.
	 * @param int $total Redirects the whole walk covers.
	 * @return void
	 */
	private function migrate( int $done, int $total ): void {
		if ( $done > 0 ) {
			WP_CLI::line( sprintf( 'Resuming where the last run stopped: %s of %s redirect(s) already done.', number_format( $done ), number_format( $total ) ) );
		}

		WP_CLI::line(
			sprintf(
				'Migrating %s redirect(s) in batches of %s, pausing %ss after each batch that writes so database replicas can keep up.',
				number_format( $total - $done ),
				number_format( self::BATCH_SIZE ),
				self::BATCH_PAUSE_US / 1000000
			)
		);

		$report = $this->progress_reporter( 'Processed', $done, $total );
		$totals = array(
			'processed'  => 0,
			'changed'    => 0,
			'unchanged'  => 0,
			'skipped'    => 0,
			'published'  => 0,
			'repathed'   => 0,
			'deduped'    => 0,
			'normalized' => 0,
			'conflicts'  => array(),
			'failed'     => array(),
		);

		do {
			$this->upgrader->hold_web_batches();
			$batch = $this->upgrader->run_batch( self::BATCH_SIZE );

			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = is_array( $value ) ? array_merge( $value, $batch[ $key ] ) : $value + $batch[ $key ];
			}

			if ( ! $batch['complete'] ) {
				$this->rest_between_batches( $batch );
			}

			// Reported after the pause, so the time-left estimate includes pauses.
			$done += $batch['processed'];
			$report( $done );
		} while ( ! $batch['complete'] );

		if ( array() !== $totals['conflicts'] ) {
			WP_CLI::warning( sprintf( '%s redirect(s) collided with a redirect pointing somewhere else:', number_format( count( $totals['conflicts'] ) ) ) );
			$this->list_capped( $totals['conflicts'], 'All are disabled, so `wp legacy-redirector list --status=disabled` lists them, alongside any redirect disabled on purpose.' );
			WP_CLI::line( 'Each has been drafted rather than deleted, so no redirect fires from a path two rows disagree about. Review them, then delete or re-point and republish.' );
		}

		$summary = sprintf(
			'Migration complete. %s redirect(s) inspected in this run: %s changed, %s needed no change, %s left alone because they were edited after the upgrade began, and %s could not be written. Of those changed, %s published, %s source path(s) rewritten, %s duplicate(s) trashed, %s destination(s) made relative.',
			number_format( $totals['processed'] ),
			number_format( $totals['changed'] ),
			number_format( $totals['unchanged'] ),
			number_format( $totals['skipped'] ),
			number_format( count( $totals['failed'] ) ),
			number_format( $totals['published'] ),
			number_format( $totals['repathed'] ),
			number_format( $totals['deduped'] ),
			number_format( $totals['normalized'] )
		);

		if ( array() === $totals['failed'] ) {
			WP_CLI::success( $summary );
			return;
		}

		// Listed in full, unlike conflicts: each is a redirect someone has to
		// fix by hand, and the migration will not come back to it.
		WP_CLI::warning( sprintf( '%s redirect(s) could not be written, and are as they were before the migration:', number_format( count( $totals['failed'] ) ) ) );
		foreach ( $totals['failed'] as $failure ) {
			WP_CLI::line( '  ' . $failure );
		}
		WP_CLI::line( $summary );
		WP_CLI::error( 'The migration finished, but not every redirect could be migrated; see the list above.' );
	}

	/**
	 * A callback that prints a progress line each time another whole percent is done.
	 *
	 * Plain lines rather than WP-CLI's progress bar, which prints nothing at
	 * all when output is piped - as it is for anyone keeping a log of a long
	 * run with tee.
	 *
	 * @param string $verb  What is being counted: 'Checked' or 'Processed'.
	 * @param int    $from  Redirects already done when this run began.
	 * @param int    $total Redirects in the whole walk.
	 * @return callable(int): void Takes the number done so far.
	 */
	private function progress_reporter( string $verb, int $from, int $total ): callable {
		$start = microtime( true );
		$shown = -1;

		return static function ( int $done ) use ( $verb, $from, $total, $start, &$shown ): void {
			$percent = $total > 0 ? intdiv( 100 * min( $done, $total ), $total ) : 100;
			if ( $percent === $shown ) {
				return;
			}
			$shown = $percent;

			$elapsed = microtime( true ) - $start;
			$line    = sprintf( '%s %s of %s (%d%%), %s elapsed', $verb, number_format( $done ), number_format( $total ), $percent, self::duration( $elapsed ) );

			// Rows per second is steady across a walk, so the rate so far
			// predicts the rest well.
			$left = $done > $from ? $elapsed / ( $done - $from ) * ( $total - $done ) : 0;
			if ( $left >= 1 ) {
				$line .= ', about ' . self::duration( $left ) . ' left';
			}

			WP_CLI::line( $line );
		};
	}

	/**
	 * A duration short enough for a progress line.
	 *
	 * @param float $seconds The duration.
	 * @return string E.g. '45s', '4m 05s', '1h 02m'.
	 */
	private static function duration( float $seconds ): string {
		$seconds = (int) round( $seconds );

		if ( $seconds < 60 ) {
			return $seconds . 's';
		}

		if ( $seconds < 3600 ) {
			return sprintf( '%dm %02ds', intdiv( $seconds, 60 ), $seconds % 60 );
		}

		return sprintf( '%dh %02dm', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * Print the first few lines of a list, then say how many more there are.
	 *
	 * The rest go to the legacy-redirector debug group, so --debug shows the
	 * whole list without burying the summary for everyone else.
	 *
	 * @param string[] $lines The full list.
	 * @param string   $hint  Where to find the rest.
	 * @return void
	 */
	private function list_capped( array $lines, string $hint ): void {
		foreach ( $lines as $index => $line ) {
			if ( $index < self::LIST_LIMIT ) {
				WP_CLI::line( '  ' . $line );
			} else {
				WP_CLI::debug( $line, 'legacy-redirector' );
			}
		}

		$more = count( $lines ) - self::LIST_LIMIT;
		if ( $more > 0 ) {
			WP_CLI::line( sprintf( '  ...and %s more. %s', number_format( $more ), $hint ) );
		}
	}

	/**
	 * Housekeeping between batches on a long run.
	 *
	 * A multi-million-row walk holds one PHP process and one database primary
	 * for its whole runtime, so the loop clears the request-lifetime caches
	 * that would otherwise grow without bound, and pauses after each batch
	 * that wrote so replicas keep pace. Batches that changed nothing skip the
	 * pause: an already-migrated stretch replicates nothing.
	 *
	 * @param array{changed: int} $batch The batch totals just processed.
	 * @return void
	 */
	private function rest_between_batches( array $batch ): void {
		if ( function_exists( 'vip_reset_local_object_cache' ) ) {
			vip_reset_local_object_cache();
		} elseif ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		if ( function_exists( 'vip_reset_db_query_log' ) ) {
			vip_reset_db_query_log();
		}

		if ( $batch['changed'] > 0 ) {
			usleep( self::BATCH_PAUSE_US );
		}
	}
}
