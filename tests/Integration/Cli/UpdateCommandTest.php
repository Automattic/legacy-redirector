<?php
/**
 * UpdateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand;

/**
 * Integration tests for UpdateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
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

		$this->command = new UpdateCommand(
			$this->manager(),
			new RedirectFetcher( $this->repository() )
		);
	}

	/**
	 * Get the stored destination for a source path.
	 *
	 * @param string $from The source path.
	 * @return string|int The destination.
	 */
	private function get_destination( string $from ) { // phpcs:ignore NeutronStandard.Functions.TypeHint.NoReturnType -- Mixed return.
		$repository = $this->repository();
		$redirect   = $repository->find_by_id(
			$repository->get_id_by_source( SourceUrl::from_string( $from ) )
		);

		$dest = $redirect->destination();

		return $dest->is_post_id() ? $dest->as_post_id()->value() : $dest->as_url()->value();
	}

	/**
	 * Test updating a redirect's destination to a URL.
	 */
	public function test_update_destination_to_url(): void {
		$this->create_redirect( '/update-me', 'https://example.com/old-dest' );

		$this->invoke_command(
			$this->command,
			array( '/update-me' ),
			array( 'to' => 'https://example.com/new-dest' )
		);

		$this->assert_success_contains( 'Updated redirect: /update-me' );
		$this->assertSame( 'https://example.com/new-dest', $this->get_destination( '/update-me' ) );
	}

	/**
	 * Test updating a redirect's destination to a post ID.
	 */
	public function test_update_destination_to_post_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/update-to-post', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/update-to-post' ),
			array( 'to' => (string) $post_id )
		);

		$this->assert_command_success();
		$this->assertSame( $post_id, $this->get_destination( '/update-to-post' ) );
	}

	/**
	 * Test updating a redirect by ID.
	 */
	public function test_update_by_id(): void {
		$redirect_id = $this->create_redirect( '/update-by-id', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array( 'to' => '/new-path' )
		);

		$this->assert_command_success();
		$this->assertSame( '/new-path', $this->get_destination( '/update-by-id' ) );
	}

	/**
	 * Test updating destination and status together.
	 */
	public function test_update_with_status_disabled(): void {
		$redirect_id = $this->create_redirect( '/update-disable', 'https://example.com/old' );

		$this->invoke_command(
			$this->command,
			array( '/update-disable' ),
			array(
				'to'     => 'https://example.com/new',
				'status' => 'disabled',
			)
		);

		$this->assert_command_success();
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
		$this->assertSame( 'https://example.com/new', $this->get_destination( '/update-disable' ) );
	}

	/**
	 * Test updating status only.
	 */
	public function test_update_status_only(): void {
		$redirect_id = $this->create_redirect( '/update-status-only', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/update-status-only' ),
			array( 'status' => 'disabled' )
		);

		$this->assert_command_success();
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
		// Destination unchanged.
		$this->assertSame( 'https://example.com/dest', $this->get_destination( '/update-status-only' ) );
	}

	/**
	 * Test updating multiple redirects to the same destination.
	 */
	public function test_update_multiple(): void {
		$this->create_redirect( '/update-multi-one', 'https://example.com/a' );
		$this->create_redirect( '/update-multi-two', 'https://example.com/b' );

		$this->invoke_command(
			$this->command,
			array( '/update-multi-one', '/update-multi-two' ),
			array( 'to' => '/shared-target' )
		);

		$this->assert_success_contains( 'Updated 2 redirects.' );
		$this->assertSame( '/shared-target', $this->get_destination( '/update-multi-one' ) );
		$this->assertSame( '/shared-target', $this->get_destination( '/update-multi-two' ) );
	}

	/**
	 * Test error when neither --to nor --status is given.
	 */
	public function test_update_requires_a_change(): void {
		$this->create_redirect( '/update-no-op', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/update-no-op' ),
			array()
		);

		$this->assert_error_contains( 'at least one of --to or --status' );
	}

	/**
	 * Test error when redirect not found.
	 */
	public function test_update_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent' ),
			array( 'to' => '/anywhere' )
		);

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Redirect not found: /nonexistent' );
	}

	/**
	 * Test error for invalid destination.
	 */
	public function test_update_invalid_destination(): void {
		$this->create_redirect( '/update-bad-dest', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/update-bad-dest' ),
			array( 'to' => '' )
		);

		$this->assert_error_contains( 'Invalid destination' );
	}
}
