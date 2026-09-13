<?php
/**
 * WP_CLI\Utils namespace stubs for unit and integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

namespace WP_CLI\Utils;

if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
	/**
	 * Format items for display.
	 *
	 * @param string $format Output format.
	 * @param array  $items  Items to format.
	 * @param array  $fields Fields to display.
	 * @return void
	 */
	function format_items( string $format, array $items, array $fields ): void {
		// Track calls for unit test assertions.
		if ( ! isset( $GLOBALS['wp_cli_format_items_calls'] ) ) {
			$GLOBALS['wp_cli_format_items_calls'] = array();
		}
		$GLOBALS['wp_cli_format_items_calls'][] = array( $format, $items, $fields );

		// Output items as simple lines for testing.
		foreach ( $items as $item ) {
			$values = array();
			foreach ( $fields as $field ) {
				if ( isset( $item[ $field ] ) ) {
					$values[] = $item[ $field ];
				}
			}
			\WP_CLI::line( implode( "\t", $values ) );
		}
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
	/**
	 * Get the value of an associative argument, with a fallback default.
	 *
	 * @param array  $assoc_args Associative arguments.
	 * @param string $flag       Flag name.
	 * @param mixed  $fallback   Value to return when the flag is absent.
	 * @return mixed The flag value, or the fallback.
	 */
	function get_flag_value( array $assoc_args, string $flag, $fallback = null ) {
		return $assoc_args[ $flag ] ?? $fallback;
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\write_csv' ) ) {
	/**
	 * Write rows to an open file handle as CSV.
	 *
	 * @param resource $fd      File descriptor.
	 * @param array    $rows    Rows to write.
	 * @param array    $headers Optional header row.
	 * @return void
	 */
	function write_csv( $fd, array $rows, array $headers = array() ): void {
		if ( ! empty( $headers ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Test stub writing to a temp file.
			fputcsv( $fd, $headers );
		}

		foreach ( $rows as $row ) {
			if ( ! empty( $headers ) ) {
				$row = array_merge( array_fill_keys( $headers, '' ), $row );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Test stub writing to a temp file.
			fputcsv( $fd, array_values( $row ) );
		}
	}
}

if ( ! function_exists( 'WP_CLI\\Utils\\make_progress_bar' ) ) {
	/**
	 * Create a progress bar.
	 *
	 * @param string $message The message.
	 * @param int    $count   Total count.
	 * @return object Progress bar object with tick() and finish() methods.
	 */
	function make_progress_bar( string $message, int $count ): object {
		// phpcs:ignore Universal.Classes.DisallowAnonClassParentheses.Found -- Required for constructor args.
		return new class( $message, $count ) {
			/**
			 * Constructor.
			 *
			 * @param string $message The message.
			 * @param int    $count   Total count.
			 */
			public function __construct( string $message, int $count ) {
				// No-op for tests.
			}

			/**
			 * Tick the progress bar.
			 *
			 * @return void
			 */
			public function tick(): void {
				// No-op for tests.
			}

			/**
			 * Finish the progress bar.
			 *
			 * @return void
			 */
			public function finish(): void {
				// No-op for tests.
			}
		};
	}
}
