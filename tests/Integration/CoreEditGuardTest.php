<?php
/**
 * CoreEditGuard integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CoreEditGuard;
use WPDieException;

/**
 * Integration tests for the guard on core's generic post editing.
 *
 * Each test builds the request core would see, fires the hook the guard is
 * on, and checks the guard ends it before core's own handler would run.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CoreEditGuard
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 */
final class CoreEditGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var CoreEditGuard
	 */
	private CoreEditGuard $guard;

	/**
	 * A redirect row.
	 *
	 * @var int
	 */
	private int $redirect_id;

	/**
	 * A normal post.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->guard = new CoreEditGuard();
		$this->guard->register();

		$user = wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );

		$this->redirect_id = $this->insert_redirect_post(
			array(
				'post_title' => '/old-page',
				'post_name'  => md5( '/old-page' ),
			)
		);
		$this->post_id     = self::factory()->post->create();

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Clean up request superglobals and the current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Test a Quick Edit save of a redirect is refused and the row left as it was.
	 */
	public function test_quick_edit_of_a_redirect_is_refused(): void {
		$before = $this->snapshot( $this->redirect_id );
		$this->request( array(), array( 'post_ID' => (string) $this->redirect_id ) );

		$this->assert_refused( 'wp_ajax_inline-save' );
		$this->assertSame( $before, $this->snapshot( $this->redirect_id ) );
	}

	/**
	 * Test a Quick Edit save of a normal post is left to core.
	 */
	public function test_quick_edit_of_a_post_is_allowed(): void {
		$this->request( array(), array( 'post_ID' => (string) $this->post_id ) );

		$this->assert_not_refused( 'wp_ajax_inline-save' );
	}

	/**
	 * Test every edit screen action that would save a redirect is refused.
	 *
	 * @dataProvider data_edit_screen_writes
	 *
	 * @param string                $where Which superglobal names the redirect: 'get' or 'post'.
	 * @param string                $field The field naming it.
	 * @param array<string, string> $extra Other request fields.
	 */
	public function test_edit_screen_write_to_a_redirect_is_refused( string $where, string $field, array $extra ): void {
		$before = $this->snapshot( $this->redirect_id );
		$target = array( $field => (string) $this->redirect_id );

		$this->request( 'get' === $where ? $target + $extra : $extra, 'post' === $where ? $target + $extra : array() );

		$this->assert_refused( 'load-post.php' );
		$this->assertSame( $before, $this->snapshot( $this->redirect_id ) );
	}

	/**
	 * Requests to post.php that save a redirect.
	 *
	 * @return array<string, array{string, string, array<string, string>}>
	 */
	public static function data_edit_screen_writes(): array {
		return array(
			'classic editor save'               => array( 'post', 'post_ID', array( 'action' => 'editpost' ) ),
			'save addressed by query string'    => array( 'get', 'post', array( 'action' => 'editpost' ) ),
			'edit screen without a GET action'  => array( 'post', 'post_ID', array( 'action' => 'edit' ) ),
			'preview overriding a trash action' => array(
				'post',
				'post_ID',
				array(
					'action'     => 'trash',
					'wp-preview' => 'dopreview',
				),
			),
			'quick draft save'                  => array( 'get', 'post_ID', array( 'action' => 'post-quickdraft-save' ) ),
		);
	}

	/**
	 * Test a new redirect cannot be written through post.php.
	 */
	public function test_edit_screen_create_of_a_redirect_is_refused(): void {
		$this->request(
			array(),
			array(
				'action'    => 'post',
				'post_type' => 'vip-legacy-redirect',
			)
		);

		$this->assert_refused( 'load-post.php' );
	}

	/**
	 * Test the edit screen save of a normal post is left to core.
	 */
	public function test_edit_screen_save_of_a_post_is_allowed(): void {
		$this->request(
			array(),
			array(
				'action'  => 'editpost',
				'post_ID' => (string) $this->post_id,
			)
		);

		$this->assert_not_refused( 'load-post.php' );
	}

	/**
	 * Test the row links' trash, restore and delete still reach a redirect.
	 *
	 * They go through core's post.php, so the guard must let them by, and
	 * core's own functions must still change the row.
	 */
	public function test_trash_restore_and_delete_links_still_work(): void {
		foreach ( array( 'trash', 'untrash', 'delete' ) as $action ) {
			$this->request(
				array(
					'action' => $action,
					'post'   => (string) $this->redirect_id,
				),
				array()
			);
			$this->assert_not_refused( 'load-post.php' );
		}

		$this->request( array(), array( 'deletepost' => 'Move to Trash' ) + array( 'post_ID' => (string) $this->redirect_id ) );
		$this->assert_not_refused( 'load-post.php' );

		wp_trash_post( $this->redirect_id );
		$this->assertSame( 'trash', get_post_status( $this->redirect_id ) );

		wp_untrash_post( $this->redirect_id );
		$this->assertSame( 'draft', get_post_status( $this->redirect_id ) );
		$this->assertSame( md5( '/old-page' ), get_post_field( 'post_name', $this->redirect_id ) );

		wp_delete_post( $this->redirect_id, true );
		$this->assertNull( get_post( $this->redirect_id ) );
	}

	/**
	 * Test a Bulk Edit that includes a redirect is refused, from any list screen.
	 */
	public function test_bulk_edit_including_a_redirect_is_refused(): void {
		$before = $this->snapshot( $this->redirect_id );
		$this->request(
			array(
				'post_type' => 'post',
				'action'    => 'edit',
				'bulk_edit' => 'Update',
				'post'      => array( (string) $this->post_id, (string) $this->redirect_id ),
				'_status'   => 'private',
			),
			array()
		);

		$this->assert_refused( 'load-edit.php' );
		$this->assertSame( $before, $this->snapshot( $this->redirect_id ) );
	}

	/**
	 * Test a Bulk Edit of normal posts, and a bulk trash of redirects, are left to core.
	 */
	public function test_bulk_edit_of_posts_and_bulk_trash_are_allowed(): void {
		$this->request(
			array(
				'action'    => 'edit',
				'bulk_edit' => 'Update',
				'post'      => array( (string) $this->post_id ),
			),
			array()
		);
		$this->assert_not_refused( 'load-edit.php' );

		$this->request(
			array(
				'post_type' => 'vip-legacy-redirect',
				'action'    => 'trash',
				'post'      => array( (string) $this->redirect_id ),
			),
			array()
		);
		$this->assert_not_refused( 'load-edit.php' );
	}

	/**
	 * Test XML-RPC clients lose edit_post on a redirect, and only on a redirect.
	 */
	public function test_xmlrpc_edit_of_a_redirect_is_refused(): void {
		$this->assertTrue( current_user_can( 'edit_post', $this->redirect_id ), 'The fixture user should be able to edit the redirect outside XML-RPC.' );

		add_filter( 'map_meta_cap', array( $this->guard, 'refuse_xmlrpc_edit' ), 10, 4 );

		$this->assertFalse( current_user_can( 'edit_post', $this->redirect_id ) );
		$this->assertFalse( current_user_can( 'edit_post', get_post( $this->redirect_id ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $this->post_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $this->redirect_id ) );
	}

	/**
	 * Set the request superglobals.
	 *
	 * @param array<string, mixed> $get  Query string fields.
	 * @param array<string, mixed> $post Body fields.
	 * @return void
	 */
	private function request( array $get, array $post ): void {
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );
	}

	/**
	 * The fields of a row a core save could change.
	 *
	 * @param int $post_id The post ID.
	 * @return array<string, mixed>
	 */
	private function snapshot( int $post_id ): array {
		clean_post_cache( $post_id );

		return array_intersect_key(
			get_post( $post_id, ARRAY_A ),
			array_flip( array( 'post_name', 'post_title', 'post_status', 'post_excerpt', 'post_parent', 'post_author' ) )
		);
	}

	/**
	 * Fire a hook and assert the guard ends the request with a 403.
	 *
	 * @param string $hook The hook to fire.
	 * @return void
	 */
	private function assert_refused( string $hook ): void {
		try {
			do_action( $hook );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'Redirects can only be edited from the Redirects screens.', $e->getMessage() );
			return;
		}

		$this->fail( 'Expected the guard to refuse ' . $hook . '.' );
	}

	/**
	 * Fire a hook and assert the guard lets the request through.
	 *
	 * @param string $hook The hook to fire.
	 * @return void
	 */
	private function assert_not_refused( string $hook ): void {
		try {
			do_action( $hook );
		} catch ( WPDieException $e ) {
			$this->fail( 'Expected the guard to let ' . $hook . ' through, but it died with: ' . $e->getMessage() );
		}

		$this->addToAssertionCount( 1 );
	}
}
