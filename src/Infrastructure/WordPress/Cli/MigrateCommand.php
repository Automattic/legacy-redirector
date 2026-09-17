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
	 * worry about, and the round trips dominate on big redirect sets.
	 */
	private const int BATCH_SIZE = 500;

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
	 * This runs automatically in small batches on ordinary page loads. Running
	 * it here completes the whole job in one pass, which is the better option
	 * for sites with large redirect sets.
	 *
	 * It is safe to run more than once: redirects you have disabled since
	 * upgrading are left alone.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Migrate 1.x redirect data.
	 *     $ wp wpcom-legacy-redirector migrate
	 *
	 *     # Migrate every site on a network.
	 *     $ wp site list --field=url | xargs -I % wp --url=% wpcom-legacy-redirector migrate
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
					$pending['to_normalise']
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
		$normalised = 0;
		$processed  = 0;
		$conflicts  = array();

		do {
			$batch = $this->upgrader->run_batch( self::BATCH_SIZE );

			$processed  += $batch['processed'];
			$published  += $batch['published'];
			$repathed   += $batch['repathed'];
			$deduped    += $batch['deduped'];
			$normalised += $batch['normalised'];
			$conflicts   = array_merge( $conflicts, $batch['conflicts'] );

			if ( $batch['processed'] > 0 ) {
				WP_CLI::line( sprintf( 'Processed %d redirect(s)...', $processed ) );
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
				$normalised
			)
		);
	}
}
