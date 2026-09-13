<?php
/**
 * Validate redirects CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use WP_CLI;
use WP_CLI_Command;

/**
 * Validate redirects for broken destinations.
 */
final class ValidateCommand extends WP_CLI_Command {

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	private RedirectFetcher $fetcher;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect validator.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectFetcher                  $fetcher          The redirect fetcher.
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectValidator                $validator        The redirect validator.
	 * @param RedirectManager                  $manager          The redirect manager.
	 */
	public function __construct(
		RedirectFetcher $fetcher,
		RedirectQueryRepositoryInterface $query_repository,
		RedirectValidator $validator,
		RedirectManager $manager
	) {
		$this->fetcher          = $fetcher;
		$this->query_repository = $query_repository;
		$this->validator        = $validator;
		$this->manager          = $manager;
	}

	/**
	 * Validate redirects and find broken destinations.
	 *
	 * Checks for:
	 * - Destinations pointing to deleted or trashed posts
	 * - Destinations pointing to unpublished posts
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
	 *     $ wp wpcom-legacy-redirector validate
	 *
	 *     # Validate a single redirect by source path, including URL checks.
	 *     $ wp wpcom-legacy-redirector validate /old-page --check-urls
	 *
	 *     # Validate a single redirect by ID.
	 *     $ wp wpcom-legacy-redirector validate 123
	 *
	 *     # Find and disable broken redirects.
	 *     $ wp wpcom-legacy-redirector validate --fix
	 *
	 *     # Check all redirects (enabled and disabled).
	 *     $ wp wpcom-legacy-redirector validate --status=any
	 *
	 *     # Get count of broken redirects.
	 *     $ wp wpcom-legacy-redirector validate --format=count
	 *
	 *     # Export broken redirects to a CSV file.
	 *     $ wp wpcom-legacy-redirector validate --format=csv > broken.csv
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

		$issues = $this->validator->validate_batch(
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
			WP_CLI::warning( sprintf( 'Found %d broken redirect(s).', count( $issues ) ) );
			WP_CLI::line( '' );
		}

		// Convert issues to array format for display.
		$items = array_map(
			fn( ValidationIssue $issue ) => $issue->to_array(),
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
		$redirects = array();

		foreach ( $identifiers as $identifier ) {
			try {
				$redirect = $this->fetcher->fetch( $identifier );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::warning( sprintf( 'Invalid source path: %s (%s)', $identifier, $e->getMessage() ) );
				continue;
			}

			if ( null === $redirect ) {
				WP_CLI::warning( sprintf( 'Redirect not found: %s', $identifier ) );
				continue;
			}

			$redirects[] = $redirect;
		}

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
		$fixed = 0;

		foreach ( $issues as $issue ) {
			if ( $issue->redirect()->is_active() && $this->manager->disable( $issue->redirect_id() ) ) {
				++$fixed;
			}
		}

		WP_CLI::success( sprintf( 'Disabled %d broken redirect(s).', $fixed ) );
	}
}
