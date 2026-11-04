<?php
/**
 * Disable redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

/**
 * Disable one or more redirects.
 */
final class DisableCommand extends AbstractStatusCommand {

	/**
	 * Disable one or more redirects.
	 *
	 * Disabled redirects are kept in the database but do not redirect.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>...
	 * : One or more redirect IDs or source paths (e.g. /old-page).
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable redirect by source path.
	 *     $ wp legacy-redirector disable /old-page
	 *
	 *     # Disable redirect by ID.
	 *     $ wp legacy-redirector disable 123
	 *
	 *     # Disable multiple redirects.
	 *     $ wp legacy-redirector disable /old-page /other-page
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->change_status( $args, 'draft', 'Disabled' );
	}
}
