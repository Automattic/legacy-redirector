<?php
/**
 * ValidateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand;

/**
 * Integration tests for ValidateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssue
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssueType
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ValidateCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ValidateCommand
	 */
	private ValidateCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new ValidateCommand(
			new RedirectFetcher( $this->container()->inner_repository() ),
			$this->container()->query_repository(),
			$this->container()->validator(),
			$this->container()->manager()
		);
	}

	// =========================================================================
	// Batch mode (no positional arguments)
	// =========================================================================

	/**
	 * Test batch validation with no issues.
	 */
	public function test_validate_batch_no_issues(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/valid-one', $post_id );
		$this->create_redirect( '/valid-two', $post_id );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_success_contains( 'No issues found.' );
	}

	/**
	 * Test batch validation finds a trashed post destination.
	 */
	public function test_validate_batch_finds_trashed_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/broken-redirect', $post_id );
		wp_trash_post( $post_id );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
		$this->assert_stdout_contains( '/broken-redirect' );
		$this->assert_stdout_contains( 'Post trashed' );
	}

	/**
	 * Test batch validation finds a deleted post destination.
	 */
	public function test_validate_batch_finds_deleted_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/deleted-dest', $post_id );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
		$this->assert_stdout_contains( 'Post deleted' );
	}

	/**
	 * Test count format outputs only the number of issues.
	 */
	public function test_validate_count_format(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/count-broken', $post_id );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array( 'format' => 'count' ) );

		$this->assertFalse( $this->output->had_error() );
		$this->assertSame( '1', trim( $this->get_stdout() ) );
	}

	/**
	 * Test --fix disables broken redirects via the manager.
	 */
	public function test_validate_fix_disables_broken_redirects(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/fix-me', $post_id );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array( 'fix' => true ) );

		$this->assert_success_contains( 'Disabled 1 broken redirect(s).' );
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
	}

	/**
	 * Test disabled redirects are skipped by default (status defaults to enabled).
	 */
	public function test_validate_defaults_to_enabled_only(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/disabled-broken', $post_id );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_success_contains( 'No issues found.' );
	}

	/**
	 * Test --status=any includes disabled redirects.
	 */
	public function test_validate_status_any_includes_disabled(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/any-status-broken', $post_id );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array( 'status' => 'any' ) );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
	}

	// =========================================================================
	// Targeted mode (positional arguments)
	// =========================================================================

	/**
	 * Test validating a single valid redirect by source.
	 */
	public function test_validate_single_valid_redirect(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/single-valid', $post_id );

		$this->invoke_command( $this->command, array( '/single-valid' ), array() );

		$this->assert_success_contains( 'No issues found.' );
	}

	/**
	 * Test validating a single broken redirect by source.
	 */
	public function test_validate_single_broken_redirect(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/single-broken', $post_id );
		wp_trash_post( $post_id );

		$this->invoke_command( $this->command, array( '/single-broken' ), array() );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
		$this->assert_stdout_contains( 'Post trashed' );
	}

	/**
	 * Test validating a single redirect by ID.
	 */
	public function test_validate_single_by_id(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/single-by-id', $post_id );

		$this->invoke_command( $this->command, array( (string) $redirect_id ), array() );

		$this->assert_success_contains( 'No issues found.' );
	}

	/**
	 * Test validating a targeted disabled redirect works without --status.
	 */
	public function test_validate_single_includes_disabled(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/single-disabled', $post_id );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $post_id );

		$this->invoke_command( $this->command, array( '/single-disabled' ), array() );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
	}

	/**
	 * Test a relative-path destination whose post is trashed is reported
	 * without needing --check-urls.
	 *
	 * Trashing renames the post's slug with a __trashed suffix, so a plain
	 * path lookup misses it; the validator must check the renamed slug.
	 */
	public function test_validate_finds_trashed_relative_path_destination(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'trashed-path-target',
				'post_title'  => 'trashed-path-target',
			)
		);
		$this->create_redirect( '/trashed-path-source', '/trashed-path-target' );

		$post = get_page_by_path( 'trashed-path-target', OBJECT, array( 'post', 'page' ) );
		wp_trash_post( $post->ID );

		$this->invoke_command( $this->command, array( '/trashed-path-source' ), array() );

		$this->assert_warning_contains( 'Found 1 broken redirect(s).' );
		$this->assert_stdout_contains( 'Post trashed' );
	}

	/**
	 * Test error when no given redirects can be resolved.
	 */
	public function test_validate_not_found(): void {
		$this->invoke_command( $this->command, array( '/nonexistent' ), array() );

		$this->assert_command_error();
		$this->assert_stdout_contains( 'Redirect not found: /nonexistent' );
	}

	/**
	 * Test --fix on a targeted broken redirect.
	 */
	public function test_validate_single_fix(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/single-fix', $post_id );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array( '/single-fix' ), array( 'fix' => true ) );

		$this->assert_success_contains( 'Disabled 1 broken redirect(s).' );
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
	}
}
