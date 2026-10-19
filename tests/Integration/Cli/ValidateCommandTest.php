<?php
/**
 * ValidateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::status_label
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
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
			new AuditSweep( $this->query_repository(), $this->auditor(), new AuditFlags() ),
			new AuditFlags()
		);
	}

	/**
	 * Clean up the sweep's state and the badge.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array( AuditSweep::STATE_OPTION, AuditSweep::LOCK_OPTION, AuditFlags::CHECKED_AT_OPTION, AuditFlags::COUNTS_OPTION ) as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/**
	 * Test an attachment destination is not reported as unpublished.
	 *
	 * Attachments carry post_status 'inherit', never 'publish', so reading the
	 * raw property would flag every media destination as broken.
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

		$this->assert_warning_contains( 'Found 1 issue.' );
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

		$this->assert_warning_contains( 'Found 1 issue.' );
	}

	/**
	 * Test a relative-path destination whose post is trashed is reported
	 * without a request.
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

		$this->assert_warning_contains( 'Found 1 issue.' );
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
		$this->assert_error_contains( 'Redirect not found: /nonexistent' );
	}

	/**
	 * Test a redirect named but not found fails the run, after the others are checked.
	 */
	public function test_validate_partly_not_found_is_an_error(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/partly-found', $post_id );

		$this->invoke_command( $this->command, array( '/partly-found', '/partly-missing' ), array() );

		$this->assert_warning_contains( 'Redirect not found: /partly-missing' );
		$this->assert_stdout_contains( 'Success: No issues found.' );
		$this->assert_error_contains( 'Only validated 1 of 2 redirects.' );
	}

	/**
	 * Test a structured format with no findings prints an empty list, not a sentence.
	 */
	public function test_validate_json_without_findings_is_an_empty_list(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/json-clean', $post_id );
		$GLOBALS['wp_cli_format_items_calls'] = array();

		$this->invoke_command( $this->command, array( '/json-clean' ), array( 'format' => 'json' ) );

		$this->assertSame( '', $this->get_stdout() );
		$this->assertSame( 'json', $GLOBALS['wp_cli_format_items_calls'][0][0] );
		$this->assertSame( array(), $GLOBALS['wp_cli_format_items_calls'][0][1] );
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

		$this->assert_warning_contains( 'Found 1 issue.' );
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

		$this->assert_warning_contains( 'Found 1 issue.' );
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

		$this->assert_warning_contains( 'Found 1 issue.' );
		$this->assert_stdout_contains( 'Destination host not allowed' );
	}

	/**
	 * Test a loop across two redirects is reported on both members.
	 */
	public function test_validate_reports_a_possible_loop(): void {
		$this->create_redirect( '/loop-a', '/loop-b' );
		$this->create_redirect( '/loop-b', '/loop-a' );

		$this->invoke_command( $this->command, array( '/loop-a', '/loop-b' ), array() );

		$this->assert_warning_contains( 'Found 2 issues.' );
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

		$this->assert_warning_contains( 'Found 1 issue.' );
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
	 * Test a named redirect's finding says what is wrong and what to do about it.
	 */
	public function test_validate_says_what_to_do_about_a_finding(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/deleted-dest', $post_id );
		wp_delete_post( $post_id, true );

		$GLOBALS['wp_cli_format_items_calls'] = array();
		$this->invoke_command( $this->command, array( '/deleted-dest' ), array( 'format' => 'json' ) );

		$rows = $GLOBALS['wp_cli_format_items_calls'][0][1];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'problem', $rows[0]['severity'] );
		$this->assertSame( 'Post deleted', $rows[0]['issue'] );
		$this->assertSame( 'The destination post no longer exists', $rows[0]['detail'] );
		$this->assertSame( 'Point this redirect at another destination, or delete it', $rows[0]['remedy'] );
	}

	/**
	 * Test a reserved source is reported as a warning, and nothing is changed.
	 *
	 * It may be a genuine legacy URL, so it is for a person to judge.
	 */
	public function test_validate_reports_a_reserved_source_as_a_warning(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/wp-admin/', $post_id );

		$this->invoke_command( $this->command, array( (string) $redirect_id ), array() );

		$this->assert_warning_contains( 'Found 1 issue.' );
		$this->assert_stdout_contains( 'Reserved WordPress path' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
		$this->assertSame( 'warning', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test validating named redirects records their flags and recounts the badge, and clears them once fixed.
	 *
	 * A fix that no event announces - here, a host allowed in code - is
	 * picked up by checking that one redirect again.
	 */
	public function test_validate_records_and_clears_a_named_redirects_flag(): void {
		$redirect_id = $this->create_redirect( '/host-later-allowed', 'https://later-allowed.example.net/page' );

		$this->invoke_command( $this->command, array( '/host-later-allowed' ), array() );

		$this->assertSame( 'problem', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
		$this->assertSame( 1, ( new AuditFlags() )->problem_count() );

		$allow = static fn( array $hosts ): array => array_merge( $hosts, array( 'later-allowed.example.net' ) );
		add_filter( 'allowed_redirect_hosts', $allow );

		try {
			$this->invoke_command( $this->command, array( '/host-later-allowed' ), array() );
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allow );
		}

		$this->assert_success_contains( 'No issues found.' );
		$this->assertSame( '', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
		$this->assertSame( 0, ( new AuditFlags() )->problem_count() );
	}

	/**
	 * Test validate with no redirects checks every redirect, records the flags, and points at the list.
	 */
	public function test_validate_all_checks_every_redirect_and_records_flags(): void {
		$post_id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$broken   = $this->create_redirect( '/all-broken', $post_id );
		$disabled = $this->create_redirect( '/all-disabled', 'https://not-allowed.example.net/x', false );
		$this->create_redirect( '/all-fine', '/somewhere' );
		wp_update_post(
			array(
				'ID'          => $disabled,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $post_id );

		// Trashing the destination flags the redirect at once; start clean so
		// the flag below is validate's own.
		delete_post_meta( $broken, AuditFlags::META_KEY );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_success_contains( 'Validated 3 redirects: 2 with problems, 0 with warnings.' );
		$this->assert_stdout_contains( 'wp legacy-redirector list --has-issues' );
		$this->assertSame( 'problem', get_post_meta( $broken, AuditFlags::META_KEY, true ) );
		$this->assertSame( 'problem', get_post_meta( $disabled, AuditFlags::META_KEY, true ) );
		$this->assertSame( 2, ( new AuditFlags() )->problem_count() );
		$this->assertNotNull( ( new AuditFlags() )->checked_at() );
	}

	/**
	 * Test validate with no redirects and no problems says so, without pointing anywhere.
	 */
	public function test_validate_all_with_no_issues(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/valid-one', $post_id );
		$this->create_redirect( '/valid-two', $post_id );

		$this->invoke_command( $this->command, array(), array() );

		$this->assert_success_contains( 'Validated 2 redirects: 0 with problems, 0 with warnings.' );
		$this->assert_stdout_not_contains( 'list --has-issues' );
	}

	/**
	 * Test a full pass in a structured format prints the totals as data, not a sentence.
	 */
	public function test_validate_all_json_prints_the_totals(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/totals-one', $post_id );
		$this->create_redirect( '/wp-admin/totals', '/fine' );
		$GLOBALS['wp_cli_format_items_calls'] = array();

		$this->invoke_command( $this->command, array(), array( 'format' => 'json' ) );

		$this->assertFalse( $this->output->had_success() );
		$this->assertSame(
			array(
				array(
					'validated' => 2,
					'problems'  => 0,
					'warnings'  => 1,
				),
			),
			$GLOBALS['wp_cli_format_items_calls'][0][1]
		);
	}

	/**
	 * Test the count format: flagged redirects for a full pass, findings for named ones.
	 */
	public function test_validate_count_format(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/count-broken', $post_id );
		$this->create_redirect( '/wp-admin/count', '/fine' );
		wp_delete_post( $post_id, true );

		$this->invoke_command( $this->command, array(), array( 'format' => 'count' ) );
		$this->assertSame( '2', trim( $this->get_stdout() ) );

		$this->invoke_command( $this->command, array( '/count-broken' ), array( 'format' => 'count' ) );
		$this->assertSame( '1', trim( $this->get_stdout() ) );
	}

	/**
	 * Test validate never requests a URL: that is `test`'s job.
	 */
	public function test_validate_makes_no_http_requests(): void {
		$this->create_redirect( '/external-unrequested', 'https://example.com/page' );

		$requests = 0;
		$count    = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $count );

		try {
			$this->invoke_command( $this->command, array(), array() );
			$this->invoke_command( $this->command, array( '/external-unrequested' ), array() );
		} finally {
			remove_filter( 'pre_http_request', $count );
		}

		$this->assertSame( 0, $requests );
	}

	/**
	 * Test a source that a published page now answers is a warning, with what to do.
	 *
	 * Redirects only answer 404s, so the page wins and the redirect lies
	 * dormant: found from stored details, without a request.
	 */
	public function test_validate_warns_about_content_at_the_source(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'occupied-source',
			)
		);
		$redirect_id = $this->create_redirect( '/occupied-source', '/somewhere-else' );

		$GLOBALS['wp_cli_format_items_calls'] = array();
		$this->invoke_command( $this->command, array( '/occupied-source' ), array() );

		$rows = $GLOBALS['wp_cli_format_items_calls'][0][1];
		$this->assertSame( 'warning', $rows[0]['severity'] );
		$this->assertSame( 'Source serves content', $rows[0]['issue'] );
		$this->assertStringContainsString( 'Unpublish the content at the source', $rows[0]['remedy'] );
		$this->assertSame( 'warning', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}
}
