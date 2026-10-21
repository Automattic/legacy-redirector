<?php
/**
 * WP_CLI\Formatter stub for integration tests.
 *
 * Lives apart from WpCliUtilsStubs.php because PHPCS allows one namespace,
 * and either functions or classes, per file.
 *
 * @package Automattic\LegacyRedirector
 */

namespace WP_CLI;

if ( ! class_exists( 'WP_CLI\\Formatter' ) ) {
	/**
	 * Minimal WP_CLI\Formatter stub: records display_item() calls.
	 */
	class Formatter {
		/**
		 * The format, fields, and field arguments, as WP-CLI reads them.
		 *
		 * @var array
		 */
		private array $args;

		/**
		 * Constructor.
		 *
		 * @param array      $assoc_args Associative arguments.
		 * @param array|null $fields     Default fields.
		 */
		public function __construct( array &$assoc_args, ?array $fields = null ) {
			$this->args = array(
				'format' => $assoc_args['format'] ?? 'table',
				'fields' => $assoc_args['fields'] ?? $fields,
				'field'  => $assoc_args['field'] ?? null,
			);
		}

		/**
		 * Display a single item.
		 *
		 * @param array $item The item.
		 * @return void
		 */
		public function display_item( array $item ): void {
			// Track calls for test assertions.
			$GLOBALS['wp_cli_display_item_calls'][] = array( $this->args, $item );

			// Output the field's value, or key and value lines, for testing.
			if ( null !== $this->args['field'] ) {
				\WP_CLI::line( (string) $item[ $this->args['field'] ] );
				return;
			}
			foreach ( $item as $key => $value ) {
				\WP_CLI::line( $key . "\t" . $value );
			}
		}
	}
}
