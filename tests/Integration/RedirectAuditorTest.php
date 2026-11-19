<?php
/**
 * RedirectAuditor integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;

/**
 * The auditor's checks of stored redirects against a real site: how
 * WordPress reports a destination post's status, and how its paths and
 * permalinks resolve. The unit RedirectAuditorTest covers the branching
 * with those answers stubbed; the validate command's own tests cover what
 * it does with the findings.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 * @uses \Automattic\LegacyRedirector\Domain\AuditDetail
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository::find_by_destination_post
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 */
final class RedirectAuditorTest extends TestCase {

	/**
	 * Audit a stored redirect, as validate does, and return the finding types.
	 *
	 * @param int $redirect_id The redirect's ID.
	 * @return AuditFindingType[] The finding types, in the auditor's order.
	 */
	private function finding_types( int $redirect_id ): array {
		return array_map(
			static fn( AuditFinding $finding ): AuditFindingType => $finding->type(),
			$this->auditor()->audit( $this->repository()->find_by_id( $redirect_id ) )
		);
	}

	/**
	 * Test an attachment destination is not reported as unpublished.
	 *
	 * Attachments carry post_status 'inherit', never 'publish', so reading the
	 * raw property would flag every media destination as broken.
	 */
	public function test_attachment_destination_is_not_unpublished(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'brochure.pdf',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Brochure',
			)
		);
		$redirect_id   = $this->create_redirect( '/attachment-dest', $attachment_id );

		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );
		$this->assertSame( array(), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test a relative-path destination whose post is trashed is reported.
	 *
	 * Trashing renames the post's slug with a __trashed suffix, so a plain
	 * path lookup misses it; the auditor must check the renamed slug.
	 */
	public function test_trashed_relative_path_destination_is_reported(): void {
		$post_id     = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'trashed-path-target',
			)
		);
		$redirect_id = $this->create_redirect( '/trashed-path-source', '/trashed-path-target' );

		wp_trash_post( $post_id );

		$this->assertSame( array( AuditFindingType::POST_TRASHED ), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test a destination of '/' is not judged by an unrelated post's status.
	 *
	 * The home page has no slug, and get_page_by_path( '' ) matches any post
	 * with an empty post_name - which every draft and pending post has.
	 */
	public function test_home_destination_ignores_posts_with_no_slug(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'pending',
				'post_name'   => '',
			)
		);
		$redirect_id = $this->create_redirect( '/home-destination', '/' );

		$this->assertSame( array(), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test an unpublished custom post type destination is reported.
	 *
	 * The destination resolver searches every registered post type, so a
	 * redirect to a custom post type item is judged by that post's status
	 * instead of being treated as unresolvable.
	 */
	public function test_unpublished_custom_post_type_destination_is_reported(): void {
		register_post_type( 'book', array( 'public' => true ) );

		try {
			$book_id     = self::factory()->post->create(
				array(
					'post_type'   => 'book',
					'post_status' => 'publish',
					'post_name'   => 'audited-book',
				)
			);
			$redirect_id = $this->create_redirect( '/book-source', '/audited-book' );
			wp_update_post(
				array(
					'ID'          => $book_id,
					'post_status' => 'draft',
				)
			);

			$this->assertSame( array( AuditFindingType::POST_UNPUBLISHED ), $this->finding_types( $redirect_id ) );
		} finally {
			unregister_post_type( 'book' );
		}
	}

	/**
	 * Test an unpublished destination behind a dated permalink is reported.
	 *
	 * A dated permalink cannot be walked as a hierarchical slug, so the
	 * resolver falls back to url_to_postid() to find the post. That fallback
	 * resolves an unpublished post only for a user who can edit it - which is
	 * exactly who runs `validate` - so the check runs as an administrator.
	 */
	public function test_unpublished_dated_permalink_destination_is_reported(): void {
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'dated-target',
				'post_date'   => '2020-01-15 12:00:00',
			)
		);
		$path    = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
		$this->assertStringContainsString( '2020', $path );

		$redirect_id = $this->create_redirect( '/dated-source', $path );

		$this->assertSame( array(), $this->finding_types( $redirect_id ), 'A published post behind the dated permalink resolves cleanly.' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( array( AuditFindingType::POST_UNPUBLISHED ), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test a destination host missing from allowed_redirect_hosts is reported.
	 *
	 * At request time wp_safe_redirect() quietly sends visitors elsewhere,
	 * so the redirect is broken without any HTTP request being needed.
	 */
	public function test_disallowed_external_host_is_reported(): void {
		$redirect_id = $this->create_redirect( '/external-source', 'https://not-allowed.example.net/page' );

		$this->assertSame( array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED ), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test a loop across two redirects is reported on both members.
	 */
	public function test_loop_is_reported_on_each_member(): void {
		$first  = $this->create_redirect( '/loop-a', '/loop-b' );
		$second = $this->create_redirect( '/loop-b', '/loop-a' );

		$this->assertSame( array( AuditFindingType::POSSIBLE_LOOP ), $this->finding_types( $first ) );
		$this->assertSame( array( AuditFindingType::POSSIBLE_LOOP ), $this->finding_types( $second ) );
	}

	/**
	 * Test a stored self-loop is reported.
	 *
	 * The write gate refuses these, but --skip-validation and legacy data can
	 * still store them.
	 */
	public function test_stored_self_loop_is_reported(): void {
		$redirect_id = $this->create_redirect( '/self-loop', '/self-loop' );

		$this->assertSame( array( AuditFindingType::POSSIBLE_LOOP ), $this->finding_types( $redirect_id ) );
	}

	/**
	 * Test a redirect chain that ends free is not reported as a loop.
	 */
	public function test_chain_is_not_a_loop(): void {
		$first  = $this->create_redirect( '/hop-one', '/hop-two' );
		$second = $this->create_redirect( '/hop-two', '/somewhere-final' );

		$this->assertSame( array(), $this->finding_types( $first ) );
		$this->assertSame( array(), $this->finding_types( $second ) );
	}
}
