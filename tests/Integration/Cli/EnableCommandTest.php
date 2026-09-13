<?php
/**
 * EnableCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand;

/**
 * Integration tests for EnableCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class EnableCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var EnableCommand
	 */
	private EnableCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new EnableCommand(
			$this->container()->manager(),
			$this->container()->inner_repository()
		);
	}

	// =========================================================================
	// Tests for enable by source
	// =========================================================================

	/**
	 * Test enabling a disabled redirect by source path.
	 */
	public function test_enable_disabled_redirect_by_source(): void {
		$redirect_id = $this->create_redirect( '/disabled-page', 'https://example.com/dest' );

		// Disable the redirect first.
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		// Verify it's disabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );

		// Enable it.
		$this->invoke_command(
			$this->command,
			array( '/disabled-page' ),
			array()
		);

		$this->assert_success_contains( 'Enabled redirect' );

		// Verify it's now enabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	/**
	 * Test enabling an already enabled redirect.
	 */
	public function test_enable_already_enabled_redirect(): void {
		$redirect_id = $this->create_redirect( '/already-enabled', 'https://example.com/dest' );

		// It's already enabled (publish status).
		$this->invoke_command(
			$this->command,
			array( '/already-enabled' ),
			array()
		);

		// Should still succeed (idempotent operation).
		$this->assert_success_contains( 'Enabled redirect' );

		// Verify it's still enabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	/**
	 * Test error when redirect not found by source.
	 */
	public function test_enable_not_found_by_source(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent-page' ),
			array()
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test error for invalid source path.
	 */
	public function test_enable_invalid_source_path(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array()
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	// =========================================================================
	// Tests for enable by ID
	// =========================================================================

	/**
	 * Test enabling a redirect by ID.
	 */
	public function test_enable_by_id(): void {
		$redirect_id = $this->create_redirect( '/enable-by-id', 'https://example.com/dest' );

		// Disable it first.
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array( 'by' => 'id' )
		);

		$this->assert_success_contains( 'Enabled redirect' );

		// Verify it's enabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	/**
	 * Test error when redirect not found by ID.
	 */
	public function test_enable_by_id_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '999999' ),
			array( 'by' => 'id' )
		);

		$this->assert_error_contains( 'Could not enable redirect' );
	}

	// =========================================================================
	// Tests for default behavior
	// =========================================================================

	/**
	 * Test that source lookup is the default.
	 */
	public function test_defaults_to_source_lookup(): void {
		$redirect_id = $this->create_redirect( '/default-enable', 'https://example.com/dest' );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		// No --by argument.
		$this->invoke_command(
			$this->command,
			array( '/default-enable' ),
			array()
		);

		$this->assert_success_contains( 'Enabled redirect' );
	}
}
