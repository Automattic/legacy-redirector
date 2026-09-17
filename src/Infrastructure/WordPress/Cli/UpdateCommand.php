<?php
/**
 * Update redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;
use WP_CLI_Command;

/**
 * Update one or more redirects.
 */
final class UpdateCommand extends WP_CLI_Command {

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
	 * Update the destination and/or status of one or more redirects.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>...
	 * : One or more redirect IDs or source paths (e.g. /old-page).
	 *
	 * [--to=<destination>]
	 * : The new destination. A path, a full URL, or a post ID.
	 *
	 * [--status=<status>]
	 * : The new status.
	 * ---
	 * options:
	 *   - enabled
	 *   - disabled
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Update a redirect's destination.
	 *     $ wp legacy-redirector update /old-page --to=/new-page
	 *
	 *     # Point a redirect at a post.
	 *     $ wp legacy-redirector update /old-page --to=123
	 *
	 *     # Update the destination and disable the redirect.
	 *     $ wp legacy-redirector update /old-page --to=/new-page --status=disabled
	 *
	 *     # Point several redirects at the same destination.
	 *     $ wp legacy-redirector update /old-a /old-b --to=/new-page
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$to_value    = $assoc_args['to'] ?? null;
		$status_flag = $assoc_args['status'] ?? null;

		if ( null === $to_value && null === $status_flag ) {
			WP_CLI::error( 'Please specify at least one of --to or --status.' );
			return;
		}

		// Parse the destination once.
		$destination = null;
		if ( null !== $to_value ) {
			try {
				$destination = Destination::from_mixed( ctype_digit( (string) $to_value ) ? (int) $to_value : $to_value );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::error( sprintf( 'Invalid destination: %s', $e->getMessage() ) );
				return;
			}
		}

		$post_status = null;
		if ( null !== $status_flag ) {
			$post_status = 'disabled' === $status_flag ? 'draft' : 'publish';
		}

		$items = $this->batch->apply(
			$args,
			fn( Redirect $redirect ) => null !== $destination
				? $this->manager->update_destination( $redirect->id(), $destination, $post_status )
				: $this->manager->change_status( $redirect->id(), $post_status )
		);

		$updated = $this->report_batch_failures( $items, 'update' );
		$failed  = count( $items ) - $updated;

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( 'Only updated %d of %d redirects.', $updated, count( $args ) ) );
			return;
		}

		WP_CLI::success(
			1 === $updated
				? sprintf( 'Updated redirect: %s', $args[0] )
				: sprintf( 'Updated %d redirects.', $updated )
		);
	}
}
