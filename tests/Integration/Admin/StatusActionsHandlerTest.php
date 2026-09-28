<?php
/**
 * StatusActionsHandler status change integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;
use WPDieException;

/**
 * Integration tests for the enable/disable row actions.
 *
 * The handler ends either in wp_die() or in wp_safe_redirect() followed by
 * exit, so these tests install a throwing wp_die handler and hook 'wp_redirect'
 * to throw before the exit is reached, mirroring RedirectFormPageTest.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class StatusActionsHandlerTest extends TestCase {

	/**
	 * The handler under test.
	 *
	 * @var StatusActionsHandler
	 */
	private StatusActionsHandler $handler;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->handler = new StatusActionsHandler( $this->manager(), $this->repository() );

		( new Capability() )->register();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );

		$_GET = array();
	}

	/**
	 * Clean up request superglobals and the current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Test disabling a redirect through the row action changes its status.
	 */
	public function test_disable_redirect_sets_the_redirect_to_draft(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );
		$this->request( 'disable_redirect', $redirect_id );

		$this->assertStringContainsString( 'redirect_disabled=1', $this->capture_redirect( 'handle_disable_redirect' ) );
		$this->assertSame( 'draft', get_post_status( $redirect_id ) );
	}

	/**
	 * Test a post of another type cannot be driven through the redirect row action.
	 *
	 * The handler and RedirectManager both reject the post, so this passes with
	 * either check alone. It guards the behavior, not one particular guard.
	 */
	public function test_disable_redirect_rejects_a_post_of_another_type(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Not a redirect',
			)
		);
		$this->request( 'disable_redirect', $post_id );

		$this->assert_dies_with( 'Invalid redirect.', 'handle_disable_redirect' );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test a missing nonce stops the request.
	 */
	public function test_missing_nonce_dies(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );
		$_GET        = array( 'redirect_id' => (string) $redirect_id );

		$this->assert_dies_with( 'Security check failed.', 'handle_disable_redirect' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test an invalid nonce stops the request.
	 */
	public function test_invalid_nonce_dies(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );
		$_GET        = array(
			'redirect_id' => (string) $redirect_id,
			'_wpnonce'    => 'not-a-real-nonce',
		);

		$this->assert_dies_with( 'Security check failed.', 'handle_disable_redirect' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test a nonce issued for a different redirect stops the request.
	 *
	 * The nonce is per-ID, so one valid nonce must not act on every redirect.
	 */
	public function test_nonce_for_another_redirect_dies(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );
		$other_id    = $this->create_redirect( '/other-page', 'https://example.com/other' );
		$_GET        = array(
			'redirect_id' => (string) $redirect_id,
			'_wpnonce'    => wp_create_nonce( 'disable_redirect_' . $other_id ),
		);

		$this->assert_dies_with( 'Security check failed.', 'handle_disable_redirect' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test a user without the capability is stopped.
	 */
	public function test_missing_capability_dies(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );

		// Nonces are tied to the current user, so sign in before minting one.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->request( 'disable_redirect', $redirect_id );

		$this->assert_dies_with( 'You do not have permission to modify redirects.', 'handle_disable_redirect' );
		$this->assertSame( 'publish', get_post_status( $redirect_id ) );
	}

	/**
	 * Test the enable action is gated by its own nonce and the capability.
	 */
	public function test_enable_redirect_requires_a_valid_nonce(): void {
		$redirect_id = $this->create_redirect( '/old-page', 'https://example.com/new-page' );
		$this->request( 'disable_redirect', $redirect_id );

		$this->assert_dies_with( 'Security check failed.', 'handle_enable_redirect' );
	}

	/**
	 * Populate $_GET with the row action arguments and a valid nonce.
	 *
	 * @param string $action      The action name, as used in the nonce.
	 * @param int    $redirect_id The redirect ID to act on.
	 * @return void
	 */
	private function request( string $action, int $redirect_id ): void {
		$_GET = array(
			'redirect_id' => (string) $redirect_id,
			'_wpnonce'    => wp_create_nonce( $action . '_' . $redirect_id ),
		);
	}

	/**
	 * Run the handler and return the URL it redirects to.
	 *
	 * @param string $method The handler method to call.
	 * @return string The redirect location.
	 */
	private function capture_redirect( string $method ): string {
		$capture = static function ( $location ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Location is only read back by the test.
			throw new \RuntimeException( (string) $location );
		};

		add_filter( 'wp_redirect', $capture );

		$location = null;
		try {
			$this->handler->$method();
		} catch ( \RuntimeException $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		if ( null === $location ) {
			$this->fail( 'Expected ' . $method . '() to redirect.' );
		}

		return $location;
	}

	/**
	 * Run the handler and assert it calls wp_die() with the given message.
	 *
	 * The WordPress test suite's default wp_die handler prints the message and
	 * returns, so this installs a handler that throws instead.
	 *
	 * @param string $expected The expected wp_die() message.
	 * @param string $method   The handler method to call.
	 * @return void
	 */
	private function assert_dies_with( string $expected, string $method ): void {
		$thrower = static function () {
			return static function ( $message ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is only read back by the test.
				throw new WPDieException( (string) $message );
			};
		};

		add_filter( 'wp_die_handler', $thrower );

		$message = null;
		try {
			$this->handler->$method();
		} catch ( WPDieException $e ) {
			$message = $e->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $thrower );
		}

		if ( null === $message ) {
			$this->fail( 'Expected ' . $method . '() to call wp_die().' );
		}

		$this->assertSame( $expected, $message );
	}

	/**
	 * Test enabling a duplicate source explains why it is refused.
	 *
	 * Any failure used to read "Invalid redirect.", which says nothing about
	 * what to do next.
	 */
	public function test_enabling_a_duplicate_source_dies_with_the_reason(): void {
		$live_id      = $this->create_redirect( '/clash', 'https://example.com/one' );
		$duplicate_id = $this->insert_redirect_post(
			array(
				'post_title'   => '/clash/',
				'post_name'    => md5( '/clash/' ),
				'post_excerpt' => 'https://example.com/two',
				'post_status'  => 'draft',
			)
		);
		$this->request( 'enable_redirect', $duplicate_id );

		$this->assert_dies_with(
			sprintf( 'Redirect #%1$d already has the source &quot;/clash&quot;, and a source can answer for only one redirect. Change the destination of #%1$d instead, or delete one of the two.', $live_id ),
			'handle_enable_redirect'
		);
		$this->assertSame( 'draft', get_post_status( $duplicate_id ) );
	}
}
