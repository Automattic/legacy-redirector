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
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Integration tests for DeleteCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CountsInWords
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::status_label
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
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

		$this->assert_success_contains( 'Deleted redirect: /delete-me (ID ' . $redirect_id . ', to https://example.com/dest)' );
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
		$this->assert_error_contains( 'Redirect not found: /nonexistent' );
		$this->assertCount( 1, \WP_CLI::get_calls( 'error' ), 'One error is enough for one redirect.' );
		$this->assertFalse( $this->output->had_warning() );
	}

	/**
	 * Test nothing is asked about when none of the redirects exist.
	 */
	public function test_delete_does_not_prompt_when_nothing_is_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/missing-one', '/missing-two' ),
			array()
		);

		$this->assertFalse( \WP_CLI::was_called( 'confirm' ) );
		$this->assert_warning_contains( 'Redirect not found: /missing-one' );
		$this->assert_error_contains( 'Could not delete any of the 2 redirects.' );
	}

	/**
	 * Test the prompt counts the redirects found, not the identifiers given.
	 */
	public function test_delete_prompt_counts_what_was_found(): void {
		$first  = $this->create_redirect( '/found-one', 'https://example.com/dest' );
		$second = $this->create_redirect( '/found-two', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/found-one', '/found-two', '/found-missing' ),
			array()
		);

		$this->assertSame( 'Are you sure you want to delete 2 redirects?', \WP_CLI::get_call( 'confirm' )[1] );
		$this->assert_error_contains( 'Only deleted 2 of 3 redirects.' );
		$this->assertNull( get_post( $first ) );
		$this->assertNull( get_post( $second ) );
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
		$this->assert_error_contains( 'Invalid source path' );
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
		$redirect_id = $this->create_redirect( '/confirm-me', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/confirm-me' ),
			array()
		);

		// The stub records the confirm call; a real run would prompt.
		$this->assertTrue( \WP_CLI::was_called( 'confirm' ) );
		$this->assertSame(
			'Are you sure you want to delete the redirect /confirm-me (ID ' . $redirect_id . ', to https://example.com/dest)?',
			\WP_CLI::get_call( 'confirm' )[1]
		);
	}

	/**
	 * Test a path shared with a disabled duplicate deletes nothing and names both rows.
	 */
	public function test_delete_refuses_a_path_shared_with_a_duplicate(): void {
		list( $live_id, $duplicate_id ) = $this->create_duplicate_pair();

		$this->invoke_command(
			$this->command,
			array( '/clash/' ),
			array( 'yes' => true )
		);

		$this->assert_command_error();
		$this->assert_error_contains( sprintf( '2 redirects share this source, IDs %d, %d; use an ID', $live_id, $duplicate_id ) );
		$this->assertNotNull( get_post( $live_id ) );
		$this->assertNotNull( get_post( $duplicate_id ) );
	}

	/**
	 * Test deleting a duplicate by ID deletes only that row and leaves the live redirect serving.
	 */
	public function test_delete_duplicate_by_id_leaves_the_live_redirect_serving(): void {
		list( $live_id, $duplicate_id ) = $this->create_duplicate_pair();
		$source                         = SourceUrl::from_string( '/clash' );
		$this->assertSame( $live_id, $this->repository()->find_by_source( $source )?->id() );

		$this->invoke_command(
			$this->command,
			array( (string) $duplicate_id ),
			array( 'yes' => true )
		);

		$this->assert_command_success();
		$this->assertNull( get_post( $duplicate_id ) );
		$this->assertSame( $live_id, $this->repository()->find_by_source( $source )?->id() );
	}

	/**
	 * Test a duplicate in the trash does not make the path ambiguous.
	 */
	public function test_delete_by_path_ignores_a_trashed_duplicate(): void {
		list( $live_id, $duplicate_id ) = $this->create_duplicate_pair();
		wp_trash_post( $duplicate_id );

		$this->invoke_command(
			$this->command,
			array( '/clash' ),
			array( 'yes' => true )
		);

		$this->assert_command_success();
		$this->assertNull( get_post( $live_id ) );
	}

	/**
	 * Create a live redirect and a disabled duplicate of it, as the upgrade leaves them.
	 *
	 * @return array{0: int, 1: int} The live and duplicate IDs.
	 */
	private function create_duplicate_pair(): array {
		$live_id      = $this->create_redirect( '/clash', 'https://example.com/one' );
		$duplicate_id = $this->insert_redirect_post(
			array(
				'post_title'   => '/clash/',
				'post_name'    => md5( '/clash/' ),
				'post_excerpt' => 'https://example.com/two',
				'post_status'  => 'draft',
			)
		);
		update_post_meta( $duplicate_id, Upgrader::DUPLICATE_META_KEY, $live_id );

		return array( $live_id, $duplicate_id );
	}
}
