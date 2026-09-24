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
	 * entire redirect set, so on a site with millions of redirects it takes
	 * minutes - that is the walk, not a hang.
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
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! $this->upgrader->needs_upgrade() ) {
			WP_CLI::success( 'Redirect data is already up to date; nothing to migrate.' );
			return;
		}

		if ( $dry_run ) {
			$pending = $this->upgrader->count_pending();
			WP_CLI::line( sprintf( 'Dry run - no changes will be made.' ) );
			WP_CLI::line(
				sprintf(
					'%d redirect(s) would be inspected, of which %d would be published, %d would have their source path rewritten, %d would be trashed as duplicates, and %d would have their destination made relative.',
					$pending['total'],
					$pending['to_publish'],
					$pending['to_repath'],
					$pending['to_dedupe'],
					$pending['to_normalize']
				)
			);

			if ( array() !== $pending['conflicts'] ) {
				WP_CLI::warning( sprintf( '%d source path(s) would collide with a redirect pointing somewhere else:', count( $pending['conflicts'] ) ) );
				foreach ( $pending['conflicts'] as $conflict ) {
					WP_CLI::line( '  ' . $conflict );
				}
			}

			return;
		}

		$published  = 0;
		$repathed   = 0;
		$deduped    = 0;
		$normalized = 0;
		$processed  = 0;
		$conflicts  = array();

		do {
			$this->upgrader->hold_web_batches();
			$batch = $this->upgrader->run_batch( self::BATCH_SIZE );

			$processed  += $batch['processed'];
			$published  += $batch['published'];
			$repathed   += $batch['repathed'];
			$deduped    += $batch['deduped'];
			$normalized += $batch['normalized'];
			$conflicts   = array_merge( $conflicts, $batch['conflicts'] );

			if ( $batch['processed'] > 0 ) {
				WP_CLI::line( sprintf( 'Processed %d redirect(s)...', $processed ) );
			}

			if ( ! $batch['complete'] ) {
				$this->rest_between_batches( $batch );
			}
		} while ( ! $batch['complete'] );

		if ( array() !== $conflicts ) {
			WP_CLI::warning( sprintf( '%d redirect(s) collided with a redirect pointing somewhere else:', count( $conflicts ) ) );
			foreach ( $conflicts as $conflict ) {
				WP_CLI::line( '  ' . $conflict );
			}
			WP_CLI::line( 'Each has been drafted rather than deleted, so no redirect fires from a path two rows disagree about. Review them, then delete or re-point and republish.' );
		}

		WP_CLI::success(
			sprintf(
				'Migration complete. %d redirect(s) inspected, %d published, %d source path(s) rewritten, %d duplicate(s) trashed, %d destination(s) made relative.',
				$processed,
				$published,
				$repathed,
				$deduped,
				$normalized
			)
		);
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
	 * @param array{published: int, repathed: int, deduped: int, normalized: int} $batch The batch totals just processed.
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

		$wrote = $batch['published'] + $batch['repathed'] + $batch['deduped'] + $batch['normalized'] > 0;
		if ( $wrote ) {
			usleep( self::BATCH_PAUSE_US );
		}
	}
}
