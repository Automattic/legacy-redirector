<?php
/**
 * WordPress class stubs for unit tests.
 *
 * These stubs provide minimal implementations of WordPress classes
 * needed for unit testing. They are kept separate to allow tests
 * to control when they're loaded.
 *
 * @package Automattic\LegacyRedirector
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Stubs file with multiple WP class stubs.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs may have unused parameters to match original signatures.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Stubs match WP_CLI's original signatures.

// WordPress constants for unit tests.
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// WP_Post stub for unit tests.
if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub for unit testing.
	 */
	class WP_Post {
		/**
		 * The post ID.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * The post title.
		 *
		 * @var string
		 */
		public string $post_title = '';

		/**
		 * The post excerpt.
		 *
		 * @var string
		 */
		public string $post_excerpt = '';

		/**
		 * The post parent.
		 *
		 * @var int
		 */
		public int $post_parent = 0;

		/**
		 * The post status.
		 *
		 * @var string
		 */
		public string $post_status = 'publish';

		/**
		 * Constructor.
		 *
		 * @param array|string $args Post data array, or post_status string for backward compatibility.
		 */
		public function __construct( $args = array() ) {
			// Backward compatibility: accept a string for post_status.
			if ( is_string( $args ) ) {
				$this->post_status = $args;
				return;
			}

			foreach ( $args as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}

		/**
		 * Create a WP_Post from an array of data.
		 *
		 * @param array $data Post data.
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self( $data );
		}
	}
}

// WP_CLI_Command stub for unit tests.
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal WP_CLI_Command stub for unit testing.
	 *
	 * WP_CLI commands extend this base class.
	 */
	class WP_CLI_Command {
		// Empty base class - WP_CLI commands extend this.
	}
}

// WP_CLI stub for unit tests.
if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP_CLI stub for unit testing.
	 *
	 * Collects method calls for assertion in tests.
	 */
	class WP_CLI {
		/**
		 * Tracks all method calls.
		 *
		 * @var array
		 */
		public static array $calls = array();

		/**
		 * Reset the call tracker.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$calls = array();
		}

		/**
		 * Record a success message.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function success( string $message ): void {
			self::$calls[] = array( 'success', $message );
		}

		/**
		 * Record an error message.
		 *
		 * @param string $message The message.
		 * @param bool   $exit    Whether to exit (ignored in stub).
		 * @return void
		 */
		public static function error( string $message, bool $exit = true ): void {
			self::$calls[] = array( 'error', $message );
		}

		/**
		 * Record a warning message.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function warning( string $message ): void {
			self::$calls[] = array( 'warning', $message );
		}

		/**
		 * Record a line of output.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function line( string $message = '' ): void {
			self::$calls[] = array( 'line', $message );
		}

		/**
		 * Record a log message.
		 *
		 * @param string $message The message.
		 * @return void
		 */
		public static function log( string $message ): void {
			self::$calls[] = array( 'log', $message );
		}

		/**
		 * Strip WP-CLI color tokens (e.g. %G, %n) from a string.
		 *
		 * @param string $string The string to colorize.
		 * @return string The string without color tokens.
		 */
		public static function colorize( string $string ): string {
			return (string) preg_replace( '/%[a-zA-Z0-9]/', '', $string );
		}

		/**
		 * Record a debug message.
		 *
		 * @param string $message The message.
		 * @param string $group   Debug group.
		 * @return void
		 */
		public static function debug( string $message, string $group = '' ): void {
			self::$calls[] = array( 'debug', $message, $group );
		}

		/**
		 * Confirm prompt (always returns true in stub).
		 *
		 * @param string $question   The question.
		 * @param array  $assoc_args Associative arguments.
		 * @return void
		 */
		public static function confirm( string $question, array $assoc_args = array() ): void {
			self::$calls[] = array( 'confirm', $question );
			// In tests, this is effectively a no-op - always proceeds.
		}

		/**
		 * Get a call by method name.
		 *
		 * @param string $method The method name.
		 * @return array|null The call data, or null if not found.
		 */
		public static function get_call( string $method ): ?array {
			foreach ( self::$calls as $call ) {
				if ( $call[0] === $method ) {
					return $call;
				}
			}
			return null;
		}

		/**
		 * Get all calls of a specific method.
		 *
		 * @param string $method The method name.
		 * @return array Array of calls.
		 */
		public static function get_calls( string $method ): array {
			$result = array();
			foreach ( self::$calls as $call ) {
				if ( $call[0] === $method ) {
					$result[] = $call;
				}
			}
			return $result;
		}

		/**
		 * Check if a method was called.
		 *
		 * @param string $method The method name.
		 * @return bool True if called.
		 */
		public static function was_called( string $method ): bool {
			return null !== self::get_call( $method );
		}
	}
}

// WP_Query stub for unit tests.
if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub for unit testing.
	 *
	 * Captures query arguments and returns configurable results.
	 */
	class WP_Query {
		/**
		 * The posts returned by the query.
		 *
		 * @var array
		 */
		public array $posts = array();

		/**
		 * The total number of found posts.
		 *
		 * @var int
		 */
		public int $found_posts = 0;

		/**
		 * Captured query args for testing.
		 *
		 * @var array|null
		 */
		public static ?array $last_args = null;

		/**
		 * Posts to return (set before instantiation).
		 *
		 * @var array
		 */
		public static array $mock_posts = array();

		/**
		 * Found posts count to return (set before instantiation).
		 *
		 * @var int
		 */
		public static int $mock_found_posts = 0;

		/**
		 * Constructor.
		 *
		 * @param array $args Query arguments.
		 */
		public function __construct( array $args = array() ) {
			self::$last_args   = $args;
			$this->posts       = self::$mock_posts;
			$this->found_posts = self::$mock_found_posts;
		}

		/**
		 * Reset the mock data.
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$last_args        = null;
			self::$mock_posts       = array();
			self::$mock_found_posts = 0;
		}

		/**
		 * Configure mock results.
		 *
		 * @param array $posts       Posts to return.
		 * @param int   $found_posts Total found posts.
		 * @return void
		 */
		public static function mock_results( array $posts, int $found_posts ): void {
			self::$mock_posts       = $posts;
			self::$mock_found_posts = $found_posts;
		}
	}
}

// WP_Error stub for unit tests.
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub for unit testing.
	 */
	class WP_Error {
		/**
		 * The error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * The error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
