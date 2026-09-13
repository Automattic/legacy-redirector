<?php
/**
 * Export to CSV CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use WP_CLI;
use WP_CLI_Command;

/**
 * Export redirects to a CSV file.
 */
final class ExportToCsvCommand extends WP_CLI_Command {

	/**
	 * Export redirects to a CSV file.
	 *
	 * Exports redirects with the following structure:
	 *    redirect_from_path,(redirect_to_post_id|redirect_to_path|redirect_to_url),status
	 *
	 * The status column contains 'enabled' or 'disabled'.
	 *
	 * ## OPTIONS
	 *
	 * --csv=<path-to-csv>
	 * : Path to CSV.
	 *
	 * [--status=<status>]
	 * : Filter by redirect status.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - enabled
	 *   - disabled
	 * ---
	 *
	 * [--overwrite]
	 * : Whether to overwrite an existing file. Defaults to false.
	 *
	 * [--broken-only]
	 * : Only export redirects with broken destinations.
	 *
	 * [--check-urls]
	 * : When using --broken-only, also check URL destinations via HTTP.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export all redirects to a CSV file.
	 *     $ wp wpcom-legacy-redirector export-to-csv --csv=path/to/redirects.csv
	 *
	 *     # Export only enabled redirects.
	 *     $ wp wpcom-legacy-redirector export-to-csv --csv=path/to/redirects.csv --status=enabled
	 *
	 *     # Export only disabled redirects.
	 *     $ wp wpcom-legacy-redirector export-to-csv --csv=path/to/redirects.csv --status=disabled
	 *
	 *     # Export only broken redirects.
	 *     $ wp wpcom-legacy-redirector export-to-csv --csv=path/to/redirects.csv --broken-only
	 *
	 *     # Export broken redirects, including URL destination checks.
	 *     $ wp wpcom-legacy-redirector export-to-csv --csv=path/to/redirects.csv --broken-only --check-urls
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$filename      = $assoc_args['csv'] ?? false;
		$overwrite     = isset( $assoc_args['overwrite'] ) ? (bool) $assoc_args['overwrite'] : false;
		$status_filter = $assoc_args['status'] ?? 'any';
		$broken_only   = isset( $assoc_args['broken-only'] );
		$check_urls    = isset( $assoc_args['check-urls'] );

		if ( ! $filename ) {
			WP_CLI::error( 'Invalid CSV file!' );
		}

		if ( file_exists( $filename ) && ! $overwrite ) {
			WP_CLI::error( 'CSV file already exists!' );
		} elseif ( file_exists( $filename ) && $overwrite ) {
			WP_CLI::warning( 'Overwriting file ' . $filename );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CLI command, WP_Filesystem not appropriate.
		$file_descriptor = fopen( $filename, 'wb' );

		if ( ! $file_descriptor ) {
			WP_CLI::error( 'Invalid CSV filename!' );
		}

		$posts_per_page = 100;
		$paged          = 1;

		// Map filter values to post statuses.
		$post_status = 'any';
		if ( 'enabled' === $status_filter ) {
			$post_status = 'publish';
		} elseif ( 'disabled' === $status_filter ) {
			$post_status = 'draft';
		}

		// Get count for progress bar (exclude trash for 'any').
		// wp_count_posts() returns counts as numeric strings.
		$counts = (array) wp_count_posts( PostType::POST_TYPE );
		if ( 'any' === $post_status ) {
			$post_count = (int) ( $counts['publish'] ?? 0 ) + (int) ( $counts['draft'] ?? 0 );
		} else {
			$post_count = (int) ( $counts[ $post_status ] ?? 0 );
		}

		$label = $broken_only ? 'Scanning ' : 'Exporting ';
		if ( $broken_only && $check_urls ) {
			WP_CLI::warning( 'URL checking enabled - this may be slow.' );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( $label . number_format( $post_count ) . ' redirects', $post_count );
		$output   = array();

		do {
			$query_status = 'any' === $post_status ? array( 'publish', 'draft' ) : $post_status;

			$posts = get_posts(
				array(
					'posts_per_page'   => $posts_per_page,
					'paged'            => $paged,
					'post_type'        => PostType::POST_TYPE,
					'post_status'      => $query_status,
					// The export must be exhaustive: suppress posts_* filters so
					// third-party plugins (multilingual, search, visibility) cannot
					// silently exclude redirects. Note pre_get_posts still runs.
					'suppress_filters' => true,
				)
			);

			foreach ( $posts as $post ) {
				// If broken-only mode, check if the redirect is broken.
				if ( $broken_only ) {
					$issue = $this->check_redirect( $post, $check_urls );
					if ( null === $issue ) {
						continue; // Skip valid redirects.
					}
				}

				$redirect_from = $post->post_title;
				$redirect_to   = ( $post->post_parent && 0 !== $post->post_parent ) ? $post->post_parent : $post->post_excerpt;
				$status        = 'publish' === $post->post_status ? 'enabled' : 'disabled';
				$output[]      = array( $redirect_from, $redirect_to, $status );
			}
			$progress->tick( $posts_per_page );

			if ( function_exists( 'vip_inmemory_cleanup' ) ) {
				vip_inmemory_cleanup();
			}

			++$paged;
			$posts_count = count( $posts );
		} while ( $posts_count );

		$progress->finish();

		if ( $broken_only ) {
			WP_CLI::line( sprintf( 'Found %d broken redirects.', count( $output ) ) );
		}

		\WP_CLI\Utils\write_csv( $file_descriptor, $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CLI command, WP_Filesystem not appropriate.
		fclose( $file_descriptor );
	}

	/**
	 * Check a redirect for issues.
	 *
	 * @param \WP_Post $post       The redirect post.
	 * @param bool     $check_urls Whether to check URL destinations via HTTP.
	 * @return string|null The issue description, or null if valid.
	 */
	private function check_redirect( \WP_Post $post, bool $check_urls ): ?string {
		$is_post_dest = $post->post_parent > 0;

		if ( $is_post_dest ) {
			return $this->check_post_destination( $post->post_parent );
		}

		// URL destination.
		$url = $post->post_excerpt;

		if ( empty( $url ) ) {
			return 'Empty destination';
		}

		// Check if it's a relative path.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $this->check_relative_path( $url, $check_urls );
		}

		// External URL - only check if requested.
		if ( $check_urls ) {
			return $this->check_url( $url );
		}

		return null;
	}

	/**
	 * Check if a post destination is valid.
	 *
	 * @param int $post_id The post ID.
	 * @return string|null The issue, or null if valid.
	 */
	private function check_post_destination( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return 'Post deleted';
		}

		if ( 'trash' === $post->post_status ) {
			return 'Post trashed';
		}

		if ( 'publish' !== $post->post_status ) {
			return 'Post not published';
		}

		return null;
	}

	/**
	 * Check if a relative path destination is valid.
	 *
	 * @param string $path       The relative path.
	 * @param bool   $check_urls Whether to check via HTTP.
	 * @return string|null The issue, or null if valid.
	 */
	private function check_relative_path( string $path, bool $check_urls ): ?string {
		// Try to find a post by path.
		$post = get_page_by_path( ltrim( $path, '/' ), OBJECT, array( 'post', 'page' ) );

		if ( null !== $post ) {
			if ( 'trash' === $post->post_status ) {
				return 'Destination page trashed';
			}
			if ( 'publish' !== $post->post_status ) {
				return 'Destination page not published';
			}
			return null;
		}

		// If URL checking is enabled, verify via HTTP.
		if ( $check_urls ) {
			$full_url = home_url( $path );
			return $this->check_url( $full_url );
		}

		// Can't determine without HTTP check.
		return null;
	}

	/**
	 * Check if a URL returns a successful response.
	 *
	 * @param string $url The URL to check.
	 * @return string|null The issue, or null if valid.
	 */
	private function check_url( string $url ): ?string {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'Request failed';
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			return 'Destination returns 404';
		}

		if ( $status_code >= 500 ) {
			return 'Destination returns error';
		}

		return null;
	}
}
