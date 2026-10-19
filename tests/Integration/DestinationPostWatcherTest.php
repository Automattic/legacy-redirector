<?php
/**
 * Destination post watcher integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher;

/**
 * Integration tests for flagging redirects as their destination post changes.
 *
 * The watcher is registered by the plugin's own bootstrap, so these
 * exercise it through ordinary WordPress post operations.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 */
final class DestinationPostWatcherTest extends TestCase {

	/**
	 * Clean up the stored badge counts.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( AuditFlags::COUNTS_OPTION );

		parent::tear_down();
	}

	/**
	 * The flag stored on a redirect.
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return string The flag, or '' when unflagged.
	 */
	private function flag( int $redirect_id ): string {
		return (string) get_post_meta( $redirect_id, AuditFlags::META_KEY, true );
	}

	/**
	 * Set a post's status.
	 *
	 * @param int    $post_id The post ID.
	 * @param string $status  The new status.
	 * @return void
	 */
	private function set_status( int $post_id, string $status ): void {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Test unpublishing a destination post flags its redirects and raises the badge.
	 */
	public function test_unpublishing_the_destination_flags_its_redirects(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$first   = $this->create_redirect( '/watch-draft-a', $post_id );
		$second  = $this->create_redirect( '/watch-draft-b', $post_id );

		$this->set_status( $post_id, 'draft' );

		$this->assertSame( 'problem', $this->flag( $first ) );
		$this->assertSame( 'problem', $this->flag( $second ) );
		$this->assertSame( 2, ( new AuditFlags() )->problem_count() );
	}

	/**
	 * Test trashing a destination flags it, and republishing it clears the flag.
	 */
	public function test_trashing_flags_and_republishing_clears(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/watch-trash', $post_id );

		wp_trash_post( $post_id );

		$this->assertSame( 'problem', $this->flag( $redirect_id ) );
		$this->assertSame( 1, ( new AuditFlags() )->problem_count() );

		$this->set_status( $post_id, 'publish' );

		$this->assertSame( '', $this->flag( $redirect_id ) );
		$this->assertSame( 0, ( new AuditFlags() )->problem_count() );
	}

	/**
	 * Test permanently deleting a destination flags its redirects.
	 */
	public function test_deleting_the_destination_flags_its_redirects(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/watch-delete', $post_id );

		wp_delete_post( $post_id, true );

		$this->assertSame( 'problem', $this->flag( $redirect_id ) );
	}

	/**
	 * Test deleting an attachment destination flags its redirects.
	 *
	 * An attachment's own status is 'inherit', so it is judged by the
	 * auditor's rule rather than by a 'publish' check here.
	 */
	public function test_deleting_an_attachment_destination_flags_its_redirects(): void {
		$parent        = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachment_id = self::factory()->attachment->create_object( 'watch.jpg', $parent, array( 'post_mime_type' => 'image/jpeg' ) );
		$redirect_id   = $this->create_redirect( '/watch-attachment', $attachment_id );

		$this->assertSame( '', $this->flag( $redirect_id ) );

		wp_delete_attachment( $attachment_id, true );

		$this->assertSame( 'problem', $this->flag( $redirect_id ) );
	}

	/**
	 * Test a status change of a post nothing points at costs one indexed lookup and writes nothing.
	 */
	public function test_a_post_without_redirects_costs_one_lookup(): void {
		global $wpdb;

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$watcher = new DestinationPostWatcher( $this->query_repository(), $this->auditor(), new AuditFlags() );
		$before  = $wpdb->num_queries;

		$watcher->on_transition( 'draft', 'publish', get_post( $post_id ) );

		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertFalse( get_option( AuditFlags::COUNTS_OPTION ) );
	}

	/**
	 * Test a save that keeps the status, and a redirect row's own changes, cost nothing.
	 */
	public function test_unchanged_status_and_redirect_rows_are_ignored(): void {
		global $wpdb;

		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/watch-ignored', $post_id );
		$watcher     = new DestinationPostWatcher( $this->query_repository(), $this->auditor(), new AuditFlags() );
		$before      = $wpdb->num_queries;

		$watcher->on_transition( 'publish', 'publish', get_post( $post_id ) );
		$watcher->on_transition( 'draft', 'publish', get_post( $redirect_id ) );

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}
}
