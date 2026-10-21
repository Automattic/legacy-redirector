<?php
/**
 * EnableCommand and DisableCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\AbstractStatusCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand;

/**
 * Integration tests for the enable and disable commands.
 *
 * Each is one line over AbstractStatusCommand, naming the status it sets
 * and the word it reports, so every case runs against both.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\AbstractStatusCommand
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CountsInWords
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
 * @uses \Automattic\LegacyRedirector\Application\AmbiguousSourceException
 */
final class StatusCommandsTest extends CliTestCase {

	/**
	 * Data provider: each command, the status it moves a redirect from and to, and the words it reports with.
	 *
	 * @return array<string, array{class-string<AbstractStatusCommand>, string, string, string, string}>
	 */
	public static function data_commands(): array {
		return array(
			'enable'  => array( EnableCommand::class, 'draft', 'publish', 'Enabled', 'enable' ),
			'disable' => array( DisableCommand::class, 'publish', 'draft', 'Disabled', 'disable' ),
		);
	}

	/**
	 * Build the command under test.
	 *
	 * @param string $command_class The command's class, an AbstractStatusCommand.
	 * @return AbstractStatusCommand The command.
	 */
	private function command( string $command_class ): AbstractStatusCommand {
		return new $command_class( $this->manager(), new RedirectBatch( new RedirectFetcher( $this->repository() ) ) );
	}

	/**
	 * Create a redirect in the given status.
	 *
	 * @param string $from   The source path.
	 * @param string $status The post status.
	 * @return int The redirect ID.
	 */
	private function create_redirect_in( string $from, string $status ): int {
		$redirect_id = $this->create_redirect( $from, 'https://example.com/dest' );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => $status,
			)
		);

		return $redirect_id;
	}

	/**
	 * Test one redirect named by its source changes status, and the success names it.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 * @param string $from_status   The status before.
	 * @param string $to_status     The status after.
	 * @param string $past_tense    The word the success starts with.
	 */
	public function test_changes_a_redirect_named_by_source( string $command_class, string $from_status, string $to_status, string $past_tense ): void {
		$redirect_id = $this->create_redirect_in( '/change-me', $from_status );

		$this->invoke_command( $this->command( $command_class ), array( '/change-me' ), array() );

		$this->assert_success_contains( $past_tense . ' redirect: /change-me' );
		$this->assertSame( $to_status, get_post_status( $redirect_id ) );
	}

	/**
	 * Test several redirects, named by source and by ID, all change, and the success counts them.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 * @param string $from_status   The status before.
	 * @param string $to_status     The status after.
	 * @param string $past_tense    The word the success starts with.
	 */
	public function test_changes_several_named_by_source_and_id( string $command_class, string $from_status, string $to_status, string $past_tense ): void {
		$first  = $this->create_redirect_in( '/multi-one', $from_status );
		$second = $this->create_redirect_in( '/multi-two', $from_status );

		$this->invoke_command( $this->command( $command_class ), array( '/multi-one', (string) $second ), array() );

		$this->assert_success_contains( $past_tense . ' 2 redirects.' );
		$this->assertSame( $to_status, get_post_status( $first ) );
		$this->assertSame( $to_status, get_post_status( $second ) );
	}

	/**
	 * Test a redirect already in the status asked for is a success, not an error.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 * @param string $from_status   The status before.
	 * @param string $to_status     The status after.
	 */
	public function test_redirect_already_in_that_status_succeeds( string $command_class, string $from_status, string $to_status ): void {
		$redirect_id = $this->create_redirect_in( '/already', $to_status );

		$this->invoke_command( $this->command( $command_class ), array( '/already' ), array() );

		$this->assert_command_success();
		$this->assertSame( $to_status, get_post_status( $redirect_id ) );
	}

	/**
	 * Test a source no redirect has is an error.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 */
	public function test_not_found_is_an_error( string $command_class ): void {
		$this->invoke_command( $this->command( $command_class ), array( '/nonexistent' ), array() );

		$this->assert_command_error();
		$this->assert_error_contains( 'Redirect not found: /nonexistent' );
	}

	/**
	 * Test an empty source path is an error.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 */
	public function test_invalid_source_path_is_an_error( string $command_class ): void {
		$this->invoke_command( $this->command( $command_class ), array( '' ), array() );

		$this->assert_command_error();
		$this->assert_error_contains( 'Invalid source path' );
	}

	/**
	 * Test a refused change names the action that failed.
	 *
	 * A corrupt row cannot be re-saved, so its status cannot change.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 * @param string $from_status   The status before.
	 * @param string $to_status     The status after.
	 * @param string $past_tense    The word the success starts with.
	 * @param string $verb          The word the failure uses.
	 */
	public function test_refused_change_names_the_action( string $command_class, string $from_status, string $to_status, string $past_tense, string $verb ): void {
		$redirect_id = $this->insert_redirect_post( array( 'post_status' => $from_status ) );

		$this->invoke_command( $this->command( $command_class ), array( (string) $redirect_id ), array() );

		$this->assert_command_error();
		$this->assert_error_contains( sprintf( 'Could not %s redirect: %d', $verb, $redirect_id ) );
	}

	/**
	 * Test a mixed batch changes what it can, then names the shortfall.
	 *
	 * @dataProvider data_commands
	 *
	 * @param string $command_class The command's class.
	 * @param string $from_status   The status before.
	 * @param string $to_status     The status after.
	 * @param string $past_tense    The word the success starts with.
	 */
	public function test_partial_failure_names_the_shortfall( string $command_class, string $from_status, string $to_status, string $past_tense ): void {
		$redirect_id = $this->create_redirect_in( '/partial', $from_status );

		$this->invoke_command( $this->command( $command_class ), array( '/partial', '/partial-missing' ), array() );

		$this->assert_warning_contains( 'Redirect not found: /partial-missing' );
		$this->assert_error_contains( sprintf( 'Only %s 1 of 2 redirects.', strtolower( $past_tense ) ) );
		$this->assertSame( $to_status, get_post_status( $redirect_id ) );
	}
}
