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
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
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

		$this->handler = new StatusActionsHandler( $this->manager() );

		( new Capability() )->register();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );

		$_GET = array();
	}

	/**
	 * Clean up request superglobals and capabilities.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();

		wp_set_current_user( 0 );
		( new Capability() )->unregister();

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
	 * either check alone. It guards the behaviour, not one particular guard.
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
}
