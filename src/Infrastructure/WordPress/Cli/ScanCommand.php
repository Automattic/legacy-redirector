<?php
/**
 * Scan CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use WP_CLI;
use WP_CLI_Command;

/**
 * Run a full pass of the background check.
 */
final class ScanCommand extends WP_CLI_Command {

	/**
	 * Seconds to wait for a background tick to finish its batch.
	 *
	 * @var int
	 */
	private const int LOCK_WAIT = 30;

	/**
	 * The audit sweep.
	 *
	 * @var AuditSweep
	 */
	private AuditSweep $sweep;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param AuditSweep $sweep The audit sweep.
	 * @param AuditFlags $flags The per-row audit flags.
	 */
	public function __construct( AuditSweep $sweep, AuditFlags $flags ) {
		$this->sweep = $sweep;
		$this->flags = $flags;
	}

	/**
	 * Check every redirect's stored details for problems, flagging each one.
	 *
	 * Runs a full pass of the check the site runs in the background, with no
	 * time budget: the "Has issues" view, the menu badge and the background
	 * check's progress all update from it. No URLs are requested; use
	 * `validate --check-destination` for that.
	 *
	 * On a very large site, a full pass in the background can take days. Run
	 * this off-peak instead, optionally turning the background check off with
	 * the legacy_redirector_background_audit_seconds filter.
	 *
	 * ## EXAMPLES
	 *
	 *     # Check every redirect now.
	 *     $ wp legacy-redirector scan
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$total    = $this->sweep->total();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning redirects', $total );
		$restart  = true;

		do {
			// Locked a batch at a time, as the background takes it, so a long
			// run never holds the lock past the age at which it counts as
			// abandoned.
			if ( ! $this->sweep->lock( self::LOCK_WAIT ) ) {
				WP_CLI::error( 'Another check held the lock throughout. Try again in a minute.' );
				return;
			}

			try {
				if ( $restart ) {
					$this->sweep->start_pass();
					$restart = false;
				}

				$batch = $this->sweep->run_batch();
			} finally {
				$this->sweep->unlock();
			}

			$progress->tick( $batch['audited'] );
		} while ( ! $batch['done'] );

		$progress->finish();

		$counts = $this->flags->counts();
		WP_CLI::success(
			sprintf(
				'Scanned %d redirect(s): %d with problems, %d with warnings.',
				$total,
				$counts['problem'],
				$counts['warning']
			)
		);
	}
}
