<?php
/**
 * Test redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;
use WP_CLI_Command;

/**
 * Check redirects live, by requesting them.
 */
final class TestCommand extends WP_CLI_Command {

	use CountsInWords;
	use FormatsRedirectRows;
	use ReportsBatchFailures;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectBatch   $batch   The batch resolver.
	 * @param RedirectAuditor $auditor The redirect auditor.
	 */
	public function __construct( RedirectBatch $batch, RedirectAuditor $auditor ) {
		$this->batch   = $batch;
		$this->auditor = $auditor;
	}

	/**
	 * Check redirects live: whether the destination responds, and whether visiting the source redirects there.
	 *
	 * Runs every stored-details check `validate` runs, requests the
	 * destination where only a request can judge it (a URL, or a path that
	 * resolves to no post), and requests the source to see what a visitor
	 * gets. A disabled redirect's source is not requested: it is not meant
	 * to fire.
	 *
	 * Nothing is recorded. A timeout or a throttled request says nothing
	 * definite about a redirect, so live results never reach the admin
	 * "Has issues" view or the menu badge; they are reported as inconclusive
	 * instead. Each request takes time, so name the redirects to test.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>...
	 * : Redirect IDs or source paths to test.
	 *
	 * [--only=<check>]
	 * : Run just one of the two checks.
	 * ---
	 * options:
	 *   - destination
	 *   - source
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format. `count` prints how many results
	 * are not OK.
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
	 *     # Check one redirect live.
	 *     $ wp legacy-redirector test /old-page
	 *
	 *     # Check whether every external destination still responds.
	 *     $ wp legacy-redirector list --destination-type=url --limit=100000 --format=ids | xargs wp legacy-redirector test --only=destination
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$only   = $assoc_args['only'] ?? null;

		$items = $this->batch->resolve( $args );
		$this->report_batch_failures( $items, 'test' );

		$redirects = RedirectBatch::redirects( $items );

		if ( array() === $redirects ) {
			WP_CLI::error( 'No matching redirects found.' );
			return;
		}

		$progress = 'table' === $format && count( $redirects ) > 1
			? \WP_CLI\Utils\make_progress_bar( 'Testing redirects', count( $redirects ) )
			: null;

		$rows = array();
		foreach ( $redirects as $redirect ) {
			array_push( $rows, ...$this->rows_for( $redirect, 'source' !== $only, 'destination' !== $only ) );
			$progress?->tick();
		}

		$progress?->finish();

		$not_ok = array_filter( $rows, static fn( array $row ) => 'ok' !== $row['severity'] );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $not_ok ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'from', 'to', 'severity', 'issue', 'detail', 'remedy' ) );

		if ( 'table' === $format ) {
			if ( array() === $not_ok ) {
				WP_CLI::success( 'Tested ' . self::count_of( count( $redirects ), 'redirect', 'redirects' ) . ': no issues found.' );
			} else {
				WP_CLI::warning( sprintf( 'Tested %s: %s attention.', self::count_of( count( $redirects ), 'redirect', 'redirects' ), self::count_of( count( $not_ok ), 'result needs', 'results need' ) ) );
			}
		}
	}

	/**
	 * Test one redirect and describe each outcome as a row.
	 *
	 * A check that passes still gets a row, so every redirect tested says
	 * what was checked, not only what failed.
	 *
	 * @param Redirect $redirect          The redirect to test.
	 * @param bool     $check_destination Whether to run the stored-details checks and request the destination.
	 * @param bool     $check_source      Whether to request the source.
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows_for( Redirect $redirect, bool $check_destination, bool $check_source ): array {
		$result = $this->auditor->test( $redirect, $check_destination, $check_source );
		$rows   = array();

		foreach ( $result['findings'] as $finding ) {
			$rows[] = $this->finding_row( $finding );
		}

		// A corrupt row's finding is the whole story: neither check could run.
		if ( $redirect->is_corrupt() ) {
			return $rows;
		}

		// Warnings do not stop the destination counting as fine.
		$destination_problems = array_filter(
			$result['findings'],
			static fn( $finding ) => $finding !== $result['source_finding'] && ! $finding->is_warning()
		);

		if ( $check_destination && array() === $destination_problems ) {
			$rows[] = $this->ok_row(
				$redirect,
				$this->auditor->destination_needs_http( $redirect ) ? 'Destination responds' : 'Destination is valid'
			);
		}

		if ( $check_source && null === $result['source_finding'] ) {
			$rows[] = $this->ok_row( $redirect, $this->source_note( $result['source'] ) );
		}

		return $rows;
	}

	/**
	 * What the source check found, when it found nothing wrong.
	 *
	 * @param array{status: string, location?: string}|null $probe The probe outcome, or null when the source was not requested.
	 * @return string The note.
	 */
	private function source_note( ?array $probe ): string {
		return null !== $probe
			? 'Source redirects to the destination'
			: 'Source not requested: the redirect is disabled';
	}

	/**
	 * A row for a check that passed.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param string   $issue    What was found.
	 * @return array<string, mixed> The row.
	 */
	private function ok_row( Redirect $redirect, string $issue ): array {
		return $this->redirect_row( $redirect ) + array(
			'severity' => 'ok',
			'issue'    => $issue,
			'detail'   => '',
			'remedy'   => '',
		);
	}
}
