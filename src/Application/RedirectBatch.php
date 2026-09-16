<?php
/**
 * Redirect batch.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Redirect;

/**
 * Resolves a list of redirect identifiers and acts on each one that resolves.
 *
 * Every caller that works on several redirects at once - the CLI commands and
 * the abilities - shares this loop, so a batch reports the work that did
 * succeed alongside a per-item reason for the work that did not, and so
 * "could not be resolved" and "the action failed" cannot be conflated by
 * whichever transport happens to report them.
 *
 * Outcomes come back per item, in the order the identifiers were given, with
 * no user-facing wording: each transport formats them its own way.
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
	 * @return array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> One item per identifier.
	 */
	public function resolve( array $identifiers ): array {
		$items = array();

		foreach ( $identifiers as $identifier ) {
			$identifier = (string) $identifier;

			try {
				$redirect = $this->fetcher->fetch( $identifier );
			} catch ( \InvalidArgumentException $e ) {
				$items[] = self::item( $identifier, BatchOutcome::INVALID, null, $e->getMessage() );
				continue;
			}

			$items[] = null === $redirect
				? self::item( $identifier, BatchOutcome::NOT_FOUND )
				: self::item( $identifier, BatchOutcome::SUCCESS, $redirect );
		}

		return $items;
	}

	/**
	 * Resolve identifiers and act on each redirect that resolves.
	 *
	 * An action that returns a RedirectCreationResult carries its own error
	 * message through to the item, so a transport can report why the change
	 * failed rather than only that it did.
	 *
	 * @param array<int, string|int> $identifiers Redirect IDs or source paths.
	 * @param callable               $action      Receives a Redirect, returns true or a successful RedirectCreationResult when the change was made.
	 * @return array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> One item per identifier.
	 */
	public function apply( array $identifiers, callable $action ): array {
		$items = $this->resolve( $identifiers );

		foreach ( $items as $index => $item ) {
			if ( BatchOutcome::SUCCESS !== $item['outcome'] ) {
				continue;
			}

			$outcome = $action( $item['redirect'] );
			$success = $outcome instanceof RedirectCreationResult ? $outcome->is_success() : (bool) $outcome;

			if ( $success ) {
				continue;
			}

			$error           = $outcome instanceof RedirectCreationResult ? $outcome->error_message() : null;
			$items[ $index ] = self::item( $item['identifier'], BatchOutcome::FAILED, $item['redirect'], $error );
		}

		return $items;
	}

	/**
	 * Count the items that succeeded.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items The batch items.
	 * @return int How many items succeeded.
	 */
	public static function count_succeeded( array $items ): int {
		$succeeded = array_filter(
			$items,
			static fn( array $item ): bool => BatchOutcome::SUCCESS === $item['outcome']
		);

		return count( $succeeded );
	}

	/**
	 * The redirects from the items that succeeded.
	 *
	 * @param array<int, array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}> $items The batch items.
	 * @return Redirect[] The resolved redirects, in the order given.
	 */
	public static function redirects( array $items ): array {
		$succeeded = array_filter(
			$items,
			static fn( array $item ): bool => BatchOutcome::SUCCESS === $item['outcome']
		);

		return array_values( array_column( $succeeded, 'redirect' ) );
	}

	/**
	 * Build one batch item.
	 *
	 * @param string        $identifier The identifier as given.
	 * @param BatchOutcome  $outcome    What happened to it.
	 * @param Redirect|null $redirect   The redirect, when it resolved.
	 * @param string|null   $error      The underlying error message, when there is one.
	 * @return array{identifier: string, outcome: BatchOutcome, redirect: Redirect|null, error: string|null}
	 */
	private static function item( string $identifier, BatchOutcome $outcome, ?Redirect $redirect = null, ?string $error = null ): array {
		return array(
			'identifier' => $identifier,
			'outcome'    => $outcome,
			'redirect'   => $redirect,
			'error'      => $error,
		);
	}
}
