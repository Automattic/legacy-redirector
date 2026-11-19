<?php
/**
 * Records the import commands' pauses instead of sleeping, for integration tests.
 *
 * The commands call sleep() unqualified, so PHP resolves this namespaced
 * function before the global one. Tests assert on the recorded calls rather
 * than on elapsed time, and no import test really sleeps.
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

/**
 * Record a pause instead of taking it.
 *
 * @param int $seconds Seconds the command asked to sleep for.
 * @return int Zero, as sleep() returns when not interrupted.
 */
function sleep( int $seconds ): int {
	$GLOBALS['legacy_redirector_cli_sleeps'][] = $seconds;

	return 0;
}
