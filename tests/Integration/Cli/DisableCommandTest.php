<?php
/**
 * DisableCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand;

/**
 * Integration tests for DisableCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class DisableCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var DisableCommand
	 */
	private DisableCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new DisableCommand(
			$this->container()->manager(),
			$this->container()->inner_repository()
		);
	}

	// =========================================================================
	// Tests for disable by source
	// =========================================================================

	/**
	 * Test disabling an enabled redirect by source path.
	 */
	public function test_disable_enabled_redirect_by_source(): void {
		$redirect_id = $this->create_redirect( '/enabled-page', 'https://example.com/dest' );

		// Verify it's enabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'publish', $post->post_status );

		// Disable it.
		$this->invoke_command(
			$this->command,
			array( '/enabled-page' ),
			array()
		);

		$this->assert_success_contains( 'Disabled redirect' );

		// Verify it's now disabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	/**
	 * Test disabling an already disabled redirect.
	 */
	public function test_disable_already_disabled_redirect(): void {
		$redirect_id = $this->create_redirect( '/already-disabled', 'https://example.com/dest' );

		// Disable it first.
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command(
			$this->command,
			array( '/already-disabled' ),
			array()
		);

		// Should still succeed (idempotent operation).
		$this->assert_success_contains( 'Disabled redirect' );

		// Verify it's still disabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	/**
	 * Test error when redirect not found by source.
	 */
	public function test_disable_not_found_by_source(): void {
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
	public function test_disable_invalid_source_path(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array()
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	// =========================================================================
	// Tests for disable by ID
	// =========================================================================

	/**
	 * Test disabling a redirect by ID.
	 */
	public function test_disable_by_id(): void {
		$redirect_id = $this->create_redirect( '/disable-by-id', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array( 'by' => 'id' )
		);

		$this->assert_success_contains( 'Disabled redirect' );

		// Verify it's disabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	/**
	 * Test error when redirect not found by ID.
	 */
	public function test_disable_by_id_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '999999' ),
			array( 'by' => 'id' )
		);

		$this->assert_error_contains( 'Could not disable redirect' );
	}

	// =========================================================================
	// Tests for default behavior
	// =========================================================================

	/**
	 * Test that source lookup is the default.
	 */
	public function test_defaults_to_source_lookup(): void {
		$this->create_redirect( '/default-disable', 'https://example.com/dest' );

		// No --by argument.
		$this->invoke_command(
			$this->command,
			array( '/default-disable' ),
			array()
		);

		$this->assert_success_contains( 'Disabled redirect' );
	}
}
