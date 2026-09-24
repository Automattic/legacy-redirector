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
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;
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
	 * Output fields for --duplicates.
	 *
	 * @var string[]
	 */
	private const array DUPLICATE_FIELDS = array( 'ID', 'from', 'to', 'never_fired', 'duplicate_of', 'duplicate_of_from', 'duplicate_of_to' );

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * The upgrade routine, which records the duplicate sources it disabled.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectRepositoryInterface      $repository       The redirect repository.
	 * @param Upgrader                         $upgrader         The upgrade routine.
	 */
	public function __construct( RedirectQueryRepositoryInterface $query_repository, RedirectRepositoryInterface $repository, Upgrader $upgrader ) {
		$this->query_repository = $query_repository;
		$this->repository       = $repository;
		$this->upgrader         = $upgrader;
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
	 * [--duplicates]
	 * : List only the redirects the 2.0 migration disabled because, once
	 * normalized, they have the same source as another redirect with a
	 * different destination, alongside that live redirect. `from` is the
	 * disabled redirect's spelling as 1.x stored it, and `never_fired` says
	 * whether any browser could ever have requested it: if not, disabling it
	 * changed nothing for visitors. To keep the live redirect's destination,
	 * delete the disabled one; to keep the disabled one's, update the live
	 * redirect to it, then delete the disabled one. Deleting or trashing it
	 * takes it off this list. The filters and pagination above do not apply.
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated). Available: ID, from, to, type, status. With --duplicates: ID, from, to, never_fired, duplicate_of, duplicate_of_from, duplicate_of_to.
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
	 *     $ wp legacy-redirector list
	 *
	 *     # List disabled redirects.
	 *     $ wp legacy-redirector list --status=disabled
	 *
	 *     # List redirects pointing to posts.
	 *     $ wp legacy-redirector list --destination-type=post
	 *
	 *     # Search for redirects containing "blog".
	 *     $ wp legacy-redirector list --search=blog
	 *
	 *     # Get count of all redirects.
	 *     $ wp legacy-redirector list --format=count
	 *
	 *     # Export all redirects to a CSV file.
	 *     $ wp legacy-redirector list --limit=100000 --format=csv > redirects.csv
	 *
	 *     # Export the duplicate sources the migration disabled.
	 *     $ wp legacy-redirector list --duplicates --format=csv > duplicates.csv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format   = $assoc_args['format'] ?? 'table';
		$criteria = self::criteria_from_args( $assoc_args );

		$duplicates = isset( $assoc_args['duplicates'] );
		$available  = $duplicates ? self::DUPLICATE_FIELDS : self::DEFAULT_FIELDS;

		// Resolve output fields.
		$fields = $available;
		if ( isset( $assoc_args['fields'] ) ) {
			$fields  = array_map( 'trim', explode( ',', $assoc_args['fields'] ) );
			$invalid = array_diff( $fields, $available );
			if ( ! empty( $invalid ) ) {
				WP_CLI::error( sprintf( 'Invalid fields: %s. Available fields: %s', implode( ', ', $invalid ), implode( ', ', $available ) ) );
				return;
			}
		}

		if ( $duplicates ) {
			$this->list_duplicates( $format, $fields );
			return;
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
	 * Print the redirects the migration disabled as duplicate sources.
	 *
	 * @param string   $format The output format.
	 * @param string[] $fields The fields to show.
	 * @return void
	 */
	private function list_duplicates( string $format, array $fields ): void {
		$items = array();

		foreach ( $this->upgrader->duplicates() as $id => $duplicate ) {
			$live_id  = $duplicate['of'];
			$redirect = $this->repository->find_by_id( $id );

			if ( null === $redirect ) {
				continue;
			}

			$live = $this->repository->find_by_id( $live_id );
			$row  = $this->redirect_row( $redirect );

			$items[] = array(
				'ID'                => $row['ID'],
				// The spelling as stored, not as normalized: the two redirects
				// normalize alike, and the difference is what needs deciding.
				'from'              => (string) get_post_field( 'post_title', $id ),
				'to'                => $row['to'],
				'never_fired'       => $duplicate['never_fired'] ? 'yes' : 'no',
				'duplicate_of'      => $live_id,
				'duplicate_of_from' => null === $live ? '(deleted)' : $live->source()->path(),
				'duplicate_of_to'   => null === $live ? '' : $this->redirect_row( $live )['to'],
			);
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $items ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_column( $items, 'ID' ) ) );
			return;
		}

		if ( array() === $items ) {
			WP_CLI::success( 'No redirects are disabled as duplicate sources.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
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
