<?php
/**
 * Find domains CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use WP_CLI;
use WP_CLI_Command;

/**
 * Find unique outbound domains in redirects.
 */
final class FindDomainsCommand extends WP_CLI_Command {

	/**
	 * Number of URLs fetched per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 */
	public function __construct( RedirectQueryRepositoryInterface $query_repository ) {
		$this->query_repository = $query_repository;
	}

	/**
	 * Find domains redirected to, useful to populate the allowed_redirect_hosts filter.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
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
	 *     # Get a list of the domains used as redirect destinations.
	 *     $ wp wpcom-legacy-redirector find-domains
	 *
	 *     # Get the domains as a plain CSV column.
	 *     $ wp wpcom-legacy-redirector find-domains --format=csv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$is_table = 'table' === $format;
		$domains  = array();
		$offset   = 0;

		$total    = $this->query_repository->count_external_destinations();
		$progress = $is_table ? \WP_CLI\Utils\make_progress_bar( 'Finding domains', $total ) : null;

		do {
			$redirect_urls = $this->query_repository->get_external_destination_urls( self::PAGE_SIZE, $offset );

			foreach ( $redirect_urls as $redirect_url ) {
				if ( null !== $progress ) {
					$progress->tick();
				}

				if ( empty( $redirect_url ) ) {
					continue;
				}

				$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
				if ( $redirect_host ) {
					$domains[ $redirect_host ] = true;
				}
			}

			$offset            += self::PAGE_SIZE;
			$fetched_urls_count = count( $redirect_urls );
		} while ( self::PAGE_SIZE === $fetched_urls_count );

		if ( null !== $progress ) {
			$progress->finish();
		}

		$domains = array_keys( $domains );
		sort( $domains );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $domains ) );
			return;
		}

		if ( $is_table ) {
			WP_CLI::line( sprintf( 'Found %s unique outbound domain(s).', number_format_i18n( count( $domains ) ) ) );
		}

		$items = array_map(
			fn( string $domain ) => array( 'domain' => $domain ),
			$domains
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'domain' ) );
	}
}
