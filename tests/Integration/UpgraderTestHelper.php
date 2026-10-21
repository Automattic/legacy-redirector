<?php
/**
 * Upgrader test helper trait.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Scaffolding shared by the tests that drive the Upgrader.
 *
 * Expects the using class to hold the upgrader under test in $this->upgrader.
 */
trait UpgraderTestHelper {

	/**
	 * Run a callback with every query starting with a prefix refused.
	 *
	 * Blanks the query, which is how a refused one looks to $wpdb: the call
	 * returns false, or an empty result for a read.
	 *
	 * @param string   $prefix   The start of the queries to refuse.
	 * @param callable $callback The code to run.
	 * @return mixed What the callback returned.
	 */
	private function with_queries_refused( string $prefix, callable $callback ): mixed {
		$refuse = static fn( string $query ): string => str_starts_with( $query, $prefix ) ? '' : $query;

		add_filter( 'query', $refuse );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $refuse );
		}
	}

	/**
	 * Run a callback with every per-row redirect write refused.
	 *
	 * Blanks the UPDATE $wpdb->update() builds (its table name is quoted),
	 * leaving the bulk publish, which is not, alone.
	 *
	 * @param callable $callback The code to run.
	 * @return mixed What the callback returned.
	 */
	private function with_row_writes_refused( callable $callback ): mixed {
		global $wpdb;

		return $this->with_queries_refused( "UPDATE `{$wpdb->posts}`", $callback );
	}

	/**
	 * Run a callback with queries refused, and return the exception that stopped it.
	 *
	 * Fails the test if nothing was thrown.
	 *
	 * @param string   $prefix   The start of the queries to refuse.
	 * @param callable $callback The code to run.
	 * @return \RuntimeException What stopped it.
	 */
	private function refusal_stops( string $prefix, callable $callback ): \RuntimeException {
		try {
			$this->with_queries_refused( $prefix, $callback );
		} catch ( \RuntimeException $e ) {
			return $e;
		}

		$this->fail( 'The refused query should have stopped the run.' );
	}

	/**
	 * Run batches until the upgrade completes, adding up their totals.
	 *
	 * @param int $size The batch size.
	 * @return array<string, mixed> The summed counts, and every batch's conflicts and failures.
	 */
	private function run_to_completion( int $size = Upgrader::BATCH_SIZE ): array {
		$totals = array();

		do {
			$batch = $this->upgrader->run_batch( $size );

			foreach ( $batch as $key => $value ) {
				if ( is_int( $value ) ) {
					$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + $value;
				} elseif ( is_array( $value ) ) {
					$totals[ $key ] = array_merge( $totals[ $key ] ?? array(), $value );
				}
			}
		} while ( ! $batch['complete'] );

		return $totals;
	}
}
