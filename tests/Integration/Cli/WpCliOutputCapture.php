<?php
/**
 * WP_CLI output capture helper for integration tests.
 *
 * Intercepts WP_CLI output methods to capture what would be displayed
 * to the user during command execution.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

/**
 * Helper class to capture WP_CLI output during command execution.
 *
 * For integration tests, we load the WP_CLI stub from WordPressStubs.php
 * which provides a minimal testable WP_CLI implementation. This class
 * wraps the stub's call tracking to provide a cleaner API for tests.
 *
 * @see \WP_CLI (stub in tests/Stubs/WordPressStubs.php)
 */
class WpCliOutputCapture {

	/**
	 * Reset WP_CLI call tracking and start fresh capture.
	 *
	 * @return void
	 */
	public function reset(): void {
		if ( class_exists( '\WP_CLI', false ) && method_exists( '\WP_CLI', 'reset' ) ) {
			\WP_CLI::reset();
		}

		$GLOBALS['wp_cli_format_items_calls'] = array();
	}

	/**
	 * Start capturing (alias for reset for API consistency).
	 *
	 * @return void
	 */
	public function start_capture(): void {
		$this->reset();
	}

	/**
	 * Stop capturing (no-op, but here for API consistency).
	 *
	 * @return void
	 */
	public function stop_capture(): void {
		// Nothing to do - the stub stores calls statically.
	}

	/**
	 * Get stdout as a single string.
	 *
	 * Includes: line, log and success messages.
	 *
	 * @return string
	 */
	public function get_stdout_string(): string {
		$lines = array();

		if ( ! class_exists( '\WP_CLI', false ) || ! property_exists( '\WP_CLI', 'calls' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Static property in stub.
		foreach ( \WP_CLI::$calls as $call ) {
			$method = $call[0];
			if ( in_array( $method, array( 'line', 'log' ), true ) ) {
				$lines[] = $call[1] ?? '';
			} elseif ( 'success' === $method ) {
				$lines[] = 'Success: ' . ( $call[1] ?? '' );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get stderr as a single string.
	 *
	 * Includes: warning and error messages, which real WP-CLI writes to STDERR.
	 *
	 * @return string
	 */
	public function get_stderr_string(): string {
		$lines = array();

		if ( ! class_exists( '\WP_CLI', false ) || ! property_exists( '\WP_CLI', 'calls' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Static property in stub.
		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'warning' === $call[0] ) {
				$lines[] = 'Warning: ' . ( $call[1] ?? '' );
			} elseif ( 'error' === $call[0] ) {
				$lines[] = 'Error: ' . ( $call[1] ?? '' );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get combined output as a single string.
	 *
	 * @return string
	 */
	public function get_combined_string(): string {
		$stdout = $this->get_stdout_string();
		$stderr = $this->get_stderr_string();

		if ( '' === $stdout && '' === $stderr ) {
			return '';
		}

		return trim( $stdout . "\n" . $stderr );
	}

	/**
	 * Check if an error occurred.
	 *
	 * @return bool
	 */
	public function had_error(): bool {
		return class_exists( '\WP_CLI', false )
			&& method_exists( '\WP_CLI', 'was_called' )
			&& \WP_CLI::was_called( 'error' );
	}

	/**
	 * Check if success occurred.
	 *
	 * @return bool
	 */
	public function had_success(): bool {
		return class_exists( '\WP_CLI', false )
			&& method_exists( '\WP_CLI', 'was_called' )
			&& \WP_CLI::was_called( 'success' );
	}

	/**
	 * Check if a warning occurred.
	 *
	 * @return bool
	 */
	public function had_warning(): bool {
		return class_exists( '\WP_CLI', false )
			&& method_exists( '\WP_CLI', 'was_called' )
			&& \WP_CLI::was_called( 'warning' );
	}
}
