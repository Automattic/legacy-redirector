<?php
/**
 * Base class for redirect status CLI commands.
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
 * Shared behaviour for the enable and disable commands.
 */
abstract class AbstractStatusCommand extends WP_CLI_Command {

	use ReportsBatchFailures;

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	protected RedirectManager $manager;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	protected RedirectBatch $batch;

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
	 * Change the status of one or more redirects.
	 *
	 * @param array  $identifiers Redirect IDs or source paths.
	 * @param string $post_status The target post status ('publish' or 'draft').
	 * @param string $past_tense  The past tense verb for messages ('Enabled' or 'Disabled').
	 */
	protected function change_status( array $identifiers, string $post_status, string $past_tense ): void {
		$items = $this->batch->apply(
			$identifiers,
			fn( Redirect $redirect ) => $this->manager->change_status( $redirect->id(), $post_status )
		);

		$changed = $this->report_batch_failures( $items, 'update' );
		$failed  = count( $items ) - $changed;

		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( 'Only %s %d of %d redirects.', strtolower( $past_tense ), $changed, count( $identifiers ) ) );
			return;
		}

		WP_CLI::success(
			1 === $changed
				? sprintf( '%s redirect: %s', $past_tense, $identifiers[0] )
				: sprintf( '%s %d redirects.', $past_tense, $changed )
		);
	}
}
