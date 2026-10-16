<?php
/**
 * Delete redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;
use WP_CLI_Command;

/**
 * Delete one or more redirects.
 */
final class DeleteCommand extends WP_CLI_Command {

	use FormatsRedirectRows;
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
	 * @param RedirectBatch   $batch   The batch resolver.
	 */
	public function __construct( RedirectManager $manager, RedirectBatch $batch ) {
		$this->manager = $manager;
		$this->batch   = $batch;
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
	 *     $ wp legacy-redirector delete /old-page
	 *
	 *     # Delete redirect by ID.
	 *     $ wp legacy-redirector delete 123
	 *
	 *     # Delete multiple redirects without confirmation.
	 *     $ wp legacy-redirector delete /old-page /other-page --yes
	 *
	 *     # Delete all disabled redirects.
	 *     $ wp legacy-redirector list --status=disabled --format=ids | xargs wp legacy-redirector delete --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Name the row a single identifier resolves to, so a path that resolves
		// to a different row than the one meant shows before it goes for good.
		$single = 1 === count( $args ) ? $this->batch->resolve( $args )[0]['redirect'] : null;

		WP_CLI::confirm(
			match ( true ) {
				null !== $single     => sprintf( 'Are you sure you want to delete the redirect %s?', $this->describe( $single ) ),
				1 === count( $args ) => sprintf( 'Are you sure you want to delete the redirect "%s"?', $args[0] ),
				default              => sprintf( 'Are you sure you want to delete %d redirects?', count( $args ) ),
			},
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
				? sprintf( 'Deleted redirect: %s', $this->describe( $items[0]['redirect'] ) )
				: sprintf( 'Deleted %d redirects.', $deleted )
		);
	}

	/**
	 * Describe a redirect by its source, ID and destination.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return string The description.
	 */
	private function describe( Redirect $redirect ): string {
		$row = $this->redirect_row( $redirect );

		return sprintf( '%s (ID %d, to %s)', $row['from'], $row['ID'], $row['to'] );
	}
}
