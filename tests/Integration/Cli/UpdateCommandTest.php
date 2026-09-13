<?php
/**
 * UpdateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand;

/**
 * Integration tests for UpdateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class UpdateCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var UpdateCommand
	 */
	private UpdateCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new UpdateCommand( $this->container()->manager() );
	}

	// =========================================================================
	// Tests for updating destination
	// =========================================================================

	/**
	 * Test updating redirect destination to a new URL.
	 */
	public function test_update_destination_to_url(): void {
		$redirect_id = $this->create_redirect( '/update-test', 'https://example.com/old-dest' );

		$this->invoke_command(
			$this->command,
			array( '/update-test', 'https://example.com/new-dest' ),
			array()
		);

		$this->assert_success_contains( 'Updated' );
		$this->assert_stdout_contains( '/update-test' );
		$this->assert_stdout_contains( 'https://example.com/new-dest' );

		// Verify the destination was actually updated.
		$redirect = $this->container()->inner_repository()->find_by_id( $redirect_id );
		$this->assertEquals( 'https://example.com/new-dest', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test updating redirect destination to a relative path.
	 */
	public function test_update_destination_to_path(): void {
		$redirect_id = $this->create_redirect( '/path-update', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/path-update', '/new-path' ),
			array()
		);

		$this->assert_success_contains( 'Updated' );

		// Verify the destination was updated.
		$redirect = $this->container()->inner_repository()->find_by_id( $redirect_id );
		$this->assertEquals( '/new-path', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test updating redirect destination to a post ID.
	 */
	public function test_update_destination_to_post_id(): void {
		$redirect_id = $this->create_redirect( '/post-update', 'https://example.com/old' );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->invoke_command(
			$this->command,
			array( '/post-update', (string) $post_id ),
			array()
		);

		$this->assert_success_contains( 'Updated' );
		$this->assert_stdout_contains( (string) $post_id );

		// Verify the destination was updated.
		$redirect = $this->container()->inner_repository()->find_by_id( $redirect_id );
		$this->assertTrue( $redirect->destination()->is_post_id() );
		$this->assertEquals( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test updating from post ID destination to URL destination.
	 */
	public function test_update_from_post_to_url(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/post-to-url', $post_id );

		$this->invoke_command(
			$this->command,
			array( '/post-to-url', 'https://example.com/new-url' ),
			array()
		);

		$this->assert_success_contains( 'Updated' );

		// Verify the destination type changed.
		$redirect = $this->container()->inner_repository()->find_by_id( $redirect_id );
		$this->assertFalse( $redirect->destination()->is_post_id() );
		$this->assertEquals( 'https://example.com/new-url', $redirect->destination()->as_url()->value() );
	}

	// =========================================================================
	// Tests for status changes
	// =========================================================================

	/**
	 * Test updating destination and disabling redirect.
	 */
	public function test_update_with_status_disabled(): void {
		$redirect_id = $this->create_redirect( '/status-test', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/status-test', 'https://example.com/new' ),
			array( 'status' => 'disabled' )
		);

		$this->assert_success_contains( 'Updated' );
		$this->assert_stdout_contains( 'status: disabled' );

		// Verify the status was changed.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	/**
	 * Test updating destination and explicitly setting status to enabled.
	 *
	 * Note: update_by_source only works on enabled redirects.
	 * This test verifies the --status=enabled flag works on an already-enabled redirect.
	 */
	public function test_update_with_status_enabled(): void {
		$redirect_id = $this->create_redirect( '/enable-status', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/enable-status', 'https://example.com/new' ),
			array( 'status' => 'enabled' )
		);

		$this->assert_success_contains( 'Updated' );
		$this->assert_stdout_contains( 'status: enabled' );

		// Verify the redirect is still enabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	// =========================================================================
	// Tests for error handling
	// =========================================================================

	/**
	 * Test error when redirect not found.
	 */
	public function test_update_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent', 'https://example.com/dest' ),
			array()
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test error for invalid source path.
	 */
	public function test_update_invalid_source(): void {
		$this->invoke_command(
			$this->command,
			array( '', 'https://example.com/dest' ),
			array()
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	/**
	 * Test error for invalid destination.
	 */
	public function test_update_invalid_destination(): void {
		$this->create_redirect( '/invalid-dest-test', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/invalid-dest-test', '' ),
			array()
		);

		$this->assert_error_contains( 'Invalid destination' );
	}
}
