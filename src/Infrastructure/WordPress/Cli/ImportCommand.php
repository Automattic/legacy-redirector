<?php
/**
 * Import redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;
use WP_CLI;
use WP_CLI_Command;

/**
 * Bulk import redirects from a CSV file.
 */
final class ImportCommand extends WP_CLI_Command {

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 */
	public function __construct( RedirectManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Bulk import redirects from a CSV file.
	 *
	 * The CSV must match the following structure, one redirect per row:
	 *   redirect_from_path,(redirect_to_post_id|redirect_to_path|redirect_to_url)[,status]
	 *
	 * The optional status column can be 'enabled' or 'disabled'. If omitted,
	 * new redirects are created enabled.
	 *
	 * A leading header row is skipped when its first column is `from`, so the
	 * output of `wp legacy-redirector list --fields=from,to,status --format=csv`
	 * can be imported unedited.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file. Pass - to read from STDIN.
	 *
	 * [--mode=<mode>]
	 * : What to do with rows whose source already has a redirect.
	 * ---
	 * default: create
	 * options:
	 *   - create
	 *   - upsert
	 * ---
	 *
	 * [--skip-validation]
	 * : Skip validation of from and to values.
	 *
	 * [--dry-run]
	 * : Preview what would happen without making changes.
	 *
	 * [--verbose]
	 * : Report every row, not just errors.
	 *
	 * [--format=<format>]
	 * : Render per-row results in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Import new redirects from a CSV file.
	 *     $ wp legacy-redirector import redirects.csv
	 *
	 *     # Update existing redirects, creating any that are missing.
	 *     $ wp legacy-redirector import redirects.csv --mode=upsert
	 *
	 *     # Preview an import without making changes.
	 *     $ wp legacy-redirector import redirects.csv --dry-run
	 *
	 *     # Import from STDIN.
	 *     $ cat redirects.csv | wp legacy-redirector import -
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		$file     = $args[0];
		$mode     = $assoc_args['mode'] ?? 'create';
		$format   = $assoc_args['format'] ?? 'table';
		$verbose  = isset( $assoc_args['verbose'] );
		$validate = ! isset( $assoc_args['skip-validation'] );
		$dry_run  = isset( $assoc_args['dry-run'] );
		$is_table = 'table' === $format;

		if ( '-' === $file ) {
			$file = 'php://stdin';
		} elseif ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI command, WP_Filesystem not appropriate.
		$handle = fopen( $file, 'r' );

		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Could not open file: %s', $file ) );
			return;
		}

		if ( $dry_run && $is_table ) {
			WP_CLI::warning( 'Dry run mode - no changes will be made.' );
		}

		$row     = 0;
		$results = array();

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV reading pattern.
		while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			++$row;
			$redirect_from = trim( $data[0] ?? '' );
			$redirect_to   = trim( $data[1] ?? '' );
			$status        = $data[2] ?? null; // Optional: 'enabled' or 'disabled'.

			if ( '' === $redirect_from ) {
				continue;
			}

			// Skip our own export header, so `list --format=csv` output can be
			// re-imported unedited. Deliberately narrow: only an exact `from`
			// in the first column of the first row, so a genuinely malformed
			// first row is still reported as an error.
			if ( 1 === $row && 0 === strcasecmp( $redirect_from, 'from' ) ) {
				continue;
			}

			if ( $is_table && 0 === $row % 100 ) {
				WP_CLI::line( "Processing row $row" );
			}

			$results[] = $this->process_row( $redirect_from, $redirect_to, $status, $mode, $validate, $dry_run );

			if ( 0 === $row % 100 ) {
				if ( function_exists( 'stop_the_insanity' ) ) {
					stop_the_insanity();
				}
				sleep( 1 );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI command, WP_Filesystem not appropriate.
		fclose( $handle );

		$this->display_results( $results, $format, $dry_run, $verbose );
	}

	/**
	 * Process a single CSV row.
	 *
	 * @param string      $redirect_from The source path.
	 * @param string      $redirect_to   The destination.
	 * @param string|null $status        Optional status ('enabled' or 'disabled').
	 * @param string      $mode          The operation mode ('create' or 'upsert').
	 * @param bool        $validate      Whether to validate.
	 * @param bool        $dry_run       Whether this is a dry run.
	 * @return array{source: string, dest: string, action: string, message: string} Result row.
	 */
	private function process_row( string $redirect_from, string $redirect_to, ?string $status, string $mode, bool $validate, bool $dry_run ): array {
		try {
			$source = SourceUrl::from_string( $redirect_from, HomePath::current() );
		} catch ( \InvalidArgumentException $e ) {
			return $this->result_row( $redirect_from, $redirect_to, 'error', 'Invalid source: ' . $e->getMessage() );
		}

		if ( '' === $redirect_to ) {
			return $this->result_row( $redirect_from, $redirect_to, 'error', 'Missing destination' );
		}

		try {
			$to_value    = ctype_digit( $redirect_to ) ? (int) $redirect_to : $redirect_to;
			$destination = Destination::from_mixed( $to_value );
		} catch ( \InvalidArgumentException $e ) {
			return $this->result_row( $redirect_from, $redirect_to, 'error', 'Invalid destination: ' . $e->getMessage() );
		}

		$post_status = $this->resolve_status( $status );

		if ( $dry_run ) {
			$action = 'upsert' === $mode ? 'would update/create' : 'would create';
			return $this->accepted_row( $source, $redirect_from, $redirect_to, $action, 'Dry run - no change made' );
		}

		// In upsert mode, try updating an existing redirect first. Only a
		// not-found error falls through to the create path; any other failure
		// (validation, save) is reported against the update.
		if ( 'upsert' === $mode ) {
			$update = $this->manager->update_by_source( $source, $destination, $post_status, $validate );

			if ( $update->is_success() ) {
				return $this->accepted_row( $source, $redirect_from, $redirect_to, 'updated', 'Updated existing redirect' );
			}

			if ( 'not-found' !== $update->error_code() ) {
				return $this->result_row( $redirect_from, $redirect_to, 'error', $update->error_message() ?? 'Could not update redirect' );
			}
		}

		$result = $this->manager->create_redirect( $source, $destination, $validate, $post_status );

		if ( $result->is_error() ) {
			return $this->result_row( $redirect_from, $redirect_to, 'error', $result->error_message() ?? 'Could not create redirect' );
		}

		return $this->accepted_row( $source, $redirect_from, $redirect_to, 'created', 'Successfully imported' );
	}

	/**
	 * Build the result row for an accepted redirect, warning if its source is
	 * a path WordPress itself serves.
	 *
	 * @param SourceUrl $source_url The parsed source.
	 * @param string    $source     The source path.
	 * @param string    $dest       The destination.
	 * @param string    $action     The action taken.
	 * @param string    $message    The result message.
	 * @return array{source: string, dest: string, action: string, message: string}
	 */
	private function accepted_row( SourceUrl $source_url, string $source, string $dest, string $action, string $message ): array {
		if ( $source_url->is_reserved() ) {
			WP_CLI::warning( $source . ': ' . ValidationIssueType::RESERVED_SOURCE->description() . '.' );
		}

		return $this->result_row( $source, $dest, $action, $message );
	}

	/**
	 * Build a result row.
	 *
	 * @param string $source  The source path.
	 * @param string $dest    The destination.
	 * @param string $action  The action taken.
	 * @param string $message The result message.
	 * @return array{source: string, dest: string, action: string, message: string}
	 */
	private function result_row( string $source, string $dest, string $action, string $message ): array {
		return array(
			'source'  => $source,
			'dest'    => $dest,
			'action'  => $action,
			'message' => $message,
		);
	}

	/**
	 * Display the results summary.
	 *
	 * @param array  $results The results array.
	 * @param string $format  The output format.
	 * @param bool   $dry_run Whether this was a dry run.
	 * @param bool   $verbose Whether to report every row.
	 */
	private function display_results( array $results, string $format, bool $dry_run, bool $verbose ): void {
		if ( empty( $results ) ) {
			WP_CLI::warning( 'No rows processed.' );
			return;
		}

		$is_table = 'table' === $format;
		$errors   = array_filter( $results, fn( $r ) => 'error' === $r['action'] );

		// Summarize by action for table output.
		if ( $is_table ) {
			$counts = array_count_values( array_column( $results, 'action' ) );
			WP_CLI::line( '' );
			WP_CLI::line( 'Summary:' );
			foreach ( $counts as $action => $count ) {
				WP_CLI::line( sprintf( '  %s: %d', ucfirst( $action ), $count ) );
			}
			WP_CLI::line( '' );
		}

		// Show every row when asked (or when previewing), otherwise just the errors.
		if ( $verbose || $dry_run ) {
			\WP_CLI\Utils\format_items( $format, $results, array( 'source', 'dest', 'action', 'message' ) );
		} elseif ( ! empty( $errors ) ) {
			if ( $is_table ) {
				WP_CLI::warning( 'Errors:' );
			}
			\WP_CLI\Utils\format_items( $format, $errors, array( 'source', 'dest', 'message' ) );
		}

		if ( count( $errors ) === count( $results ) ) {
			WP_CLI::error( sprintf( 'All %d rows failed.', count( $results ) ) );
			return;
		}

		if ( ! $dry_run ) {
			WP_CLI::success( sprintf( 'Processed %d redirects.', count( $results ) ) );
		}
	}

	/**
	 * Resolve status string to post status.
	 *
	 * @param string|null $status The status from CSV ('enabled', 'disabled', or null).
	 * @return string|null Post status ('publish', 'draft') or null if not specified.
	 */
	private function resolve_status( ?string $status ): ?string {
		if ( null === $status || '' === trim( $status ) ) {
			return null;
		}

		$status = strtolower( trim( $status ) );

		if ( 'enabled' === $status || 'publish' === $status ) {
			return 'publish';
		}

		if ( 'disabled' === $status || 'draft' === $status ) {
			return 'draft';
		}

		// Unknown status - use the default.
		return null;
	}
}
