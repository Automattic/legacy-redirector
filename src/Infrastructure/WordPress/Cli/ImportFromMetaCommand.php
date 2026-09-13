<?php
/**
 * Import from meta CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
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
	 * : Starting offset. Defaults to 0.
	 *
	 * [--end=<end-offset>]
	 * : Ending offset. Defaults to 99999999.
	 *
	 * [--skip-dupes]
	 * : If set, redirects for a From URL with an existing redirect will be skipped.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: csv
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--dry-run]
	 * : If set, redirects are not imported. Defaults to false.
	 *
	 * [--verbose]
	 * : Display notices for successful imports and duplicates (if --skip-dupes is used). Defaults to false.
	 *
	 * ## EXAMPLES
	 *
	 *     # Bulk import from a my-redirect meta key.
	 *     $ wp wpcom-legacy-redirector import-from-meta --meta-key=my-redirect
	 *     ---Live Run---
	 *     Importing 143 redirects
	 *     All of your redirects have been imported. Nice work!
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		global $wpdb;

		$offset     = isset( $assoc_args['start'] ) ? intval( $assoc_args['start'] ) : 0;
		$end_offset = isset( $assoc_args['end'] ) ? intval( $assoc_args['end'] ) : 99999999;
		$meta_key   = isset( $assoc_args['meta-key'] ) ? sanitize_key( $assoc_args['meta-key'] ) : '';
		$skip_dupes = isset( $assoc_args['skip-dupes'] );
		$format     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format' );
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

				$from_path = wp_parse_url( $redirect->meta_value, PHP_URL_PATH );
				if ( ! $from_path ) {
					$notices[] = array(
						'redirect_from' => $redirect->meta_value,
						'redirect_to'   => $redirect->post_id,
						'message'       => 'Invalid source URL - no path found',
					);
					continue;
				}

				try {
					$source = SourceUrl::from_string( $from_path );
				} catch ( \InvalidArgumentException $e ) {
					$notices[] = array(
						'redirect_from' => $redirect->meta_value,
						'redirect_to'   => $redirect->post_id,
						'message'       => $e->getMessage(),
					);
					continue;
				}

				$existing_redirect = $this->repository->get_id_by_source( $source );
				if ( $skip_dupes && 0 !== $existing_redirect ) {
					if ( $verbose ) {
						$notices[] = array(
							'redirect_from' => $redirect->meta_value,
							'redirect_to'   => $redirect->post_id,
							'message'       => sprintf( 'Skipped - Redirect for this from URL already exists (%s)', $redirect->meta_value ),
						);
					}
					continue;
				}

				if ( ! $dry_run ) {
					try {
						$destination = Destination::from_post_id( DestinationPostId::from_int( (int) $redirect->post_id ) );
						$result      = $this->manager->create_redirect( $source, $destination );

						if ( $result->is_error() ) {
							$notices[] = array(
								'redirect_from' => $redirect->meta_value,
								'redirect_to'   => $redirect->post_id,
								'message'       => $result->error_message(),
							);
						} elseif ( $verbose ) {
							$notices[] = array(
								'redirect_from' => $redirect->meta_value,
								'redirect_to'   => $redirect->post_id,
								'message'       => 'Successfully imported',
							);
						}
					} catch ( \InvalidArgumentException $e ) {
						$notices[] = array(
							'redirect_from' => $redirect->meta_value,
							'redirect_to'   => $redirect->post_id,
							'message'       => $e->getMessage(),
						);
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
}
