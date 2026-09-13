<?php
/**
 * InsertRedirectCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\InsertRedirectCommand;

/**
 * Integration tests for InsertRedirectCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\InsertRedirectCommand
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class InsertRedirectCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var InsertRedirectCommand
	 */
	private InsertRedirectCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new InsertRedirectCommand( $this->container()->manager() );
	}

	// =========================================================================
	// Tests for basic insert
	// =========================================================================

	/**
	 * Test inserting a redirect to a URL path.
	 */
	public function test_insert_redirect_to_path(): void {
		$this->invoke_command(
			$this->command,
			array( '/old-page', '/new-page' ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Inserted' );
		$this->assert_stdout_contains( '/old-page' );
		$this->assert_stdout_contains( '/new-page' );

		// Verify redirect was created.
		$redirect = $this->container()->inner_repository()->find_by_source(
			\Automattic\LegacyRedirector\Domain\SourceUrl::from_string( '/old-page' )
		);
		$this->assertNotNull( $redirect );
		$this->assertEquals( '/new-page', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test inserting a redirect to a post ID.
	 */
	public function test_insert_redirect_to_post_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->invoke_command(
			$this->command,
			array( '/redirect-to-post', (string) $post_id ),
			array()
		);

		$this->assert_success_contains( 'Inserted' );
		$this->assert_stdout_contains( (string) $post_id );

		// Verify redirect was created with post ID destination.
		$redirect = $this->container()->inner_repository()->find_by_source(
			\Automattic\LegacyRedirector\Domain\SourceUrl::from_string( '/redirect-to-post' )
		);
		$this->assertNotNull( $redirect );
		$this->assertTrue( $redirect->destination()->is_post_id() );
		$this->assertEquals( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test inserting a redirect to a full URL.
	 */
	public function test_insert_redirect_to_full_url(): void {
		// Get the home URL host - it's always allowed.
		$home_url = home_url( '/destination-page' );

		$this->invoke_command(
			$this->command,
			array( '/full-url-redirect', $home_url ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Inserted' );
	}

	// =========================================================================
	// Tests for status option
	// =========================================================================

	/**
	 * Test inserting a disabled redirect.
	 */
	public function test_insert_disabled_redirect(): void {
		$this->invoke_command(
			$this->command,
			array( '/disabled-redirect', '/destination' ),
			array(
				'status'          => 'disabled',
				'skip-validation' => true,
			)
		);

		$this->assert_success_contains( 'Inserted' );
		$this->assert_stdout_contains( '(disabled)' );

		// Verify the redirect was created with draft status.
		$source      = \Automattic\LegacyRedirector\Domain\SourceUrl::from_string( '/disabled-redirect' );
		$redirect_id = $this->container()->inner_repository()->get_id_by_source( $source );
		$post        = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	// =========================================================================
	// Tests for validation
	// =========================================================================

	/**
	 * Test insert with validation enabled rejects non-existent post.
	 */
	public function test_insert_validates_post_exists(): void {
		$this->invoke_command(
			$this->command,
			array( '/invalid-post-redirect', '999999' ),
			array() // No skip-validation.
		);

		$this->assert_command_error();
		$this->assert_stderr_contains( 'post ID does not exist' );
	}

	/**
	 * Test insert with validation enabled rejects unpublished post.
	 */
	public function test_insert_validates_post_is_published(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->invoke_command(
			$this->command,
			array( '/draft-post-redirect', (string) $post_id ),
			array() // No skip-validation.
		);

		$this->assert_command_error();
		$this->assert_stderr_contains( 'not published' );
	}

	/**
	 * Test insert with skip-validation bypasses checks.
	 */
	public function test_insert_skip_validation(): void {
		$this->invoke_command(
			$this->command,
			array( '/skip-validation-test', '/nonexistent/path' ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Inserted' );
	}

	// =========================================================================
	// Tests for error handling
	// =========================================================================

	/**
	 * Test error when source and destination are the same.
	 */
	public function test_insert_rejects_same_source_and_destination(): void {
		$this->invoke_command(
			$this->command,
			array( '/same-path', '/same-path' ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_error();
		$this->assert_stderr_contains( 'should not match' );
	}

	/**
	 * Test error when redirect already exists.
	 */
	public function test_insert_rejects_duplicate(): void {
		// Create an existing redirect.
		$this->create_redirect( '/existing-source', '/some-dest' );

		$this->invoke_command(
			$this->command,
			array( '/existing-source', '/another-dest' ),
			array() // No skip-validation - let it check for duplicates.
		);

		$this->assert_command_error();
		$this->assert_stderr_contains( 'already exists' );
	}

	/**
	 * Test error for invalid source path.
	 */
	public function test_insert_rejects_invalid_source(): void {
		$this->invoke_command(
			$this->command,
			array( '', '/destination' ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_error();
	}

	// =========================================================================
	// Tests for external URL handling
	// =========================================================================

	/**
	 * Test insert rejects external URL not in allowed hosts.
	 */
	public function test_insert_rejects_disallowed_external_host(): void {
		$this->invoke_command(
			$this->command,
			array( '/external-redirect', 'https://not-allowed-domain.com/page' ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_error();
		$this->assert_stderr_contains( 'allowed_redirect_hosts' );
	}

	/**
	 * Test insert allows external URL when host is in allowed list.
	 */
	public function test_insert_allows_whitelisted_external_host(): void {
		// Add filter to allow the external domain.
		add_filter(
			'allowed_redirect_hosts',
			function ( $hosts ) {
				$hosts[] = 'allowed-domain.com';
				return $hosts;
			}
		);

		$this->invoke_command(
			$this->command,
			array( '/allowed-external', 'https://allowed-domain.com/page' ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( 'Inserted' );
	}
}
