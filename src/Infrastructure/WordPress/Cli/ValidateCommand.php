<?php
/**
 * Validate redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;
use WP_CLI;
use WP_CLI_Command;

/**
 * Validate redirects for broken destinations.
 */
final class ValidateCommand extends WP_CLI_Command {

	use FormatsRedirectRows;
	use ReportsBatchFailures;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectBatch                    $batch            The batch resolver.
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param RedirectManager                  $manager          The redirect manager.
	 */
	public function __construct(
		RedirectBatch $batch,
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		RedirectManager $manager
	) {
		$this->batch            = $batch;
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->manager          = $manager;
	}

	/**
	 * Validate redirects and find broken destinations.
	 *
	 * Checks for:
	 * - Destinations pointing to deleted or trashed posts
	 * - Destinations pointing to unpublished posts
	 * - Sources on a path WordPress itself serves, such as /wp-admin or
	 *   /wp-login.php (reported, never disabled by --fix)
	 * - Optionally checks if destination URLs return 404
	 *
	 * With no arguments, validates redirects matching --status/--limit.
	 * Pass one or more redirect IDs or source paths to validate just those.
	 *
	 * ## OPTIONS
	 *
	 * [<redirect>...]
	 * : Optional redirect IDs or source paths to validate.
	 *
	 * [--check-urls]
	 * : Also check if URL destinations return 404 (slow, makes HTTP requests).
	 *
	 * [--status=<status>]
	 * : Only check redirects with this status (ignored when redirects are given).
	 * ---
	 * default: enabled
	 * options:
	 *   - any
	 *   - enabled
	 *   - disabled
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum number of redirects to check (ignored when redirects are given).
	 * ---
	 * default: 1000
	 * ---
	 *
	 * [--fix]
	 * : Automatically disable broken redirects.
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
	 *     # Find broken redirects.
	 *     $ wp legacy-redirector validate
	 *
	 *     # Validate a single redirect by source path, including URL checks.
	 *     $ wp legacy-redirector validate /old-page --check-urls
	 *
	 *     # Validate a single redirect by ID.
	 *     $ wp legacy-redirector validate 123
	 *
	 *     # Find and disable broken redirects.
	 *     $ wp legacy-redirector validate --fix
	 *
	 *     # Check all redirects (enabled and disabled).
	 *     $ wp legacy-redirector validate --status=any
	 *
	 *     # Get count of broken redirects.
	 *     $ wp legacy-redirector validate --format=count
	 *
	 *     # Export broken redirects to a CSV file.
	 *     $ wp legacy-redirector validate --format=csv > broken.csv
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$check_urls = (bool) ( $assoc_args['check-urls'] ?? false );
		$fix        = isset( $assoc_args['fix'] );
		$format     = $assoc_args['format'] ?? 'table';
		$is_table   = 'table' === $format;

		$redirects = empty( $args )
			? $this->fetch_by_criteria( $assoc_args )
			: $this->fetch_by_identifiers( $args );

		if ( null === $redirects ) {
			return;
		}

		if ( $is_table && $check_urls ) {
			WP_CLI::warning( 'URL checking enabled - this may be slow.' );
		}

		$total    = count( $redirects );
		$progress = $is_table ? \WP_CLI\Utils\make_progress_bar( 'Validating redirects', $total ) : null;

		$issues = $this->auditor->validate_batch(
			$redirects,
			$check_urls,
			function () use ( $progress ): void {
				if ( null !== $progress ) {
					$progress->tick();
				}
			}
		);

		if ( null !== $progress ) {
			$progress->finish();
		}

		// Handle count format.
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $issues ) );
			return;
		}

		if ( $is_table ) {
			WP_CLI::line( sprintf( 'Checked %d redirect(s).', $total ) );
		}

		if ( empty( $issues ) ) {
			if ( $is_table ) {
				WP_CLI::success( 'No issues found.' );
			}
			return;
		}

		if ( $is_table ) {
			WP_CLI::warning( sprintf( 'Found %d issue(s).', count( $issues ) ) );
			WP_CLI::line( '' );
		}

		// Convert issues to array format for display.
		$items = array_map(
			fn( ValidationIssue $issue ) => $this->redirect_row( $issue->redirect() ) + array( 'issue' => $issue->label() ),
			$issues
		);

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'from', 'to', 'type', 'issue', 'status' ) );

		// Fix if requested.
		if ( $fix ) {
			$this->fix_issues( $issues );
		}
	}

	/**
	 * Fetch redirects matching the batch criteria.
	 *
	 * @param array $assoc_args Key-value associative arguments.
	 * @return Redirect[] The redirects to validate.
	 */
	private function fetch_by_criteria( array $assoc_args ): array {
		$status = $assoc_args['status'] ?? 'enabled';
		$limit  = (int) ( $assoc_args['limit'] ?? 1000 );

		$criteria = new RedirectCriteria(
			'any' === $status ? null : $status,
			null, // destination_type.
			null, // search.
			'date',
			'DESC',
			$limit,
			0
		);

		return $this->query_repository->find_matching( $criteria );
	}

	/**
	 * Fetch redirects by identifier, warning about any that cannot be resolved.
	 *
	 * @param string[] $identifiers Redirect IDs or source paths.
	 * @return Redirect[]|null The redirects, or null if none could be resolved.
	 */
	private function fetch_by_identifiers( array $identifiers ): ?array {
		$items = $this->batch->resolve( $identifiers );
		$this->report_batch_failures( $items, 'validate' );

		$redirects = RedirectBatch::redirects( $items );

		if ( empty( $redirects ) ) {
			WP_CLI::error( 'No matching redirects found.' );
			return null;
		}

		return $redirects;
	}

	/**
	 * Disable the redirects behind the given issues.
	 *
	 * @param ValidationIssue[] $issues The issues to fix.
	 */
	private function fix_issues( array $issues ): void {
		WP_CLI::line( '' );
		$fixed   = 0;
		$corrupt = 0;

		foreach ( $issues as $issue ) {
			// A reserved source is a warning, not a breakage: the redirect may be a
			// legitimate legacy URL, so it is left for a person to judge.
			if ( ValidationIssueType::RESERVED_SOURCE === $issue->type() ) {
				continue;
			}

			// A corrupt row cannot be re-saved, so it cannot be auto-disabled.
			if ( $issue->redirect()->is_corrupt() ) {
				++$corrupt;
				continue;
			}

			if ( $issue->redirect()->is_active() && $this->manager->disable( $issue->redirect_id() ) ) {
				++$fixed;
			}
		}

		WP_CLI::success( sprintf( 'Disabled %d broken redirect(s).', $fixed ) );

		if ( $corrupt > 0 ) {
			WP_CLI::warning(
				sprintf(
					'%d redirect(s) hold corrupt stored data and cannot be auto-disabled. Delete them, or update them with a new source and destination.',
					$corrupt
				)
			);
		}
	}
}
