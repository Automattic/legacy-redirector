<?php
/**
 * Base class for redirect status CLI commands.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\RedirectManager;
use WP_CLI;
use WP_CLI_Command;

/**
 * Shared behaviour for the enable and disable commands.
 */
abstract class AbstractStatusCommand extends WP_CLI_Command {

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	protected RedirectManager $manager;

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	protected RedirectFetcher $fetcher;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 * @param RedirectFetcher $fetcher The redirect fetcher.
	 */
	public function __construct( RedirectManager $manager, RedirectFetcher $fetcher ) {
		$this->manager = $manager;
		$this->fetcher = $fetcher;
	}

	/**
	 * Change the status of one or more redirects.
	 *
	 * @param array  $identifiers Redirect IDs or source paths.
	 * @param string $post_status The target post status ('publish' or 'draft').
	 * @param string $past_tense  The past tense verb for messages ('Enabled' or 'Disabled').
	 */
	protected function change_status( array $identifiers, string $post_status, string $past_tense ): void {
		$changed = 0;
		$failed  = 0;

		foreach ( $identifiers as $identifier ) {
			try {
				$redirect = $this->fetcher->fetch( $identifier );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::warning( sprintf( 'Invalid source path: %s (%s)', $identifier, $e->getMessage() ) );
				++$failed;
				continue;
			}

			if ( null === $redirect || ! $this->manager->change_status( $redirect->id(), $post_status ) ) {
				WP_CLI::warning( sprintf( 'Redirect not found: %s', $identifier ) );
				++$failed;
				continue;
			}

			++$changed;
		}

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
