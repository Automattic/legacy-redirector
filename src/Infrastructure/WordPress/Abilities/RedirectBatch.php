<?php
/**
 * Batch identifier resolution for abilities.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectFetcher;

/**
 * Resolves a list of redirect identifiers, reporting the ones that fail.
 *
 * The abilities that act on several redirects at once report per-item
 * outcomes rather than failing the whole call, so that a client gets the
 * work that did succeed along with a reason for each item that did not.
 */
final class RedirectBatch {

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	private RedirectFetcher $fetcher;

	/**
	 * Constructor.
	 *
	 * @param RedirectFetcher $fetcher The redirect fetcher.
	 */
	public function __construct( RedirectFetcher $fetcher ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Resolve identifiers to redirects.
	 *
	 * @param array<int, string|int> $identifiers Redirect IDs or source paths.
	 * @return array{resolved: array<int, array{identifier: string, redirect: \Automattic\LegacyRedirector\Domain\Redirect}>, failures: array<int, array{redirect: string, reason: string}>}
	 */
	public function resolve( array $identifiers ): array {
		$resolved = array();
		$failures = array();

		foreach ( $identifiers as $identifier ) {
			$identifier = (string) $identifier;

			try {
				$redirect = $this->fetcher->fetch( $identifier );
			} catch ( \InvalidArgumentException $e ) {
				$failures[] = array(
					'redirect' => $identifier,
					'reason'   => sprintf(
						/* translators: %s: error message. */
						__( 'Not a valid redirect ID or source path (%s).', 'wpcom-legacy-redirector' ),
						$e->getMessage()
					),
				);
				continue;
			}

			if ( null === $redirect ) {
				$failures[] = array(
					'redirect' => $identifier,
					'reason'   => __( 'No redirect found.', 'wpcom-legacy-redirector' ),
				);
				continue;
			}

			$resolved[] = array(
				'identifier' => $identifier,
				'redirect'   => $redirect,
			);
		}

		return array(
			'resolved' => $resolved,
			'failures' => $failures,
		);
	}

	/**
	 * Resolve identifiers and act on each redirect that resolves.
	 *
	 * @param array<int, string|int> $identifiers    Redirect IDs or source paths.
	 * @param callable               $action         Receives a Redirect, returns true or a successful RedirectCreationResult when the change was made.
	 * @param string                 $failure_reason Reason to report when the action fails without a message of its own.
	 * @return array{changed: int, failures: array<int, array{redirect: string, reason: string}>}
	 */
	public function apply( array $identifiers, callable $action, string $failure_reason ): array {
		$batch    = $this->resolve( $identifiers );
		$failures = $batch['failures'];
		$changed  = 0;

		foreach ( $batch['resolved'] as $item ) {
			$outcome = $action( $item['redirect'] );
			$success = $outcome instanceof RedirectCreationResult ? $outcome->is_success() : (bool) $outcome;

			if ( ! $success ) {
				$reason     = $outcome instanceof RedirectCreationResult ? $outcome->error_message() : null;
				$failures[] = array(
					'redirect' => $item['identifier'],
					'reason'   => $reason ?? $failure_reason,
				);
				continue;
			}

			++$changed;
		}

		return array(
			'changed'  => $changed,
			'failures' => $failures,
		);
	}
}
