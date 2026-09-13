<?php
/**
 * Enable redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

/**
 * Enable one or more redirects.
 */
final class EnableCommand extends AbstractStatusCommand {

	/**
	 * Enable one or more redirects.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>...
	 * : One or more redirect IDs or source paths (e.g. /old-page).
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable redirect by source path.
	 *     $ wp wpcom-legacy-redirector enable /old-page
	 *
	 *     # Enable redirect by ID.
	 *     $ wp wpcom-legacy-redirector enable 123
	 *
	 *     # Enable multiple redirects.
	 *     $ wp wpcom-legacy-redirector enable /old-page /other-page
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->change_status( $args, 'publish', 'Enabled' );
	}
}
