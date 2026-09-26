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
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults
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
			$this->auditor(),
			$this->manager(),
			$this->audit_results()
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
	 * Test an unpublished custom post type destination is reported.
	 *
	 * The destination resolver searches every registered post type, so a
	 * redirect to a custom post type item is judged by that post's status
	 * instead of being treated as unresolvable.
	 */
	public function test_validate_finds_unpublished_custom_post_type_destination(): void {
		register_post_type( 'book', array( 'public' => true ) );

		try {
			$book_id = self::factory()->post->create(
				array(
					'post_type'   => 'book',
					'post_status' => 'publish',
					'post_name'   => 'audited-book',
				)
			);
			$this->create_redirect( '/book-source', '/audited-book' );
			wp_update_post(
				array(
					'ID'          => $book_id,
					'post_status' => 'draft',
				)
			);

			$this->invoke_command( $this->command, array( '/book-source' ), array() );
		} finally {
			unregister_post_type( 'book' );
		}

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Post not published' );
	}

	/**
	 * Test an unpublished destination behind a dated permalink is reported.
	 *
	 * A dated permalink cannot be walked as a hierarchical slug, so the
	 * resolver falls back to url_to_postid() to find the post. That fallback
	 * resolves an unpublished post only for a user who can edit it - which is
	 * exactly who runs `validate` - so the check runs as an administrator.
	 */
	public function test_validate_finds_unpublished_dated_permalink_destination(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'dated-target',
				'post_date'   => '2020-01-15 12:00:00',
			)
		);

		$permalink = get_permalink( $post_id );
		$path      = wp_parse_url( $permalink, PHP_URL_PATH );
		$this->assertStringContainsString( '2020', (string) $path );

		$this->create_redirect( '/dated-source', $path );

		// A published post behind the dated permalink resolves cleanly.
		$this->invoke_command( $this->command, array( '/dated-source' ), array() );
		$this->assert_success_contains( 'No issues found.' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command( $this->command, array( '/dated-source' ), array() );

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Post not published' );
	}

	/**
	 * Test a destination host missing from allowed_redirect_hosts is reported.
	 *
	 * At request time wp_safe_redirect() quietly sends visitors elsewhere,
	 * so the redirect is broken without any HTTP request being needed.
	 */
	public function test_validate_reports_disallowed_external_host(): void {
		$this->create_redirect( '/external-source', 'https://not-allowed.example.net/page' );

		$this->invoke_command( $this->command, array( '/external-source' ), array() );

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Destination host not allowed' );
	}

	/**
	 * Test a batch run records the summary, and a spot check does not.
	 *
	 * The recorded summary feeds the admin menu badge, so a check of one
	 * named redirect must not overwrite the site-wide count.
	 */
	public function test_batch_run_records_summary_but_spot_check_does_not(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/summary-broken', $post_id );
		$this->create_redirect( '/summary-fine', '/target-somewhere' );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array() );

		$summary = $this->audit_results()->summary();
		$this->assertNotNull( $summary );
		$this->assertSame( 1, $summary['problems'] );
		$this->assertSame( 2, $summary['checked'] );

		delete_option( \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults::OPTION );

		$this->invoke_command( $this->command, array( '/summary-broken' ), array() );

		$this->assertNull( $this->audit_results()->summary() );
	}

	/**
	 * Test a loop across two redirects is reported on both members.
	 */
	public function test_validate_reports_a_possible_loop(): void {
		$this->create_redirect( '/loop-a', '/loop-b' );
		$this->create_redirect( '/loop-b', '/loop-a' );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_warning_contains( 'Found 2 issue(s).' );
		$this->assert_stdout_contains( 'Possible redirect loop' );
	}

	/**
	 * Test a stored self-loop is reported.
	 *
	 * The write gate refuses these, but --skip-validation and legacy data can
	 * still store them.
	 */
	public function test_validate_reports_a_stored_self_loop(): void {
		$this->create_redirect( '/self-loop', '/self-loop' );

		$this->invoke_command( $this->command, array( '/self-loop' ), array() );

		$this->assert_warning_contains( 'Found 1 issue(s).' );
		$this->assert_stdout_contains( 'Possible redirect loop' );
	}

	/**
	 * Test a redirect chain that ends free is not reported as a loop.
	 */
	public function test_validate_does_not_report_a_chain_as_a_loop(): void {
		$this->create_redirect( '/hop-one', '/hop-two' );
		$this->create_redirect( '/hop-two', '/somewhere-final' );

		$this->invoke_command( $this->command, array( '/hop-one', '/hop-two' ), array() );

		$this->assert_success_contains( 'No issues found.' );
	}

	/**
	 * Test --fix leaves loop members enabled.
	 *
	 * A loop is a warning: it may be dormant behind live pages, and disabling
	 * every member would be the wrong fix even when it is live.
	 */
	public function test_validate_fix_leaves_loop_members_enabled(): void {
		$a = $this->create_redirect( '/loop-fix-a', '/loop-fix-b' );
		$b = $this->create_redirect( '/loop-fix-b', '/loop-fix-a' );

		$this->invoke_command( $this->command, array(), array( 'fix' => true ) );

		$this->assert_stdout_contains( 'Disabled 0 broken redirect(s).' );
		$this->assertSame( 'publish', get_post_status( $a ) );
		$this->assertSame( 'publish', get_post_status( $b ) );
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
