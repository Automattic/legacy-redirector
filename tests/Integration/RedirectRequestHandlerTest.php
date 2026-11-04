<?php
/**
 * Front-end redirect integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler;

/**
 * Runs the handler against real WordPress requests, as template_redirect would.
 *
 * The unit test stubs wp_validate_redirect(), so only here does the real
 * allowed_redirect_hosts rule decide whether a visitor is sent on.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectRequestHandler
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 */
final class RedirectRequestHandlerTest extends TestCase {

	/**
	 * Use pretty permalinks, so an unknown path is a 404 the handler acts on.
	 */
	public function set_up() {
		parent::set_up();

		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Request a path as a visitor and return the redirect the handler sends for it.
	 *
	 * TestCase defines WP_ADMIN, and core never marks an admin request as a
	 * 404, so after the request core's own 404 check runs again on the front
	 * screen, where is_admin() is false.
	 *
	 * @param string $path The request path.
	 * @return array{location: string, status: int, by: string}|null The redirect, or null if none was sent.
	 */
	private function request( string $path ): ?array {
		$this->go_to( $path );
		set_current_screen( 'front' );
		$GLOBALS['wp']->handle_404();

		return $this->redirect_sent_by( array( new RedirectRequestHandler( $this->resolver() ), 'maybe_redirect' ) );
	}

	/**
	 * Test a 404 for a stored source is sent to its destination as a 301.
	 */
	public function test_redirects_a_404_to_its_destination(): void {
		$this->create_redirect( '/handler-old', '/handler-new' );

		$this->assertSame(
			array(
				'location' => home_url( '/handler-new' ),
				'status'   => 301,
				'by'       => 'Legacy Redirector',
			),
			$this->request( '/handler-old' )
		);
	}

	/**
	 * Test a status from the redirect status filter reaches the response.
	 */
	public function test_filtered_status_reaches_the_response(): void {
		$this->create_redirect( '/handler-temporary', '/handler-new' );
		add_filter( 'legacy_redirector_redirect_status', static fn() => 302 );

		$this->assertSame( 302, $this->request( '/handler-temporary' )['status'] ?? null );
	}

	/**
	 * Test an external destination on an allowed host is redirected to.
	 */
	public function test_redirects_to_an_allowed_external_host(): void {
		$this->create_redirect( '/handler-external', 'https://example.com/landing' );

		$this->assertSame( 'https://example.com/landing', $this->request( '/handler-external' )['location'] ?? null );
	}

	/**
	 * Test a destination on a host outside allowed_redirect_hosts is left to the 404.
	 *
	 * Handing it to wp_safe_redirect() would send the visitor to admin_url().
	 */
	public function test_leaves_a_refused_host_to_the_404(): void {
		$this->create_redirect( '/handler-refused', 'https://not-allowed.example.org/landing' );

		$this->assertNull( $this->request( '/handler-refused' ) );
		$this->assertTrue( is_404() );
	}

	/**
	 * Test a redirect that is not live does not fire.
	 *
	 * @dataProvider data_statuses_that_do_not_fire
	 *
	 * @param string $status The post status to give the redirect.
	 */
	public function test_a_redirect_that_is_not_live_does_not_fire( string $status ): void {
		$redirect_id = $this->create_redirect( '/handler-' . $status, '/handler-new' );
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => $status,
			)
		);

		$this->assertNull( $this->request( '/handler-' . $status ) );
	}

	/**
	 * Data provider for redirect statuses that must not fire.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_statuses_that_do_not_fire(): array {
		return array(
			'draft' => array( 'draft' ),
			'trash' => array( 'trash' ),
		);
	}

	/**
	 * Test a request that WordPress answers is not redirected, even when a redirect exists for it.
	 */
	public function test_does_not_redirect_a_request_that_is_not_a_404(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'handler-real-page',
				'post_status' => 'publish',
			)
		);
		$this->create_redirect( '/handler-real-page', '/handler-new' );

		$this->assertNull( $this->request( '/handler-real-page' ) );
	}
}
