<?php
/**
 * Base test case for CLI integration tests.
 *
 * Provides helpers for testing WP-CLI commands with real WordPress
 * database but without full shell execution overhead.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Tests\Integration\TestCase;
use WP_CLI;
use WP_CLI_Command;

/**
 * Base test case for CLI command integration tests.
 *
 * This test case provides:
 * - WP_CLI output capture via the WpCliOutputCapture helper
 * - Command invocation helpers
 * - Assertion helpers for common patterns
 * - Real WordPress database with automatic cleanup
 *
 * Unlike unit tests which mock all dependencies, these integration tests
 * use real WordPress database operations while capturing CLI output.
 */
abstract class CliTestCase extends TestCase {

	/**
	 * The output capture helper.
	 *
	 * @var WpCliOutputCapture
	 */
	protected WpCliOutputCapture $output;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->output = new WpCliOutputCapture();

		// Delete all existing redirects to ensure test isolation.
		$this->delete_all_redirects();
	}

	/**
	 * Delete all redirects from the database.
	 *
	 * Ensures each test starts with a clean slate.
	 *
	 * @return void
	 */
	private function delete_all_redirects(): void {
		$redirects = get_posts(
			array(
				'post_type'      => 'vip-legacy-redirect',
				'post_status'    => array( 'publish', 'draft', 'trash', 'any' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $redirects as $redirect_id ) {
			wp_delete_post( $redirect_id, true );
		}
	}

	/**
	 * Invoke a CLI command directly.
	 *
	 * Captures all WP_CLI output during execution.
	 *
	 * @param WP_CLI_Command $command    The command instance.
	 * @param string[]       $args       Positional arguments.
	 * @param array          $assoc_args Associative arguments.
	 * @return void
	 */
	protected function invoke_command( WP_CLI_Command $command, array $args = array(), array $assoc_args = array() ): void {
		$this->output->reset();
		$this->output->start_capture();

		try {
			$command->__invoke( $args, $assoc_args );
		} catch ( \WP_CLI_Stub_Exit ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- WP_CLI::error() recorded the message; real WP-CLI would have exited here.
		} finally {
			$this->output->stop_capture();
		}
	}

	/**
	 * Get all stdout output as a string.
	 *
	 * @return string
	 */
	protected function get_stdout(): string {
		return $this->output->get_stdout_string();
	}

	/**
	 * Get all stderr output as a string.
	 *
	 * @return string
	 */
	protected function get_stderr(): string {
		return $this->output->get_stderr_string();
	}

	/**
	 * Get combined output (stdout + stderr).
	 *
	 * @return string
	 */
	protected function get_output(): string {
		return $this->output->get_combined_string();
	}

	/**
	 * Assert that the command succeeded.
	 *
	 * @param string $message Optional assertion message.
	 * @return void
	 */
	protected function assert_command_success( string $message = '' ): void {
		$this->assertTrue(
			$this->output->had_success(),
			'' !== $message ? $message : 'Expected command to succeed. Output: ' . $this->get_output()
		);
		$this->assertFalse(
			$this->output->had_error(),
			'' !== $message ? $message : 'Expected no error. Got: ' . $this->get_stderr()
		);
	}

	/**
	 * Assert that the command failed.
	 *
	 * @param string $message Optional assertion message.
	 * @return void
	 */
	protected function assert_command_error( string $message = '' ): void {
		$this->assertTrue(
			$this->output->had_error(),
			'' !== $message ? $message : 'Expected command to fail. Output: ' . $this->get_output()
		);
	}

	/**
	 * Assert that stdout contains a string.
	 *
	 * @param string $expected Expected substring.
	 * @param string $message  Optional assertion message.
	 * @return void
	 */
	protected function assert_stdout_contains( string $expected, string $message = '' ): void {
		$this->assertStringContainsString(
			$expected,
			$this->get_stdout(),
			'' !== $message ? $message : sprintf( 'Expected stdout to contain "%s". Got: %s', $expected, $this->get_stdout() )
		);
	}

	/**
	 * Assert that stderr contains a string.
	 *
	 * @param string $expected Expected substring.
	 * @param string $message  Optional assertion message.
	 * @return void
	 */
	protected function assert_stderr_contains( string $expected, string $message = '' ): void {
		$this->assertStringContainsString(
			$expected,
			$this->get_stderr(),
			'' !== $message ? $message : sprintf( 'Expected stderr to contain "%s". Got: %s', $expected, $this->get_stderr() )
		);
	}

	/**
	 * Assert that stdout does not contain a string.
	 *
	 * @param string $unexpected Unexpected substring.
	 * @param string $message    Optional assertion message.
	 * @return void
	 */
	protected function assert_stdout_not_contains( string $unexpected, string $message = '' ): void {
		$this->assertStringNotContainsString(
			$unexpected,
			$this->get_stdout(),
			'' !== $message ? $message : sprintf( 'Expected stdout to not contain "%s". Got: %s', $unexpected, $this->get_stdout() )
		);
	}

	/**
	 * Assert success message contains text.
	 *
	 * Combines asserting success status and message content.
	 *
	 * @param string $expected Expected substring in success message.
	 * @param string $message  Optional assertion message.
	 * @return void
	 */
	protected function assert_success_contains( string $expected, string $message = '' ): void {
		$this->assert_command_success( $message );
		$this->assert_stdout_contains( $expected, $message );
	}

	/**
	 * Assert error message contains text.
	 *
	 * Combines asserting error status and message content.
	 *
	 * @param string $expected Expected substring in error message.
	 * @param string $message  Optional assertion message.
	 * @return void
	 */
	protected function assert_error_contains( string $expected, string $message = '' ): void {
		$this->assert_command_error( $message );
		$this->assert_stderr_contains( $expected, $message );
	}

	/**
	 * Assert that a warning was output.
	 *
	 * @param string $expected Expected substring in warning message.
	 * @param string $message  Optional assertion message.
	 * @return void
	 */
	protected function assert_warning_contains( string $expected, string $message = '' ): void {
		$this->assertTrue(
			$this->output->had_warning(),
			'' !== $message ? $message : 'Expected a warning. Output: ' . $this->get_output()
		);
		$this->assert_stderr_contains( $expected, $message );
	}
}
