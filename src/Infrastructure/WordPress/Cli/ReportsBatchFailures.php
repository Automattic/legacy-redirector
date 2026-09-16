<?php
/**
 * Batch failure reporting for CLI commands.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\BatchOutcome;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;

/**
 * Warns about the identifiers in a batch that did not succeed.
 *
 * Shared by every command that takes a list of redirects, so the same
 * situation always reads the same way, and so a redirect that exists but
 * could not be changed is never reported as one that does not exist.
 */
trait ReportsBatchFailures {

	/**
	 * Warn about every batch item that did not succeed.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items The batch items.
	 * @param string                                                                                                    $verb  Verb naming the action that failed, e.g. 'update' or 'delete'.
	 * @return int How many items succeeded.
	 */
	private function report_batch_failures( array $items, string $verb ): int {
		foreach ( $items as $item ) {
			$identifier = $item['identifier'];
			$error      = $item['error'];

			$message = match ( $item['outcome'] ) {
				BatchOutcome::SUCCESS   => null,
				BatchOutcome::INVALID   => sprintf( 'Invalid source path: %s (%s)', $identifier, (string) $error ),
				BatchOutcome::NOT_FOUND => sprintf( 'Redirect not found: %s', $identifier ),
				BatchOutcome::FAILED    => sprintf(
					'Could not %s redirect: %s%s',
					$verb,
					$identifier,
					null === $error ? '' : sprintf( ' (%s)', $error )
				),
			};

			if ( null !== $message ) {
				WP_CLI::warning( $message );
			}
		}

		return RedirectBatch::count_succeeded( $items );
	}
}
