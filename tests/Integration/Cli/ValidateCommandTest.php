<?php
/**
 * ValidateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand;

/**
 * Integration tests for ValidateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand
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
			$this->container()->inner_repository(),
			$this->container()->query_repository(),
			$this->container()->validator()
		);
	}

	// =========================================================================
	// Tests for single redirect validation
	// =========================================================================

	/**
	 * Test validating a valid redirect by source.
	 */
	public function test_validate_single_valid_redirect_by_source(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/valid-redirect', $post_id );

		$this->invoke_command(
			$this->command,
			array( '/valid-redirect' ),
			array( 'no-check-urls' => true )
		);

		$this->assert_success_contains( 'valid' );
	}

	/**
	 * Test validating a redirect pointing to trashed post.
	 */
	public function test_validate_single_trashed_post_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/trashed-dest', $post_id );

		// Trash the destination post.
		wp_trash_post( $post_id );

		$this->invoke_command(
			$this->command,
			array( '/trashed-dest' ),
			array( 'no-check-urls' => true )
		);

		$this->assertTrue( $this->output->had_warning() );
		$this->assert_stdout_contains( 'trashed' );
	}

	/**
	 * Test validating a redirect pointing to deleted post.
	 */
	public function test_validate_single_deleted_post_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/deleted-dest', $post_id );

		// Permanently delete the destination post.
		wp_delete_post( $post_id, true );

		$this->invoke_command(
			$this->command,
			array( '/deleted-dest' ),
			array( 'no-check-urls' => true )
		);

		$this->assertTrue( $this->output->had_warning() );
		$this->assert_stdout_contains( 'deleted' );
	}

	/**
	 * Test validating a redirect pointing to draft post.
	 */
	public function test_validate_single_draft_post_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->create_redirect( '/draft-dest', $post_id );

		$this->invoke_command(
			$this->command,
			array( '/draft-dest' ),
			array( 'no-check-urls' => true )
		);

		$this->assertTrue( $this->output->had_warning() );
		$this->assert_stdout_contains( 'not published' );
	}

	/**
	 * Test validating a redirect by ID.
	 */
	public function test_validate_single_by_id(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/by-id-validate', $post_id );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array(
				'by'            => 'id',
				'no-check-urls' => true,
			)
		);

		$this->assert_success_contains( 'valid' );
	}

	/**
	 * Test error when redirect not found.
	 */
	public function test_validate_single_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent-redirect' ),
			array()
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test error for invalid source path.
	 */
	public function test_validate_single_invalid_source(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array()
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	// =========================================================================
	// Tests for single redirect fix
	// =========================================================================

	/**
	 * Test fixing a broken single redirect.
	 */
	public function test_validate_single_with_fix(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/fix-single', $post_id );

		// Trash the destination.
		wp_trash_post( $post_id );

		$this->invoke_command(
			$this->command,
			array( '/fix-single' ),
			array(
				'fix'           => true,
				'no-check-urls' => true,
			)
		);

		$this->assert_success_contains( 'disabled' );

		// Verify redirect was disabled.
		$post = get_post( $redirect_id );
		$this->assertEquals( 'draft', $post->post_status );
	}

	// =========================================================================
	// Tests for batch validation
	// =========================================================================

	/**
	 * Test batch validation with no issues.
	 */
	public function test_validate_batch_no_issues(): void {
		$post1 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post2 = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->create_redirect( '/batch-one', $post1 );
		$this->create_redirect( '/batch-two', $post2 );

		$this->invoke_command(
			$this->command,
			array(),
			array()
		);

		$this->assert_success_contains( 'No issues found' );
	}

	/**
	 * Test batch validation finding broken redirects.
	 */
	public function test_validate_batch_finds_broken(): void {
		$good_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$bad_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->create_redirect( '/batch-good', $good_post );
		$this->create_redirect( '/batch-bad', $bad_post );

		// Delete the bad post.
		wp_delete_post( $bad_post, true );

		$this->invoke_command(
			$this->command,
			array(),
			array()
		);

		$this->assertTrue( $this->output->had_warning() );
		$this->assert_stdout_contains( 'broken redirect' );
		$this->assert_stdout_contains( '/batch-bad' );
	}

	/**
	 * Test batch validation with status filter.
	 */
	public function test_validate_batch_status_filter(): void {
		$post1 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post2 = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$enabled_id  = $this->create_redirect( '/batch-enabled', $post1 );
		$disabled_id = $this->create_redirect( '/batch-disabled', $post2 );

		wp_update_post(
			array(
				'ID'          => $disabled_id,
				'post_status' => 'draft',
			)
		);

		// Delete both destination posts.
		wp_delete_post( $post1, true );
		wp_delete_post( $post2, true );

		// Validate only enabled redirects.
		$this->invoke_command(
			$this->command,
			array(),
			array( 'status' => 'enabled' )
		);

		$this->assert_stdout_contains( '/batch-enabled' );
		$this->assert_stdout_not_contains( '/batch-disabled' );
	}

	/**
	 * Test batch validation count format.
	 */
	public function test_validate_batch_count_format(): void {
		$post1 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post2 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post3 = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->create_redirect( '/validate-count-one', $post1 );
		$this->create_redirect( '/validate-count-two', $post2 );
		$this->create_redirect( '/validate-count-three', $post3 );

		// Delete two posts.
		wp_delete_post( $post1, true );
		wp_delete_post( $post2, true );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'format' => 'count' )
		);

		// Count format outputs "Checking..." line followed by the count.
		// Get the last line which is the actual count.
		$lines = explode( "\n", trim( $this->get_stdout() ) );
		$count = end( $lines );
		$this->assertEquals( '2', $count );
	}

	// =========================================================================
	// Tests for batch fix
	// =========================================================================

	/**
	 * Test batch validation with fix option.
	 */
	public function test_validate_batch_with_fix(): void {
		$post1 = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post2 = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$redirect1 = $this->create_redirect( '/fix-batch-one', $post1 );
		$redirect2 = $this->create_redirect( '/fix-batch-two', $post2 );

		// Delete both destination posts.
		wp_delete_post( $post1, true );
		wp_delete_post( $post2, true );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'fix' => true )
		);

		$this->assert_success_contains( 'Disabled' );
		$this->assert_stdout_contains( '2' ); // Should mention fixing 2 redirects.

		// Verify both redirects were disabled.
		$this->assertEquals( 'draft', get_post( $redirect1 )->post_status );
		$this->assertEquals( 'draft', get_post( $redirect2 )->post_status );
	}

	// =========================================================================
	// Tests for URL redirects
	// =========================================================================

	/**
	 * Test validating a redirect with URL destination (no URL check).
	 */
	public function test_validate_url_destination_without_check(): void {
		$this->create_redirect( '/url-dest', 'https://example.com/destination' );

		$this->invoke_command(
			$this->command,
			array( '/url-dest' ),
			array( 'no-check-urls' => true )
		);

		// Without URL checking, URL destinations are always valid.
		$this->assert_success_contains( 'valid' );
	}
}
