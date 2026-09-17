<?php
/**
 * Batch failure formatting for abilities.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\BatchOutcome;
use Automattic\LegacyRedirector\Domain\Redirect;

/**
 * Turns batch outcomes into the `failed` payload the abilities report.
 *
 * The abilities that act on several redirects at once report per-item
 * outcomes rather than failing the whole call, so a client gets the work that
 * did succeed along with a reason for each item that did not.
 */
final class BatchFailures {

	/**
	 * Format the items that did not succeed.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items          The batch items.
	 * @param string                                                                                                    $failure_reason Reason to report when the action failed without a message of its own.
	 * @return array<int, array{redirect: string, reason: string}> The failures, in the order the identifiers were given.
	 */
	public static function format( array $items, string $failure_reason = '' ): array {
		$failures = array();

		foreach ( $items as $item ) {
			$reason = match ( $item['outcome'] ) {
				BatchOutcome::SUCCESS   => null,
				BatchOutcome::INVALID   => sprintf(
					/* translators: %s: error message. */
					__( 'Not a valid redirect ID or source path (%s).', 'legacy-redirector' ),
					(string) $item['error']
				),
				BatchOutcome::NOT_FOUND => __( 'No redirect found.', 'legacy-redirector' ),
				BatchOutcome::FAILED    => $item['error'] ?? $failure_reason,
			};

			if ( null === $reason ) {
				continue;
			}

			$failures[] = array(
				'redirect' => $item['identifier'],
				'reason'   => $reason,
			);
		}

		return $failures;
	}
}
