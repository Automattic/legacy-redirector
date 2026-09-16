<?php
/**
 * List redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use WP_CLI;
use WP_CLI_Command;

/**
 * List redirects with filtering options.
 */
final class ListCommand extends WP_CLI_Command {

	use FormatsRedirectRows;

	/**
	 * Default output fields.
	 *
	 * @var string[]
	 */
	private const array DEFAULT_FIELDS = array( 'ID', 'from', 'to', 'type', 'status' );

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
	 * List redirects.
	 *
	 * ## OPTIONS
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
	 * [--destination-type=<type>]
	 * : Filter by destination type.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - post
	 *   - url
	 * ---
	 *
	 * [--search=<search>]
	 * : Search in source paths.
	 *
	 * [--limit=<number>]
	 * : Maximum number of redirects to show.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--offset=<number>]
	 * : Number of redirects to skip.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--orderby=<field>]
	 * : Field to order by.
	 * ---
	 * default: date
	 * options:
	 *   - date
	 *   - title
	 *   - modified
	 * ---
	 *
	 * [--order=<order>]
	 * : Sort order.
	 * ---
	 * default: DESC
	 * options:
	 *   - ASC
	 *   - DESC
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated). Available: ID, from, to, type, status.
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all redirects.
	 *     $ wp wpcom-legacy-redirector list
	 *
	 *     # List disabled redirects.
	 *     $ wp wpcom-legacy-redirector list --status=disabled
	 *
	 *     # List redirects pointing to posts.
	 *     $ wp wpcom-legacy-redirector list --destination-type=post
	 *
	 *     # Search for redirects containing "blog".
	 *     $ wp wpcom-legacy-redirector list --search=blog
	 *
	 *     # Get count of all redirects.
	 *     $ wp wpcom-legacy-redirector list --format=count
	 *
	 *     # Export all redirects to a CSV file.
	 *     $ wp wpcom-legacy-redirector list --limit=100000 --format=csv > redirects.csv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$criteria = self::criteria_from_args( $assoc_args );

		// Resolve output fields.
		$fields = self::DEFAULT_FIELDS;
		if ( isset( $assoc_args['fields'] ) ) {
			$fields  = array_map( 'trim', explode( ',', $assoc_args['fields'] ) );
			$invalid = array_diff( $fields, self::DEFAULT_FIELDS );
			if ( ! empty( $invalid ) ) {
				WP_CLI::error( sprintf( 'Invalid fields: %s. Available fields: %s', implode( ', ', $invalid ), implode( ', ', self::DEFAULT_FIELDS ) ) );
				return;
			}
		}

		// Handle count format - only needs count, not full results.
		if ( 'count' === $format ) {
			$count = $this->query_repository->count_matching( $criteria );
			WP_CLI::line( (string) $count );
			return;
		}

		// Fetch redirects matching criteria.
		$redirects = $this->query_repository->find_matching( $criteria );

		// Handle ids format.
		if ( 'ids' === $format ) {
			$ids = array_map(
				fn( $redirect ) => $redirect->id(),
				$redirects
			);
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		if ( empty( $redirects ) ) {
			WP_CLI::warning( 'No redirects found.' );
			return;
		}

		// Build output data.
		$items = array_map(
			fn( Redirect $redirect ) => $this->redirect_row( $redirect ),
			$redirects
		);

		\WP_CLI\Utils\format_items( $format, $items, $fields );

		// Show pagination info for table format.
		if ( 'table' === $format ) {
			$total_count = $this->query_repository->count_matching( $criteria );
			if ( $total_count > count( $redirects ) ) {
				WP_CLI::line( '' );
				WP_CLI::line(
					sprintf(
						'Showing %d-%d of %d redirects. Use --offset and --limit for pagination.',
						$criteria->offset() + 1,
						$criteria->offset() + count( $redirects ),
						$total_count
					)
				);
			}
		}
	}

	/**
	 * Build query criteria from the command's arguments.
	 *
	 * @param array $assoc_args Key-value associative arguments.
	 * @return RedirectCriteria The criteria to query with.
	 */
	private static function criteria_from_args( array $assoc_args ): RedirectCriteria {
		$status = $assoc_args['status'] ?? null;
		$type   = $assoc_args['destination-type'] ?? null;

		return new RedirectCriteria(
			'any' === $status ? null : $status,
			'any' === $type ? null : $type,
			$assoc_args['search'] ?? null,
			$assoc_args['orderby'] ?? 'date',
			$assoc_args['order'] ?? 'DESC',
			(int) ( $assoc_args['limit'] ?? 100 ),
			(int) ( $assoc_args['offset'] ?? 0 )
		);
	}
}
