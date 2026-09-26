<?php
/**
 * EnableCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;

/**
 * Integration tests for EnableCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\AbstractStatusCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
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
			$this->manager(),
			new RedirectBatch( new RedirectFetcher( $this->repository() ) )
		);
	}

	/**
	 * Create a disabled redirect.
	 *
	 * @param string $from The source path.
	 * @return int The redirect ID.
	 */
	private function create_disabled_redirect( string $from ): int {
		$redirect_id = $this->create_redirect( $from, 'https://example.com/dest' );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		return $redirect_id;
	}

	/**
	 * Test enabling a disabled redirect by source path.
	 */
	public function test_enable_disabled_redirect_by_source(): void {
		$redirect_id = $this->create_disabled_redirect( '/enable-me' );

		$this->invoke_command(
			$this->command,
			array( '/enable-me' ),
			array()
		);

		$this->assert_success_contains( 'Enabled redirect: /enable-me' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test enabling a redirect by ID.
	 */
	public function test_enable_by_id(): void {
		$redirect_id = $this->create_disabled_redirect( '/enable-by-id' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array()
		);

		$this->assert_command_success();
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test enabling multiple redirects at once.
	 */
	public function test_enable_multiple(): void {
		$first  = $this->create_disabled_redirect( '/enable-multi-one' );
		$second = $this->create_disabled_redirect( '/enable-multi-two' );

		$this->invoke_command(
			$this->command,
			array( '/enable-multi-one', '/enable-multi-two' ),
			array()
		);

		$this->assert_success_contains( 'Enabled 2 redirects.' );
		$this->assertSame( 'publish', get_post_status( $first ) );
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	/**
	 * Test enabling an already-enabled redirect succeeds.
	 */
	public function test_enable_already_enabled_redirect(): void {
		$redirect_id = $this->create_redirect( '/already-enabled', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/already-enabled' ),
			array()
		);

		$this->assert_command_success();
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test error when redirect not found.
	 */
	public function test_enable_not_found(): void {
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
	public function test_enable_invalid_source_path(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array()
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Invalid source path' );
	}
}
