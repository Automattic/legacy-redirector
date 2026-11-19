<?php
/**
 * Post type tests
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Post type tests class.
 */
final class PostTypeTest extends TestCase {
	/**
	 * Test that the post type exists.
	 *
	 * @coversNothing
	 */
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( PostType::POST_TYPE ) );
	}

	/**
	 * Test that redirect records cannot be requested from the front end.
	 *
	 * Redirect posts hold the source path in the title and the destination in
	 * the excerpt, so a publicly queryable post type would leak the mappings
	 * via /?post_type=vip-legacy-redirect&name=<md5-of-source-path>.
	 *
	 * @coversNothing
	 */
	public function test_post_type_is_not_publicly_queryable() {
		$this->assertFalse( is_post_type_viewable( PostType::POST_TYPE ) );
	}

	/**
	 * Test that the redirects list screen is gated on manage_redirects.
	 *
	 * The list screen (wp-admin/edit.php) checks the post type's edit_posts
	 * capability, so an Author (who has edit_posts for regular posts) must
	 * fail it, while a user with manage_redirects must pass. Redirect maps
	 * can reveal unpublished slugs and internal structure, so lower-privileged
	 * users must not be able to read them.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::register
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
	 */
	public function test_list_screen_requires_manage_redirects() {
		( new Capability() )->register();

		$list_screen_capability = get_post_type_object( PostType::POST_TYPE )->cap->edit_posts;

		$this->assertSame( Capability::MANAGE_REDIRECTS_CAPABILITY, $list_screen_capability );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( current_user_can( $list_screen_capability ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( $list_screen_capability ) );
	}

	/**
	 * Test the single-item trash notice shows an encoded source as stored.
	 *
	 * The source arrives already decoded once by PHP. Decoding it again turned
	 * a stored %2F into a slash, and sanitize_text_field() then deleted any
	 * %xx sequence left, so the notice named a different redirect. Core then
	 * runs sprintf() over the message to insert the count, which read %2F as
	 * a float and threw on %C3, so the assertion goes through that step too.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::bulk_post_updated_messages
	 */
	public function test_trash_notice_shows_encoded_source_as_stored() {
		$_GET['redirect_source'] = wp_slash( '/a%2Fb?q=caf%C3%A9' );

		$messages = ( new PostType() )->bulk_post_updated_messages(
			array(),
			array(
				'updated'   => 0,
				'locked'    => 0,
				'deleted'   => 0,
				'trashed'   => 1,
				'untrashed' => 0,
			)
		);

		unset( $_GET['redirect_source'] );

		// Mirror wp-admin/edit.php, which inserts the count with sprintf().
		$notice = sprintf( $messages[ PostType::POST_TYPE ]['trashed'], number_format_i18n( 1 ) );

		$this->assertStringContainsString( '<code>/a%2Fb?q=caf%C3%A9</code>', $notice );
	}

	/**
	 * Test the trash notice escapes the source it reads from the URL.
	 *
	 * The source comes from $_GET with no nonce, so a crafted link could carry
	 * markup; escaping on output is the only defense.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::bulk_post_updated_messages
	 */
	public function test_trash_notice_escapes_the_source() {
		$_GET['redirect_source'] = wp_slash( '/<script>alert(1)</script>' );

		$messages = ( new PostType() )->bulk_post_updated_messages(
			array(),
			array(
				'updated'   => 0,
				'locked'    => 0,
				'deleted'   => 0,
				'trashed'   => 1,
				'untrashed' => 0,
			)
		);

		unset( $_GET['redirect_source'] );

		$this->assertStringNotContainsString( '<script>', $messages[ PostType::POST_TYPE ]['trashed'] );
		$this->assertStringContainsString( '&lt;script&gt;', $messages[ PostType::POST_TYPE ]['trashed'] );
	}

	/**
	 * Test editing, deleting and creating redirects through core all need manage_redirects.
	 *
	 * Core's post.php, the trash link and the REST API check these meta
	 * capabilities, so an Author must fail them even on a redirect they wrote,
	 * and nobody may create one through core, which bypasses the plugin's
	 * save rules.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::register
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
	 */
	public function test_core_capabilities_on_a_redirect_require_manage_redirects() {
		( new Capability() )->register();

		$author_id   = self::factory()->user->create( array( 'role' => 'author' ) );
		$redirect_id = self::factory()->post->create(
			array(
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		wp_set_current_user( $author_id );
		$this->assertFalse( current_user_can( 'edit_post', $redirect_id ), 'An Author cannot edit a redirect.' );
		$this->assertFalse( current_user_can( 'delete_post', $redirect_id ), 'An Author cannot delete a redirect.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $redirect_id ), 'A redirect manager can edit a redirect.' );
		$this->assertTrue( current_user_can( 'delete_post', $redirect_id ), 'A redirect manager can delete a redirect.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( current_user_can( get_post_type_object( PostType::POST_TYPE )->cap->create_posts ), 'Nobody creates a redirect through core.' );
	}

	/**
	 * Test trashing one redirect names it in the notice, through both halves.
	 *
	 * TrashRedirectEnhancer writes the source into core's redirect after a
	 * trash, encoded, and the notice reads it back. Each half was only ever
	 * tested against what the other was assumed to do.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::bulk_post_updated_messages
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
	 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
	 * @uses \Automattic\LegacyRedirector\Domain\Url
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
	 */
	public function test_trashing_one_redirect_names_it_in_the_notice() {
		$redirect_id = $this->create_redirect( '/a%2Fb?q=caf%C3%A9', 'https://example.com/destination' );
		wp_trash_post( $redirect_id );

		$location = ( new TrashRedirectEnhancer( $this->repository() ) )->add_source_to_redirect(
			admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&trashed=1&ids=' . $redirect_id )
		);

		// PHP decodes the query string once into $_GET, as parse_str() does.
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET['redirect_source'] = wp_slash( $query['redirect_source'] );

		$messages = ( new PostType() )->bulk_post_updated_messages(
			array(),
			array(
				'updated'   => 0,
				'locked'    => 0,
				'deleted'   => 0,
				'trashed'   => 1,
				'untrashed' => 0,
			)
		);

		unset( $_GET['redirect_source'] );

		// Mirror wp-admin/edit.php, which inserts the count with sprintf().
		$notice = sprintf( $messages[ PostType::POST_TYPE ]['trashed'], number_format_i18n( 1 ) );

		$this->assertStringContainsString( '<code>/a%2Fb?q=café</code>', $notice, 'The notice names the source as stored, query decoded.' );
	}

	/**
	 * Test the enhancer leaves every other redirect alone.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer
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
	 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
	 * @uses \Automattic\LegacyRedirector\Domain\Url
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
	 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
	 * @dataProvider data_redirects_the_enhancer_leaves_alone
	 *
	 * @param string $query The query string of core's redirect, with {id} for a trashed redirect's ID.
	 */
	public function test_the_trash_enhancer_leaves_other_redirects_alone( string $query ) {
		$redirect_id = $this->create_redirect( '/left-alone', 'https://example.com/destination' );
		wp_trash_post( $redirect_id );
		$location = admin_url( 'edit.php?' . str_replace( '{id}', (string) $redirect_id, $query ) );

		$this->assertSame( $location, ( new TrashRedirectEnhancer( $this->repository() ) )->add_source_to_redirect( $location ) );
	}

	/**
	 * Data provider for redirects the trash enhancer must not change.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_redirects_the_enhancer_leaves_alone(): array {
		return array(
			'several trashed'   => array( 'post_type=vip-legacy-redirect&trashed=2&ids={id},999' ),
			'another post type' => array( 'post_type=post&trashed=1&ids={id}' ),
			'not a trash'       => array( 'post_type=vip-legacy-redirect&untrashed=1&ids={id}' ),
			'no ids'            => array( 'post_type=vip-legacy-redirect&trashed=1' ),
			'no such redirect'  => array( 'post_type=vip-legacy-redirect&trashed=1&ids=999999' ),
		);
	}
}
