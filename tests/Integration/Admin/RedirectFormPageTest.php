<?php
/**
 * RedirectFormPage save handler integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;
use WPDieException;

/**
 * Integration tests for RedirectFormPage::handle_save().
 *
 * The handler under test ends either in wp_die() or in wp_safe_redirect()
 * followed by exit. These tests install a wp_die handler that throws, and hook
 * 'wp_redirect' to throw before the exit is reached, so both outcomes can be
 * asserted without ending the PHP process. Nothing happens after the redirect
 * call, so nothing observable is lost by never reaching the exit.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage::handle_save
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class RedirectFormPageTest extends TestCase {

	/**
	 * The page under test.
	 *
	 * @var RedirectFormPage
	 */
	private RedirectFormPage $page;

	/**
	 * Default HTTP mock marking every destination reachable.
	 *
	 * @var callable
	 */
	private $http_ok;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->page = new RedirectFormPage(
			$this->repository(),
			$this->manager(),
			$this->validator(),
			$this->auditor()
		);

		// The reachability check on save would otherwise make a real request
		// to home_url(), which in wp-env serves a different install than the
		// tests database and 404s everything. Default to reachable; tests
		// exercising rejection add their own 404 filter, which runs later
		// and wins.
		$this->http_ok = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $this->http_ok );

		$_POST = array();
	}

	/**
	 * Clean up request superglobals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http_ok );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Sign in as a user able to manage redirects.
	 *
	 * @return int The user ID.
	 */
	private function login_as_redirect_manager(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );

		return $user_id;
	}

	/**
	 * Populate $_POST with a valid nonce plus the given form fields.
	 *
	 * @param array $fields The form fields to submit.
	 * @return void
	 */
	private function submit( array $fields ): void {
		$_POST = array_merge(
			array( 'redirect_nonce' => wp_create_nonce( 'save_redirect' ) ),
			$fields
		);
	}

	/**
	 * Run handle_save() and return the URL it redirects to.
	 *
	 * @return string The redirect location.
	 */
	private function capture_redirect(): string {
		$capture = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Location is only read back by the test.
			throw new \RuntimeException( (string) $location );
		};

		add_filter( 'wp_redirect', $capture );

		$location = null;
		try {
			$this->page->handle_save();
		} catch ( \RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		if ( null === $location ) {
			$this->fail( 'Expected handle_save() to redirect.' );
		}

		return $location;
	}

	/**
	 * Run handle_save() and assert it calls wp_die() with the given message.
	 *
	 * The WordPress test suite's default wp_die handler prints the message and
	 * returns, so this installs a handler that throws instead.
	 *
	 * @param string $expected The expected wp_die() message.
	 * @return void
	 */
	private function assert_dies_with( string $expected ): void {
		$thrower = static function () {
			return static function ( $message ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is only read back by the test.
				throw new WPDieException( (string) $message );
			};
		};

		add_filter( 'wp_die_handler', $thrower );

		$message = null;
		try {
			$this->page->handle_save();
		} catch ( WPDieException $e ) {
			$message = $e->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $thrower );
		}

		if ( null === $message ) {
			$this->fail( 'Expected handle_save() to call wp_die().' );
		}

		$this->assertSame( $expected, $message );
	}

	/**
	 * Get the redirect post ID for a source path.
	 *
	 * @param string $source The source path.
	 * @return int The redirect post ID, or 0.
	 */
	private function redirect_id_for( string $source ): int {
		return $this->repository()->get_id_by_source( SourceUrl::from_string( $source ) );
	}

	/**
	 * Get a redirect by source path.
	 *
	 * @param string $source The source path.
	 * @return \Automattic\LegacyRedirector\Domain\Redirect|null The redirect, or null.
	 */
	private function find_redirect( string $source ) {
		return $this->repository()->find_by_source( SourceUrl::from_string( $source ) );
	}

	/**
	 * Test a missing nonce stops the request.
	 */
	public function test_missing_nonce_dies(): void {
		$this->login_as_redirect_manager();

		$this->assert_dies_with( 'Security check failed.' );
	}

	/**
	 * Test an invalid nonce stops the request.
	 */
	public function test_invalid_nonce_dies(): void {
		$this->login_as_redirect_manager();
		$_POST = array( 'redirect_nonce' => 'not-a-real-nonce' );

		$this->assert_dies_with( 'Security check failed.' );
	}

	/**
	 * Test a user without the capability is stopped.
	 */
	public function test_missing_capability_dies(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->submit( array() );

		$this->assert_dies_with( 'You do not have permission to manage redirects.' );
	}

	/**
	 * Test empty fields redirect back to the add page with an error.
	 */
	public function test_empty_fields_redirect_with_error(): void {
		$this->login_as_redirect_manager();
		$this->submit(
			array(
				'redirect_from' => '',
				'redirect_to'   => '',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'page=add-redirect', $location );
		$this->assertStringContainsString( 'error=empty_fields', $location );
	}

	/**
	 * Test an empty destination alone is still an error.
	 */
	public function test_empty_destination_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$this->submit(
			array(
				'redirect_from' => '/form-empty-destination',
				'redirect_to'   => '',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=empty_fields', $location );
		$this->assertSame( 0, $this->redirect_id_for( '/form-empty-destination' ) );
	}

	/**
	 * Test a destination with an unsupported scheme is rejected.
	 */
	public function test_invalid_destination_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$this->submit(
			array(
				'redirect_from' => '/form-bad-scheme',
				'redirect_to'   => 'ftp://example.com/file',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=invalid_destination', $location );
		$this->assertSame( 0, $this->redirect_id_for( '/form-bad-scheme' ) );
	}

	/**
	 * Test a destination on a disallowed host is refused with its own error.
	 *
	 * The generic "destination is not valid" message gives the admin nothing
	 * to act on; this failure has a fix - the allowed_redirect_hosts filter -
	 * so the error must name it.
	 */
	public function test_disallowed_host_destination_redirects_with_named_error(): void {
		$this->login_as_redirect_manager();
		$this->submit(
			array(
				'redirect_from' => '/form-disallowed-host',
				'redirect_to'   => 'https://not-allowed.example.net/page',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=host_not_allowed', $location );
		$this->assertSame( 0, $this->redirect_id_for( '/form-disallowed-host' ) );
	}

	/**
	 * Test a destination post ID that does not exist is rejected.
	 */
	public function test_missing_destination_post_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$this->submit(
			array(
				'redirect_from' => '/form-missing-post',
				'redirect_to'   => '999999',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=post_not_found', $location );
	}

	/**
	 * Test an unpublished destination post is rejected.
	 */
	public function test_unpublished_destination_post_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->submit(
			array(
				'redirect_from' => '/form-draft-post',
				'redirect_to'   => (string) $post_id,
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=post_not_public', $location );
	}

	/**
	 * Test a destination path that returns 404 is rejected.
	 *
	 * The slug lookup treats a miss as indeterminate, so the form asks the
	 * site directly; an affirmative 404 rejects the save.
	 */
	public function test_unknown_destination_path_redirects_with_error(): void {
		$this->login_as_redirect_manager();

		$respond_404 = static function () {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond_404 );

		$this->submit(
			array(
				'redirect_from' => '/form-unknown-path',
				'redirect_to'   => '/no-such-page-anywhere',
			)
		);

		$location = $this->capture_redirect();

		remove_filter( 'pre_http_request', $respond_404 );

		$this->assertStringContainsString( 'error=path_not_found', $location );
	}

	/**
	 * Test a postless destination path that the site serves is accepted.
	 *
	 * Archives and rewrite endpoints resolve to no post, which previously
	 * rejected them outright; a reachable path now saves.
	 */
	public function test_postless_destination_path_that_resolves_is_accepted(): void {
		$this->login_as_redirect_manager();

		$respond_200 = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond_200 );

		$this->submit(
			array(
				'redirect_from' => '/form-archive-source',
				'redirect_to'   => '/category/news/',
			)
		);

		$location = $this->capture_redirect();

		remove_filter( 'pre_http_request', $respond_200 );

		$this->assertStringContainsString( 'message=created', $location );
	}

	/**
	 * Test a source URL with no path is rejected.
	 */
	public function test_invalid_source_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->submit(
			array(
				'redirect_from' => 'http://example.com',
				'redirect_to'   => (string) $post_id,
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=invalid_source', $location );
	}

	/**
	 * Test a duplicate source is rejected.
	 */
	public function test_duplicate_source_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/form-duplicate', '/some-destination' );

		$this->submit(
			array(
				'redirect_from' => '/form-duplicate',
				'redirect_to'   => (string) $post_id,
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=duplicate', $location );

		// The original destination is untouched.
		$redirect = $this->find_redirect( '/form-duplicate' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/some-destination', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test the submitted values are preserved on the error redirect.
	 */
	public function test_error_redirect_preserves_submitted_values(): void {
		$this->login_as_redirect_manager();

		// Force the reachability check to reject so the error path runs.
		$respond_404 = static function () {
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			);
		};
		add_filter( 'pre_http_request', $respond_404 );

		$this->submit(
			array(
				'redirect_from'   => '/form-preserved',
				'redirect_to'     => '/no-such-page-anywhere',
				'redirect_status' => 'draft',
			)
		);

		$location = $this->capture_redirect();

		remove_filter( 'pre_http_request', $respond_404 );

		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		$this->assertSame( '/form-preserved', rawurldecode( $query['redirect_from'] ) );
		$this->assertSame( '/no-such-page-anywhere', rawurldecode( $query['redirect_to'] ) );
		$this->assertSame( 'draft', $query['redirect_status'] );
	}

	/**
	 * Test a valid submission creates the redirect.
	 */
	public function test_successful_create_persists_redirect(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->submit(
			array(
				'redirect_from'   => '/form-created',
				'redirect_to'     => (string) $post_id,
				'redirect_status' => 'publish',
			)
		);

		$location = $this->capture_redirect();

		$redirect = $this->find_redirect( '/form-created' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );

		$this->assertStringContainsString( 'message=created', $location );
		$this->assertStringContainsString( 'page=edit-redirect', $location );
		$this->assertStringContainsString( 'redirect_id=' . $redirect->id(), $location );
	}

	/**
	 * Test a URL destination is stored as given.
	 */
	public function test_successful_create_with_url_destination(): void {
		$this->login_as_redirect_manager();

		$this->submit(
			array(
				'redirect_from' => '/form-created-url',
				'redirect_to'   => 'https://example.com/elsewhere',
			)
		);

		$this->capture_redirect();

		$redirect = $this->find_redirect( '/form-created-url' );
		$this->assertNotNull( $redirect );
		$this->assertSame( 'https://example.com/elsewhere', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test choosing the disabled status creates a draft redirect.
	 *
	 * The redirect must never pass through a published state on the way: a
	 * create-then-disable pair would briefly serve traffic and pre-warm the
	 * positive cache entry.
	 */
	public function test_create_with_draft_status(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$statuses = array();
		add_action(
			'transition_post_status',
			static function ( $new_status, $old_status, $post ) use ( &$statuses ): void {
				if ( PostTypeRedirectRepository::POST_TYPE === $post->post_type ) {
					$statuses[] = $new_status;
				}
			},
			10,
			3
		);

		$this->submit(
			array(
				'redirect_from'   => '/form-created-draft',
				'redirect_to'     => (string) $post_id,
				'redirect_status' => 'draft',
			)
		);

		$this->capture_redirect();

		$redirect_id = $this->redirect_id_for( '/form-created-draft' );
		$this->assertGreaterThan( 0, $redirect_id );
		$this->assertSame( 'draft', get_post( $redirect_id )->post_status );
		$this->assertSame( array( 'draft' ), $statuses );
	}

	/**
	 * Test an unrecognized status falls back to publish.
	 */
	public function test_unknown_status_falls_back_to_publish(): void {
		$this->login_as_redirect_manager();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->submit(
			array(
				'redirect_from'   => '/form-unknown-status',
				'redirect_to'     => (string) $post_id,
				'redirect_status' => 'pending',
			)
		);

		$this->capture_redirect();

		$redirect_id = $this->redirect_id_for( '/form-unknown-status' );
		$this->assertSame( 'publish', get_post( $redirect_id )->post_status );
	}

	/**
	 * Test creating a redirect that points back at its own source is rejected.
	 */
	public function test_create_pointing_at_own_source_redirects_with_error(): void {
		$this->login_as_redirect_manager();

		$this->submit(
			array(
				'redirect_from' => '/form-loop',
				'redirect_to'   => '/form-loop',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=same_source_destination', $location );
		$this->assertSame( 0, $this->redirect_id_for( '/form-loop' ) );
	}

	/**
	 * Test an absolute destination on this site still counts as its own source.
	 */
	public function test_create_pointing_at_own_source_absolute_redirects_with_error(): void {
		$this->login_as_redirect_manager();

		$this->submit(
			array(
				'redirect_from' => '/form-loop-absolute',
				'redirect_to'   => home_url( '/form-loop-absolute' ),
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=same_source_destination', $location );
		$this->assertSame( 0, $this->redirect_id_for( '/form-loop-absolute' ) );
	}

	/**
	 * Test editing an existing redirect updates source and destination.
	 */
	public function test_successful_edit_updates_redirect(): void {
		$this->login_as_redirect_manager();
		$old_post    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/form-edit-old', $old_post );

		$this->submit(
			array(
				'redirect_id'     => (string) $redirect_id,
				'redirect_from'   => '/form-edit-new',
				'redirect_to'     => (string) $new_post,
				'redirect_status' => 'publish',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'message=updated', $location );
		$this->assertStringContainsString( 'redirect_id=' . $redirect_id, $location );

		$this->assertNull( $this->find_redirect( '/form-edit-old' ) );

		$redirect = $this->find_redirect( '/form-edit-new' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $redirect_id, $redirect->id() );
		$this->assertSame( $new_post, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test editing a redirect without changing its source is allowed.
	 */
	public function test_edit_keeping_same_source_is_not_a_duplicate(): void {
		$this->login_as_redirect_manager();
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/form-edit-same', '/old-destination' );

		$this->submit(
			array(
				'redirect_id'     => (string) $redirect_id,
				'redirect_from'   => '/form-edit-same',
				'redirect_to'     => (string) $post_id,
				'redirect_status' => 'publish',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'message=updated', $location );

		$redirect = $this->find_redirect( '/form-edit-same' );
		$this->assertNotNull( $redirect );
		$this->assertSame( $post_id, $redirect->destination()->as_post_id()->value() );
	}

	/**
	 * Test editing to a source already used by another redirect is rejected.
	 */
	public function test_edit_to_existing_source_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/form-edit-source', '/old-destination' );
		$this->create_redirect( '/form-edit-taken', '/taken-destination' );

		$this->submit(
			array(
				'redirect_id'     => (string) $redirect_id,
				'redirect_from'   => '/form-edit-taken',
				'redirect_to'     => (string) $post_id,
				'redirect_status' => 'publish',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=duplicate', $location );
		$this->assertStringContainsString( 'redirect_id=' . $redirect_id, $location );
	}

	/**
	 * Test editing a redirect to point back at its own source is rejected.
	 */
	public function test_edit_to_own_source_redirects_with_error(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/form-edit-loop', '/old-destination' );

		$this->submit(
			array(
				'redirect_id'     => (string) $redirect_id,
				'redirect_from'   => '/form-edit-loop',
				'redirect_to'     => '/form-edit-loop',
				'redirect_status' => 'publish',
			)
		);

		$location = $this->capture_redirect();

		$this->assertStringContainsString( 'error=same_source_destination', $location );

		$redirect = $this->find_redirect( '/form-edit-loop' );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/old-destination', $redirect->destination()->as_url()->value() );
	}

	/**
	 * Test editing can disable a redirect.
	 */
	public function test_edit_can_disable_redirect(): void {
		$this->login_as_redirect_manager();
		$redirect_id = $this->create_redirect( '/form-edit-disable', '/some-destination' );

		$this->submit(
			array(
				'redirect_id'     => (string) $redirect_id,
				'redirect_from'   => '/form-edit-disable',
				'redirect_to'     => 'https://example.com/elsewhere',
				'redirect_status' => 'draft',
			)
		);

		$this->capture_redirect();

		$this->assertSame( 'draft', get_post( $redirect_id )->post_status );
	}
}
