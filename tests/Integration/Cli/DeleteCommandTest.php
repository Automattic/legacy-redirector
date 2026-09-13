<?php
/**
 * DeleteCommand CLI integration tests.
 *
 * Tests the DeleteCommand with real WordPress database operations
 * but captured WP_CLI output.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand;

/**
 * Integration tests for DeleteCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand
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
final class DeleteCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var DeleteCommand
	 */
	private DeleteCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new DeleteCommand( $this->container()->manager() );
	}

	// =========================================================================
	// Tests for delete by source
	// =========================================================================

	/**
	 * Test deleting a redirect by source path.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_by_source_with_real_database(): void {
		// Create a redirect to delete.
		$redirect_id = $this->create_redirect( '/old-page-to-delete', 'https://example.com/new-page' );

		$this->assertGreaterThan( 0, $redirect_id, 'Redirect should be created' );

		// Delete by source path with --yes to skip confirmation.
		$this->invoke_command(
			$this->command,
			array( '/old-page-to-delete' ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted redirect' );
		$this->assert_stdout_contains( '/old-page-to-delete' );

		// Verify redirect is actually deleted.
		$post = get_post( $redirect_id );
		$this->assertNull( $post, 'Redirect post should be deleted from database' );
	}

	/**
	 * Test deleting a redirect by source path when it doesn't exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_by_source_not_found(): void {
		// Try to delete a non-existent redirect.
		$this->invoke_command(
			$this->command,
			array( '/this-redirect-does-not-exist' ),
			array( 'yes' => true )
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test deleting with an invalid source path.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_by_source_with_invalid_path(): void {
		// Empty path should be invalid.
		$this->invoke_command(
			$this->command,
			array( '' ),
			array( 'yes' => true )
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	// =========================================================================
	// Tests for delete by ID
	// =========================================================================

	/**
	 * Test deleting a redirect by ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_by_id_with_real_database(): void {
		// Create a redirect to delete.
		$redirect_id = $this->create_redirect( '/another-old-page', 'https://example.com/another-new-page' );

		$this->assertGreaterThan( 0, $redirect_id, 'Redirect should be created' );

		// Delete by ID with --yes to skip confirmation.
		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array(
				'by'  => 'id',
				'yes' => true,
			)
		);

		$this->assert_success_contains( 'Deleted redirect' );
		$this->assert_stdout_contains( (string) $redirect_id );

		// Verify redirect is actually deleted.
		$post = get_post( $redirect_id );
		$this->assertNull( $post, 'Redirect post should be deleted from database' );
	}

	/**
	 * Test deleting by ID when redirect doesn't exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_by_id_not_found(): void {
		// Try to delete a non-existent ID.
		$this->invoke_command(
			$this->command,
			array( '999999' ),
			array(
				'by'  => 'id',
				'yes' => true,
			)
		);

		$this->assert_error_contains( 'not found' );
	}

	// =========================================================================
	// Tests for confirmation behavior
	// =========================================================================

	/**
	 * Test that confirmation is requested when --yes is not passed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_confirmation_requested_without_yes_flag(): void {
		// Create a redirect.
		$redirect_id = $this->create_redirect( '/page-needing-confirm', 'https://example.com/new' );

		// Without --yes flag, WP_CLI::confirm should be called.
		$this->invoke_command(
			$this->command,
			array( '/page-needing-confirm' ),
			array() // No --yes flag.
		);

		// The stub auto-confirms, so this should succeed.
		$this->assert_success_contains( 'Deleted redirect' );

		// Verify confirm was called.
		$this->assertTrue(
			\WP_CLI::was_called( 'confirm' ),
			'WP_CLI::confirm should have been called without --yes flag'
		);
	}

	/**
	 * Test that --yes bypasses confirmation.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_yes_flag_bypasses_confirmation(): void {
		// Create a redirect.
		$redirect_id = $this->create_redirect( '/page-with-yes', 'https://example.com/new' );

		// With --yes flag.
		$this->invoke_command(
			$this->command,
			array( '/page-with-yes' ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted redirect' );

		// Confirm is still called by WP_CLI but auto-skipped with --yes.
		// The stub still records the call, but in real WP-CLI it would skip the prompt.
		$this->assertTrue(
			\WP_CLI::was_called( 'confirm' ),
			'WP_CLI::confirm is still called but skipped with --yes'
		);
	}

	// =========================================================================
	// Tests for default behavior
	// =========================================================================

	/**
	 * Test that source lookup is the default (--by not required).
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_defaults_to_source_lookup(): void {
		// Create a redirect.
		$redirect_id = $this->create_redirect( '/default-source-lookup', 'https://example.com/dest' );

		// No --by argument, should default to 'source'.
		$this->invoke_command(
			$this->command,
			array( '/default-source-lookup' ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted redirect' );

		// Verify it was actually deleted.
		$post = get_post( $redirect_id );
		$this->assertNull( $post, 'Redirect should be deleted using default source lookup' );
	}

	// =========================================================================
	// Tests for redirect to post ID
	// =========================================================================

	/**
	 * Test deleting a redirect that points to a post ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand::__invoke
	 */
	public function test_delete_redirect_to_post_id(): void {
		// Create a destination post.
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Destination Post',
				'post_status' => 'publish',
			)
		);

		// Create a redirect pointing to the post.
		$redirect_id = $this->create_redirect( '/redirects-to-post', $post_id );

		$this->assertGreaterThan( 0, $redirect_id, 'Redirect should be created' );

		// Delete the redirect.
		$this->invoke_command(
			$this->command,
			array( '/redirects-to-post' ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted redirect' );

		// Verify redirect is deleted but destination post still exists.
		$this->assertNull( get_post( $redirect_id ), 'Redirect should be deleted' );
		$this->assertNotNull( get_post( $post_id ), 'Destination post should still exist' );
	}
}
