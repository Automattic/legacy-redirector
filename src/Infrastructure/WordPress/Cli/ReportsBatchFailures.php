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
 * Reports the identifiers in a batch that did not succeed.
 *
 * Shared by every command that takes a list of redirects, so the same
 * situation always reads the same way, and so a redirect that exists but
 * could not be changed is never reported as one that does not exist.
 */
trait ReportsBatchFailures {

	use CountsInWords;

	/**
	 * Warn about every batch item that did not succeed.
	 *
	 * A lone identifier that failed is an error instead: with nothing else
	 * in the batch, a warning followed by "0 of 1" says the same thing twice.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items The batch items.
	 * @param string                                                                                                    $verb  Verb naming the action that failed, e.g. 'update' or 'delete'.
	 * @return int How many items succeeded.
	 */
	private function report_batch_failures( array $items, string $verb ): int {
		$messages = array();

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
				$messages[] = $message;
			}
		}

		if ( 1 === count( $items ) && array() !== $messages ) {
			WP_CLI::error( $messages[0] );
			return 0;
		}

		foreach ( $messages as $message ) {
			WP_CLI::warning( $message );
		}

		return RedirectBatch::count_succeeded( $items );
	}

	/**
	 * Fail the command if any batch item did not succeed.
	 *
	 * Called once the work and its warnings are done, so a script sees a
	 * non-zero exit whenever part of the batch was not acted on. A lone
	 * identifier has already had its one error.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items      The batch items.
	 * @param string                                                                                                    $verb       Verb naming the action, e.g. 'delete'.
	 * @param string                                                                                                    $past_tense The verb's past tense, e.g. 'deleted'.
	 * @return bool Whether any item failed.
	 */
	private function fail_incomplete_batch( array $items, string $verb, string $past_tense ): bool {
		$total     = count( $items );
		$succeeded = RedirectBatch::count_succeeded( $items );

		if ( $succeeded === $total ) {
			return false;
		}

		if ( $total > 1 ) {
			WP_CLI::error(
				$succeeded > 0
					? sprintf( 'Only %s %d of %s.', $past_tense, $succeeded, self::count_of( $total, 'redirect', 'redirects' ) )
					: sprintf( 'Could not %s any of the %s.', $verb, self::count_of( $total, 'redirect', 'redirects' ) )
			);
		}

		return true;
	}
}
