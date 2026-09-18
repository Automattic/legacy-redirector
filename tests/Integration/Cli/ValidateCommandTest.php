<?php
/**
 * ValidateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand;

/**
 * Integration tests for ValidateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssue
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssueType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
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
			new RedirectBatch( new RedirectFetcher( $this->repository() ) ),
			$this->query_repository(),
			new RedirectAuditor(),
			$this->manager()
		);
	}

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
	 * Test an attachment destination is not reported as unpublished.
	 *
	 * Attachments carry post_status 'inherit', never 'publish', so reading the
	 * raw property flags every media destination as broken - and --fix then
	 * disables a redirect that resolves perfectly well.
	 */
	public function test_validate_accepts_attachment_destination(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'brochure.pdf',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Brochure',
			)
		);
		$this->create_redirect( '/attachment-dest', $attachment_id );

		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );

		$this->invoke_command( $this->command, array( '/attachment-dest' ), array() );

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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
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
	 * Test a reserved source is reported, but --fix leaves it enabled.
	 *
	 * It may be a genuine legacy URL, so it is a warning for a person to
	 * judge, not a breakage to switch off.
	 */
	public function test_validate_reports_reserved_source_without_fixing_it(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/wp-admin/', $post_id );

		$this->invoke_command( $this->command, array(), array( 'fix' => true ) );

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Reserved WordPress path' );
		$this->assert_stdout_contains( 'Disabled 0 broken redirect(s).' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
	}

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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
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

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Post trashed' );
	}

	/**
	 * Test a destination of '/' is not judged by an unrelated post's status.
	 *
	 * The home page has no slug, and get_page_by_path( '' ) matches any post
	 * with an empty post_name - which every draft and pending post has.
	 */
	public function test_validate_home_destination_ignores_empty_slug_posts(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'pending',
				'post_name'   => '',
			)
		);
		$this->create_redirect( '/home-destination', '/' );

		$this->invoke_command( $this->command, array( '/home-destination' ), array() );

		$this->assert_success_contains( 'No issues found.' );
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
