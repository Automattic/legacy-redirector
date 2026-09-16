<?php
/**
 * DisableCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand;
use Automattic\LegacyRedirector\Application\RedirectFetcher;

/**
 * Integration tests for DisableCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\AbstractStatusCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
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
			$this->manager(),
			new RedirectFetcher( $this->repository() )
		);
	}

	/**
	 * Test disabling an enabled redirect by source path.
	 */
	public function test_disable_enabled_redirect_by_source(): void {
		$redirect_id = $this->create_redirect( '/disable-me', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/disable-me' ),
			array()
		);

		$this->assert_success_contains( 'Disabled redirect: /disable-me' );
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
	}

	/**
	 * Test disabling a redirect by ID.
	 */
	public function test_disable_by_id(): void {
		$redirect_id = $this->create_redirect( '/disable-by-id', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array()
		);

		$this->assert_command_success();
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
	}

	/**
	 * Test disabling multiple redirects at once.
	 */
	public function test_disable_multiple(): void {
		$first  = $this->create_redirect( '/disable-multi-one', 'https://example.com/a' );
		$second = $this->create_redirect( '/disable-multi-two', 'https://example.com/b' );

		$this->invoke_command(
			$this->command,
			array( '/disable-multi-one', (string) $second ),
			array()
		);

		$this->assert_success_contains( 'Disabled 2 redirects.' );
		$this->assertSame( 'draft', get_post_status( $first ) );
		$this->assertSame( 'draft', get_post_status( $second ) );
	}

	/**
	 * Test error when redirect not found.
	 */
	public function test_disable_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent' ),
			array()
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Redirect not found: /nonexistent' );
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

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Invalid source path' );
	}
}
