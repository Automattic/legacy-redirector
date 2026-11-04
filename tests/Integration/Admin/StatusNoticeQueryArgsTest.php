<?php
/**
 * Status notice query argument integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for a status notice's query arguments outliving its page.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::add_removable_args
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::register
 * @uses \Automattic\LegacyRedirector\Application\AmbiguousSourceException
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
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
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::__construct
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 */
final class StatusNoticeQueryArgsTest extends TestCase {

	/**
	 * The request URI before the test.
	 *
	 * @var string|null
	 */
	private ?string $request_uri;

	/**
	 * Register the arguments as the plugin does, and allow the status changes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.

		( new ValidationNotices( $this->repository(), $this->auditor() ) )->register();
		( new Capability() )->register();

		$user = wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Restore the request URI.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = $this->request_uri;

		parent::tear_down();
	}

	/**
	 * Test every argument the status handlers send is removable.
	 *
	 * Taken from the handlers' own redirects, not a copy of their lists, so an
	 * argument added to a handler later has to be registered too.
	 */
	public function test_every_argument_the_handlers_send_is_removable(): void {
		$list = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );
		$id   = $this->create_redirect( '/status-notice-args', 'https://example.com/destination' );
		$bulk = new BulkActionsHandler( $this->manager(), new RedirectBatch( new RedirectFetcher( $this->repository() ) ) );
		$row  = new StatusActionsHandler( $this->manager(), $this->repository() );

		// The ID of no redirect makes the batch report a failed row.
		$sent = array(
			$bulk->handle_bulk_actions( $list, 'enable_redirects', array( $id, PHP_INT_MAX ) ),
			$bulk->handle_bulk_actions( $list, 'disable_redirects', array( $id, PHP_INT_MAX ) ),
			$bulk->handle_bulk_actions( $list, 'test_redirects', array( $id ) ),
			$this->row_action_redirect( $row, 'disable_redirect', $id ),
			$this->row_action_redirect( $row, 'enable_redirect', $id ),
		);

		$args = array();
		foreach ( $sent as $url ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$args += $query;
		}
		unset( $args['post_type'] );

		$this->assertEqualsCanonicalizing(
			array(
				'redirect_enabled',
				'redirect_disabled',
				'redirect_source',
				'bulk_redirects_enabled',
				'bulk_redirects_disabled',
				'bulk_redirects_failed',
				'bulk_redirects_reason',
				'bulk_test_needs_js',
			),
			array_keys( $args )
		);
		$this->assertSame( array(), array_diff( array_keys( $args ), wp_removable_query_args() ) );
	}

	/**
	 * Test a notice is shown, then gone from what the rest of the page builds.
	 *
	 * Disable /a then bulk trash /b used to carry the arguments through the
	 * form's referer, and the trash enhancer overwrote the source, so the page
	 * said "Redirect from /b disabled."
	 */
	public function test_notice_arguments_do_not_reach_the_bulk_form_or_sort_links(): void {
		set_current_screen( 'edit-' . PostType::POST_TYPE );

		$_GET                   = array(
			'redirect_disabled' => '1',
			'redirect_source'   => '/a',
		);
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=' . PostType::POST_TYPE . '&s=kept&redirect_disabled=1&redirect_source=%2Fa';

		ob_start();
		( new StatusChangeNotices() )->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>/a</code>', $html );
		$this->assertSame( '/wp-admin/edit.php?post_type=' . PostType::POST_TYPE . '&s=kept', $_SERVER['REQUEST_URI'] );
		$this->assertStringNotContainsString( 'redirect_', wp_referer_field( false ) );
	}

	/**
	 * Run a row action and return where it redirects to.
	 *
	 * @param StatusActionsHandler $handler The handler.
	 * @param string               $action  The action, which names its handler method and nonce.
	 * @param int                  $id      The redirect ID.
	 * @return string The redirect's location.
	 */
	private function row_action_redirect( StatusActionsHandler $handler, string $action, int $id ): string {
		$_GET = array(
			'redirect_id' => (string) $id,
			'_wpnonce'    => wp_create_nonce( $action . '_' . $id ),
		);

		$redirect = $this->redirect_sent_by( array( $handler, 'handle_' . $action ) );
		$this->assertNotNull( $redirect );

		return $redirect['location'];
	}
}
