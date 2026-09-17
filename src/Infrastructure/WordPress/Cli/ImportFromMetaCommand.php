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
	 * : Preview the import without making changes.
	 *
	 * [--verbose]
	 * : Report successful imports and skipped duplicates, not just problems.
	 *
	 * [--format=<format>]
	 * : Render per-row notices in a particular format.
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
		$notices    = array();

		if ( $dry_run ) {
			WP_CLI::line( '---Dry Run---' );
		} else {
			WP_CLI::line( '---Live Run---' );
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

		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( 'Importing %s redirects', number_format( (int) $total_redirects ) ),
			(int) $total_redirects
		);

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI command for bulk operation.
			$redirects = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d, 1000",
					$meta_key,
					$offset
				)
			);

			$i     = 0;
			$total = count( $redirects );

			foreach ( $redirects as $redirect ) {
				++$i;
				$progress->tick();

				// A 1.x source out of postmeta can hold raw multibyte bytes, which
				// a bare parse_url() corrupts on some hosts. The path stays
				// percent-encoded; SourceUrl below does the only decode.
				$from_path = Url::parse_encoded( (string) $redirect->meta_value )['path'] ?? '';
				if ( '' === $from_path ) {
					$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, 'Invalid source URL - no path found' );
					continue;
				}

				try {
					$source = SourceUrl::from_string( $from_path, HomePath::current() );
				} catch ( \InvalidArgumentException $e ) {
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

				if ( ! $dry_run ) {
					try {
						$destination = Destination::from_post_id( DestinationPostId::from_int( (int) $redirect->post_id ) );
						$result      = $this->manager->create_redirect( $source, $destination );

						if ( $result->is_error() ) {
							$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $result->error_message() ?? 'Could not insert redirect' );
						} elseif ( $verbose ) {
							$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, 'Successfully imported' );
						}
					} catch ( \InvalidArgumentException $e ) {
						$notices[] = $this->notice( $redirect->meta_value, (int) $redirect->post_id, $e->getMessage() );
					}
				}

				if ( 0 === $i % 100 ) {
					if ( function_exists( 'vip_inmemory_cleanup' ) ) {
						vip_inmemory_cleanup();
					}
					sleep( 1 );
				}
			}
			$offset += 1000;
		} while ( $total >= 1000 && $offset < $end_offset );

		$progress->finish();

		if ( count( $notices ) > 0 ) {
			\WP_CLI\Utils\format_items( $format, $notices, array( 'redirect_from', 'redirect_to', 'message' ) );
		} else {
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
