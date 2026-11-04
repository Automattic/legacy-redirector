<?php
/**
 * Parent CLI command for the redirector.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use WP_CLI_Command;

/**
 * Manage redirects added via the Legacy Redirector plugin.
 */
final class RedirectorCommand extends WP_CLI_Command {
	// Subcommands are registered separately.
}
