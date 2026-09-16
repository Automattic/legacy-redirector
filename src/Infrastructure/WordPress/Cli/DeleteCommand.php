<?php
/**
 * Delete redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;
use WP_CLI_Command;

/**
 * Delete one or more redirects.
 */
final class DeleteCommand extends WP_CLI_Command {

	use ReportsBatchFailures;

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 * @param RedirectFetcher $fetcher The redirect fetcher.
	 */
	public function __construct( RedirectManager $manager, RedirectFetcher $fetcher ) {
		$this->manager = $manager;
		$this->batch   = new RedirectBatch( $fetcher );
	}

	/**
	 * Delete one or more redirects.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>...
	 * : One or more redirect IDs or source paths (e.g. /old-page).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete redirect by source path.
	 *     $ wp wpcom-legacy-redirector delete /old-page
	 *
	 *     # Delete redirect by ID.
	 *     $ wp wpcom-legacy-redirector delete 123
	 *
	 *     # Delete multiple redirects without confirmation.
	 *     $ wp wpcom-legacy-redirector delete /old-page /other-page --yes
	 *
	 *     # Delete all disabled redirects.
	 *     $ wp wpcom-legacy-redirector list --status=disabled --format=ids | xargs wp wpcom-legacy-redirector delete --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		WP_CLI::confirm(
			sprintf(
				1 === count( $args )
					? 'Are you sure you want to delete the redirect "%s"?'
					: 'Are you sure you want to delete %2$d redirects?',
				$args[0],
				count( $args )
			),
			$assoc_args
		);

		$items = $this->batch->apply(
			$args,
			fn( Redirect $redirect ): bool => $this->manager->delete_by_id( $redirect->id() )
		);

		$deleted = $this->report_batch_failures( $items, 'delete' );
		$failed  = count( $items ) - $deleted;

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( 'Only deleted %d of %d redirects.', $deleted, count( $args ) ) );
			return;
		}

		WP_CLI::success(
			1 === $deleted
				? sprintf( 'Deleted redirect: %s', $args[0] )
				: sprintf( 'Deleted %d redirects.', $deleted )
		);
	}
}
