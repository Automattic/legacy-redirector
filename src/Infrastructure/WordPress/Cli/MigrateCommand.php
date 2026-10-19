<?php
/**
 * Data migration CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;
use RuntimeException;
use WP_CLI;
use WP_CLI_Command;

/**
 * Migrate redirect data created by version 1.x.
 */
final class MigrateCommand extends WP_CLI_Command {

	use CountsInWords;

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
	 * Duplicate sources listed in full before the rest are summarized.
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
	 * Where two redirects end up with the same source but different
	 * destinations, only one can answer: the other is disabled and reported.
	 * The report says how to settle each one, and `wp legacy-redirector list
	 * --duplicates` lists every one, at any time.
	 *
	 * If some redirects could not be written, running it again retries just
	 * those, without walking the rest of the set again - even once the
	 * migration has otherwise completed.
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
		try {
			$this->run( isset( $assoc_args['dry-run'] ) );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( sprintf( 'Stopped because %s. It is safe to run the same command again: a migration carries on from the batch it was working on.', $e->getMessage() ) );
		}
	}

	/**
	 * Migrate, retry earlier failures, or report what would happen.
	 *
	 * @param bool $dry_run Whether to report without writing.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses a read or bulk write.
	 */
	private function run( bool $dry_run ): void {
		if ( ! $this->upgrader->needs_upgrade() ) {
			$waiting = $this->upgrader->pending_retries();

			if ( 0 === $waiting ) {
				WP_CLI::success( 'Redirect data is already up to date; nothing to migrate.' );
			} elseif ( $dry_run ) {
				WP_CLI::line( self::count_of( $waiting, 'redirect', 'redirects' ) . ' could not be written in an earlier run. Running `wp legacy-redirector migrate` without --dry-run retries just ' . ( 1 === $waiting ? 'that one.' : 'those.' ) );
			} else {
				WP_CLI::line( 'Retrying the ' . self::count_of( $waiting, 'redirect', 'redirects' ) . ' that could not be written in an earlier run. The rest are already migrated and are not walked again.' );
				$this->report_outcome( 'Retry complete.', $this->upgrader->retry_failed( 60 ) );
			}

			return;
		}

		$position = $this->upgrader->position();

		if ( $dry_run ) {
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

		$report  = $this->progress_reporter( 'Checked', 0, $total );
		$pending = $this->upgrader->count_pending(
			static function ( int $checked ) use ( $report ): void {
				// The same walk as a run, in one process, so the same housekeeping.
				self::release_memory();
				$report( $checked );
			},
			self::BATCH_SIZE
		);

		WP_CLI::line(
			sprintf(
				'%s inspected: %s, %s, and %s.',
				self::count_of( $pending['total'], 'redirect would be', 'redirects would be' ),
				self::count_of( $pending['changed'], 'would change', 'would change' ),
				self::count_of( $pending['unchanged'], 'needs no change', 'need no change' ),
				self::count_of( $pending['skipped'], 'would be left alone because it was edited after the upgrade began', 'would be left alone because they were edited after the upgrade began' )
			)
		);
		WP_CLI::line(
			sprintf(
				'Of those changing, %s, %s, %s, and %s.',
				self::count_of( $pending['published'], 'would be published', 'would be published' ),
				self::count_of( $pending['repathed'], 'would have its source path rewritten', 'would have their source path rewritten' ),
				self::count_of( $pending['deduped'], 'would be trashed as a duplicate', 'would be trashed as duplicates' ),
				self::count_of( $pending['normalized'], 'would have its destination made relative', 'would have their destination made relative' )
			)
		);

		if ( array() !== $pending['conflicts'] ) {
			WP_CLI::warning( sprintf( '%s the same source as another redirect with a different destination. Sources that differed only in form, such as /old and /old/, become one source, and only one redirect can answer it: the one already there would stay live, and the other would be disabled.', self::count_of( count( $pending['conflicts'] ), 'redirect would have', 'redirects would have' ) ) );
			$this->list_capped( $pending['conflicts'], 'To list every one, repeat this dry run with --debug=legacy-redirector; like this one, it changes nothing.' );
			if ( $pending['unfired'] > 0 ) {
				WP_CLI::line( 1 === $pending['unfired'] ? '1 of them never fired under 1.x (marked above), so disabling it will change nothing for visitors.' : sprintf( '%s of them never fired under 1.x (marked above), so disabling them will change nothing for visitors.', number_format_i18n( $pending['unfired'] ) ) );
			}
			WP_CLI::line( 'A real run disables them and explains how to settle each one.' );
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
			WP_CLI::line( sprintf( 'Resuming where the last run stopped: %s of %s already done.', number_format_i18n( $done ), self::count_of( $total, 'redirect', 'redirects' ) ) );
		}

		$left = $total - $done;
		WP_CLI::line(
			$left > self::BATCH_SIZE
				? sprintf(
					'Migrating %s in batches of %s, pausing %ss after each batch that writes so database replicas can keep up.',
					self::count_of( $left, 'redirect', 'redirects' ),
					number_format( self::BATCH_SIZE ),
					self::BATCH_PAUSE_US / 1000000
				)
				: 'Migrating ' . self::count_of( $left, 'redirect', 'redirects' ) . '.'
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
			'unfired'    => 0,
			'failed'     => array(),
		);

		do {
			$this->upgrader->hold_web_batches();
			$batch = $this->upgrader->run_batch( self::BATCH_SIZE, 60 );

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

		$this->report_outcome( 'Migration complete.', $totals );
	}

	/**
	 * Report the duplicate sources, failures and totals of a run or a retry.
	 *
	 * @param string               $headline 'Migration complete.' or 'Retry complete.'.
	 * @param array<string, mixed> $totals   The run's totals.
	 * @return void
	 */
	private function report_outcome( string $headline, array $totals ): void {
		if ( array() !== $totals['conflicts'] ) {
			WP_CLI::warning( sprintf( '%s the same source as another redirect with a different destination. Sources that differed only in form, such as /old and /old/, are now one source, and only one redirect can answer it: the one already there stays live, and the other has been disabled.', self::count_of( count( $totals['conflicts'] ), 'redirect now has', 'redirects now have' ) ) );
			$this->list_capped( $totals['conflicts'], '`wp legacy-redirector list --duplicates` lists every one.' );
			$this->explain_duplicates( count( $totals['conflicts'] ), $totals['unfired'] );
		}

		$summary = sprintf(
			'%s %s inspected in this run: %s changed, %s, %s, and %s. Of those changed, %s published, %s rewritten, %s trashed, %s made relative.',
			$headline,
			self::count_of( $totals['processed'], 'redirect', 'redirects' ),
			number_format_i18n( $totals['changed'] ),
			self::count_of( $totals['unchanged'], 'needed no change', 'needed no change' ),
			self::count_of( $totals['skipped'], 'left alone because it was edited after the upgrade began', 'left alone because they were edited after the upgrade began' ),
			self::count_of( count( $totals['failed'] ), 'could not be written', 'could not be written' ),
			number_format_i18n( $totals['published'] ),
			self::count_of( $totals['repathed'], 'source path', 'source paths' ),
			self::count_of( $totals['deduped'], 'duplicate', 'duplicates' ),
			self::count_of( $totals['normalized'], 'destination', 'destinations' )
		);

		$waiting = $this->upgrader->pending_retries();

		if ( array() !== $totals['failed'] ) {
			// Listed in full, unlike duplicate sources: each needs its cause fixed
			// before a retry can succeed.
			WP_CLI::warning( self::count_of( count( $totals['failed'] ), 'redirect could not be written, and is as it was before the migration:', 'redirects could not be written, and are as they were before the migration:' ) );
			foreach ( $totals['failed'] as $failure ) {
				WP_CLI::line( '  ' . $failure );
			}
			WP_CLI::line( $summary );
			WP_CLI::error( self::count_of( $waiting, 'redirect is waiting to be retried. Once the cause is fixed, run `wp legacy-redirector migrate` again: it retries just that one, without walking the rest again.', 'redirects are waiting to be retried. Once the cause is fixed, run `wp legacy-redirector migrate` again: it retries just those, without walking the rest again.' ) );
			return;
		}

		if ( $waiting > 0 ) {
			WP_CLI::warning( self::count_of( $waiting, 'redirect that could not be written in an earlier run is still waiting. Run `wp legacy-redirector migrate` again to retry just that one.', 'redirects that could not be written in an earlier run are still waiting. Run `wp legacy-redirector migrate` again to retry just those.' ) );
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Say how to settle the duplicate sources a run disabled.
	 *
	 * The choices are the same in the admin, so the Duplicate sources view is
	 * named too: a site that migrated on ordinary page loads never sees this.
	 *
	 * @param int $disabled Duplicates disabled.
	 * @param int $unfired  Of those, how many never fired under 1.x.
	 * @return void
	 */
	private function explain_duplicates( int $disabled, int $unfired ): void {
		if ( $unfired > 0 ) {
			WP_CLI::line( 1 === $unfired ? '1 of them never fired under 1.x (marked above), so disabling it changed nothing for visitors. Delete it unless you want its destination.' : sprintf( '%s of them never fired under 1.x (marked above), so disabling them changed nothing for visitors. Delete them unless you want their destination.', number_format_i18n( $unfired ) ) );
		}

		if ( $disabled > $unfired ) {
			WP_CLI::line( 'Visitors who used the spelling of any other disabled redirect now reach the live redirect\'s destination. For each, decide which destination is right:' );
			WP_CLI::line( '  - To keep the live one\'s, delete the disabled redirect: wp legacy-redirector delete <disabled ID>' );
			WP_CLI::line( '  - To keep the disabled one\'s, point the live redirect there, then delete the disabled one: wp legacy-redirector update <live ID> --to=<destination>' );
			WP_CLI::line( 'Enabling or editing a disabled duplicate is refused while the live redirect holds its source.' );
		}

		WP_CLI::line( '`wp legacy-redirector list --duplicates` lists them, now or later, as does the Duplicate sources view on the Redirects screen.' );
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
	 * for its whole runtime, so the loop frees memory (see release_memory()),
	 * and pauses after each batch that wrote so replicas keep pace. Batches
	 * that changed nothing skip the pause: an already-migrated stretch
	 * replicates nothing.
	 *
	 * @param array{changed: int} $batch The batch totals just processed.
	 * @return void
	 */
	private function rest_between_batches( array $batch ): void {
		self::release_memory();

		if ( $batch['changed'] > 0 ) {
			usleep( self::BATCH_PAUSE_US );
		}
	}

	/**
	 * Clear the request-lifetime caches, which grow without bound over a walk of millions of rows.
	 *
	 * @return void
	 */
	private static function release_memory(): void {
		if ( function_exists( 'vip_reset_local_object_cache' ) ) {
			vip_reset_local_object_cache();
		} elseif ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		if ( function_exists( 'vip_reset_db_query_log' ) ) {
			vip_reset_db_query_log();
		}
	}
}
