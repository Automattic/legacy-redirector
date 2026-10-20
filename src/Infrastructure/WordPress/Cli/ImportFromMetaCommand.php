<?php
/**
 * Import from meta CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\Url;
use WP_CLI;
use WP_CLI_Command;

/**
 * Bulk import redirects from post meta.
 */
final class ImportFromMetaCommand extends WP_CLI_Command {

	use CountsInWords;

	/**
	 * How many meta rows are read per query.
	 *
	 * @var int
	 */
	private const int PAGE_SIZE = 1000;

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager             $manager    The redirect manager.
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 */
	public function __construct( RedirectManager $manager, RedirectRepositoryInterface $repository ) {
		$this->manager    = $manager;
		$this->repository = $repository;
	}

	/**
	 * Bulk import redirects from URLs stored as meta values for posts.
	 *
	 * ## OPTIONS
	 *
	 * --meta-key=<name-of-meta-key>
	 * : Name of the meta key to import from. The meta value contains the From value. The To is the post ID that the meta key is for.
	 *
	 * [--start=<start-offset>]
	 * : Starting offset.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--end=<end-offset>]
	 * : Ending offset.
	 * ---
	 * default: 99999999
	 * ---
	 *
	 * [--skip-dupes]
	 * : Skip source URLs that already have a redirect.
	 *
	 * [--dry-run]
	 * : Preview the import without making changes. Each row is checked as a
	 * live run would check it, so a row the import would refuse is reported.
	 *
	 * [--verbose]
	 * : Report successful imports and skipped duplicates, not just problems.
	 *
	 * [--format=<format>]
	 * : Render per-row notices in a particular format. Formats other than
	 * table print only the notices, with no run heading or closing line.
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
	 *     # Bulk import from a my-redirect meta key.
	 *     $ wp legacy-redirector import-from-meta --meta-key=my-redirect
	 *     ---Live Run---
	 *     Importing 143 redirects
	 *     All of your redirects have been imported. Nice work!
	 *
	 *     # Preview the first thousand rows.
	 *     $ wp legacy-redirector import-from-meta --meta-key=my-redirect --start=0 --end=1000 --dry-run
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

		global $wpdb;

		$offset     = (int) ( $assoc_args['start'] ?? 0 );
		$end_offset = (int) ( $assoc_args['end'] ?? 99999999 );
		$meta_key   = sanitize_key( $assoc_args['meta-key'] ?? '' );
		$skip_dupes = isset( $assoc_args['skip-dupes'] );
		$format     = $assoc_args['format'] ?? 'table';
		$dry_run    = isset( $assoc_args['dry-run'] );
		$verbose    = isset( $assoc_args['verbose'] );
		$is_table   = 'table' === $format;
		$notices    = array();
		$processed  = 0;
		$failed     = 0;

		if ( $is_table ) {
			WP_CLI::line( $dry_run ? '---Dry Run---' : '---Live Run---' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI command for bulk operation.
		$total_redirects = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( post_id ) FROM $wpdb->postmeta WHERE meta_key = %s",
				$meta_key
			)
		);

		if ( 0 === absint( $total_redirects ) ) {
			WP_CLI::error( sprintf( 'No redirects found for meta_key: %s', $meta_key ) );
			return;
		}

		// The bar counts the slice --start and --end select, not every row
		// under the key, so a slice of a large set still reaches the end.
		$end_offset = min( $end_offset, (int) $total_redirects );
		$slice      = max( 0, $end_offset - $offset );
		$progress   = $is_table
			? \WP_CLI\Utils\make_progress_bar( ( $dry_run ? 'Checking ' : 'Importing ' ) . self::count_of( $slice, 'redirect', 'redirects' ), $slice )
			: null;

		while ( $offset < $end_offset ) {
			// Never past --end, even part-way through a page.
			$page_size = min( self::PAGE_SIZE, $end_offset - $offset );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI command for bulk operation.
			$redirects = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d, %d",
					$meta_key,
					$offset,
					$page_size
				)
			);

			foreach ( $redirects as $redirect ) {
				// Before the row, not after it, so the rows that skip or fail
				// below count towards the pause too. The object cache otherwise
				// holds every post and lookup the run touched. Only a live run
				// writes, so only a live run pauses.
				if ( $processed > 0 && 0 === $processed % 100 ) {
					\WP_CLI\Utils\wp_clear_object_cache();
					if ( ! $dry_run ) {
						sleep( 1 );
					}
				}

				++$processed;
				$progress?->tick();

				// A 1.x source out of postmeta can hold raw multibyte bytes, which
				// a bare parse_url() corrupts on some hosts. The path stays
				// percent-encoded; SourceUrl below does the only decode. Sources
				// are keyed on path and query, so the query must come along, and
				// a bare '?p=123' is a query on the root, as it was in 1.x.
				$parts     = Url::parse_encoded( (string) $redirect->meta_value ) ?? array();
				$from_path = $parts['path'] ?? '';
				if ( '' !== ( $parts['query'] ?? '' ) ) {
					$from_path = ( '' === $from_path ? '/' : $from_path ) . '?' . $parts['query'];
				}
				if ( '' === $from_path ) {
					++$failed;
					$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, 'Invalid source URL - no path found' );
					continue;
				}

				try {
					$source = SourceUrl::from_string( $from_path, HomePath::current() );
				} catch ( \InvalidArgumentException $e ) {
					++$failed;
					$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $e->getMessage() );
					continue;
				}

				$existing_redirect = $this->repository->get_id_by_source( $source );
				if ( $skip_dupes && 0 !== $existing_redirect ) {
					if ( $verbose ) {
						$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, sprintf( 'Skipped - Redirect for this from URL already exists (%s)', $redirect->meta_value ) );
					}
					continue;
				}

				try {
					$destination = Destination::from_post_id( DestinationPostId::from_int( (int) $redirect->post_id ) );

					// A dry run asks the questions the live write would, so the
					// preview does not promise rows the import will refuse.
					$error = $dry_run
						? $this->manager->check( $source, $destination, false )->error_message()
						: $this->manager->create_redirect( $source, $destination )->error_message();

					if ( null !== $error ) {
						++$failed;
						$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $error );
					} elseif ( $verbose ) {
						$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $dry_run ? 'Would be imported' : 'Successfully imported' );
					}
				} catch ( \InvalidArgumentException $e ) {
					++$failed;
					$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $e->getMessage() );
				}
			}

			if ( count( $redirects ) < $page_size ) {
				break;
			}

			$offset += $page_size;
		}

		$progress?->finish();

		// Only a table gets prose; the other formats print the notices alone,
		// so an empty JSON result is `[]`.
		if ( ! $is_table || count( $notices ) > 0 ) {
			\WP_CLI\Utils\format_items( $format, $notices, array( 'redirect_from', 'redirect_to', 'message' ) );
		}

		// Any failed row fails a live run, so scripts do not mistake a
		// partial import for a clean one.
		if ( ! $dry_run && $failed > 0 ) {
			WP_CLI::error( sprintf( 'Processed %d of %s: %d failed.', $processed - $failed, self::count_of( $processed, 'row', 'rows' ), $failed ) );
			return;
		}

		if ( ! $is_table ) {
			return;
		}

		if ( $dry_run ) {
			if ( $failed > 0 ) {
				WP_CLI::warning( sprintf( 'Dry run: %s would fail. Nothing was changed.', self::count_of( $failed, 'row', 'rows' ) ) );
			} else {
				WP_CLI::success( 'Dry run: no problems found. Nothing was changed.' );
			}
			return;
		}

		if ( 0 === count( $notices ) ) {
			WP_CLI::log( WP_CLI::colorize( '%GAll of your redirects have been imported. Nice work!%n ' ) );
		}
	}

	/**
	 * Build a notice row.
	 *
	 * @param string $redirect_from The source URL from meta.
	 * @param int    $redirect_to   The destination post ID.
	 * @param string $message       The notice message.
	 * @return array{redirect_from: string, redirect_to: int, message: string}
	 */
	private function notice( string $redirect_from, int $redirect_to, string $message ): array {
		return array(
			'redirect_from' => $redirect_from,
			'redirect_to'   => $redirect_to,
			'message'       => $message,
		);
	}
}
