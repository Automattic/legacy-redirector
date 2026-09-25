<?php
/**
 * DeleteCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;

/**
 * Integration tests for DeleteCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
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

		$this->command = new DeleteCommand(
			$this->manager(),
			new RedirectBatch( new RedirectFetcher( $this->repository() ) )
		);
	}

	/**
	 * Test deleting a redirect by source path.
	 */
	public function test_delete_by_source(): void {
		$redirect_id = $this->create_redirect( '/delete-me', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/delete-me' ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted redirect: /delete-me' );
		$this->assertNull( get_post( $redirect_id ) );
	}

	/**
	 * Test deleting a redirect by ID.
	 */
	public function test_delete_by_id(): void {
		$redirect_id = $this->create_redirect( '/delete-by-id', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array( 'yes' => true )
		);

		$this->assert_command_success();
		$this->assertNull( get_post( $redirect_id ) );
	}

	/**
	 * Test deleting multiple redirects at once.
	 */
	public function test_delete_multiple(): void {
		$first  = $this->create_redirect( '/multi-one', 'https://example.com/a' );
		$second = $this->create_redirect( '/multi-two', 'https://example.com/b' );

		$this->invoke_command(
			$this->command,
			array( '/multi-one', (string) $second ),
			array( 'yes' => true )
		);

		$this->assert_success_contains( 'Deleted 2 redirects.' );
		$this->assertNull( get_post( $first ) );
		$this->assertNull( get_post( $second ) );
	}

	/**
	 * Test error when redirect not found.
	 */
	public function test_delete_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent' ),
			array( 'yes' => true )
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Redirect not found: /nonexistent' );
	}

	/**
	 * Test warning for invalid source path.
	 */
	public function test_delete_invalid_path(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array( 'yes' => true )
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Invalid source path' );
	}

	/**
	 * Test a mixed batch reports partial failure but still deletes valid targets.
	 */
	public function test_delete_partial_failure(): void {
		$redirect_id = $this->create_redirect( '/partial-valid', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/partial-valid', '/partial-missing' ),
			array( 'yes' => true )
		);

		$this->assert_error_contains( 'Only deleted 1 of 2 redirects.' );
		$this->assertNull( get_post( $redirect_id ) );
	}

	/**
	 * Test that a confirmation prompt is issued.
	 */
	public function test_confirmation_requested(): void {
		$this->create_redirect( '/confirm-me', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/confirm-me' ),
			array()
		);

		// The stub records the confirm call; a real run would prompt.
		$this->assertTrue( \WP_CLI::was_called( 'confirm' ) );
	}
}
