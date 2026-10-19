<?php
/**
 * Validate redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use WP_CLI;
use WP_CLI_Command;

/**
 * Check redirects' stored details for problems.
 */
final class ValidateCommand extends WP_CLI_Command {

	use CountsInWords;
	use FormatsRedirectRows;
	use ReportsBatchFailures;

	/**
	 * Seconds to wait for a background tick to finish its batch.
	 *
	 * @var int
	 */
	private const int LOCK_WAIT = 30;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * The audit sweep, which validates and records.
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
	 * @param RedirectBatch $batch The batch resolver.
	 * @param AuditSweep    $sweep The audit sweep.
	 * @param AuditFlags    $flags The per-row audit flags.
	 */
	public function __construct( RedirectBatch $batch, AuditSweep $sweep, AuditFlags $flags ) {
		$this->batch = $batch;
		$this->sweep = $sweep;
		$this->flags = $flags;
	}

	/**
	 * Check redirects' stored details for problems, and record what is found.
	 *
	 * Checks what the stored details can show, without requesting any URL:
	 * - Destination posts deleted, trashed or not published
	 * - Destination hosts missing from the allowed_redirect_hosts filter
	 * - Rows whose stored data cannot be read
	 * - Sources on a path WordPress itself serves, such as /wp-admin (a warning)
	 * - Possible loops through other redirects (a warning)
	 *
	 * With no redirects given, checks every redirect, enabled and disabled:
	 * the same check the site runs in the background, run now. With
	 * redirects given, checks just those and says what is wrong with each,
	 * and what to do about it. Either way, each redirect's flag is updated,
	 * so the admin "Has issues" view, the menu badge and `list --has-issues`
	 * reflect the result. To check whether a redirect works live, use `test`.
	 *
	 * Nothing is changed beyond the flags: almost no problem has a fix the
	 * plugin can safely apply, so each finding says what to do instead.
	 *
	 * ## OPTIONS
	 *
	 * [<redirect>...]
	 * : Redirect IDs or source paths to check. Omit to check every redirect.
	 *
	 * [--format=<format>]
	 * : Render the findings for the redirects given. With no redirects,
	 * `count` prints how many redirects have problems or warnings.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Check every redirect now, rather than waiting for the background check.
	 *     $ wp legacy-redirector validate
	 *
	 *     # Say what is wrong with one redirect, and what to do about it.
	 *     $ wp legacy-redirector validate /old-page
	 *
	 *     # Re-check every redirect the background check flagged as a problem.
	 *     $ wp legacy-redirector list --has-issues=problem --format=ids | xargs wp legacy-redirector validate
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		if ( array() === $args ) {
			$this->validate_all( $format );
			return;
		}

		$items = $this->batch->resolve( $args );
		$this->report_batch_failures( $items, 'validate' );

		$redirects = RedirectBatch::redirects( $items );

		if ( array() === $redirects ) {
			WP_CLI::error( 'No matching redirects found.' );
			return;
		}

		$findings = $this->sweep->validate_redirects( $redirects );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $findings ) );
			return;
		}

		if ( array() === $findings ) {
			WP_CLI::success( 'No issues found.' );
			return;
		}

		if ( 'table' === $format ) {
			WP_CLI::warning( 'Found ' . self::count_of( count( $findings ), 'issue', 'issues' ) . '.' );
		}

		\WP_CLI\Utils\format_items(
			$format,
			array_map( fn( AuditFinding $finding ) => $this->finding_row( $finding ), $findings ),
			array( 'ID', 'from', 'to', 'severity', 'issue', 'detail', 'remedy' )
		);
	}

	/**
	 * Run a full pass over every redirect, as the background check does.
	 *
	 * Locked a batch at a time, as the background takes it, so a long run
	 * never holds the lock past the age at which it counts as abandoned.
	 *
	 * @param string $format The output format.
	 * @return void
	 */
	private function validate_all( string $format ): void {
		$total    = $this->sweep->total();
		$progress = 'table' === $format ? \WP_CLI\Utils\make_progress_bar( 'Validating redirects', $total ) : null;
		$restart  = true;

		do {
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

			// A pass over millions of rows would otherwise fill the in-memory
			// object cache with every destination post and lookup it touched.
			\WP_CLI\Utils\wp_clear_object_cache();

			$progress?->tick( $batch['audited'] );
		} while ( ! $batch['done'] );

		$progress?->finish();

		$counts = $this->flags->counts();

		if ( 'count' === $format ) {
			WP_CLI::line( (string) ( $counts['problem'] + $counts['warning'] ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Validated %s: %s, %s.',
				self::count_of( $total, 'redirect', 'redirects' ),
				self::count_of( $counts['problem'], 'with a problem', 'with problems' ),
				self::count_of( $counts['warning'], 'with a warning', 'with warnings' )
			)
		);

		if ( $counts['problem'] + $counts['warning'] > 0 ) {
			WP_CLI::line( 'List them with `wp legacy-redirector list --has-issues`, and see what to do about one with `wp legacy-redirector validate <redirect>`.' );
		}
	}
}
