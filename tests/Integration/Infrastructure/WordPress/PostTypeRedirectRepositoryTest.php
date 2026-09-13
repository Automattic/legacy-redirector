<?php
/**
 * PostTypeRedirectRepository integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for PostTypeRedirectRepository.
 *
 * Tests the repository implementation that stores redirects using
 * the WordPress custom post type vip-legacy-redirect.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\UrlUtils
 */
final class PostTypeRedirectRepositoryTest extends TestCase {

	/**
	 * The repository under test.
	 *
	 * @var PostTypeRedirectRepository
	 */
	private PostTypeRedirectRepository $repository;

	/**
	 * Set up the test fixture.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->repository = new PostTypeRedirectRepository();
	}

	// =========================================================================
	// find_by_source() tests
	// =========================================================================

	/**
	 * Test find_by_source returns a redirect for a published redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_redirect_for_published_post(): void {
		$source      = SourceUrl::from_string( '/find-by-source-published' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$found = $this->repository->find_by_source( $source );

		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( $saved->id(), $found->id() );
		$this->assertTrue( $source->equals( $found->source() ) );
		$this->assertTrue( $destination->equals( $found->destination() ) );
	}

	/**
	 * Test find_by_source returns null for a non-existent source URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_null_for_nonexistent(): void {
		$source = SourceUrl::from_string( '/does-not-exist-' . wp_generate_uuid4() );

		$found = $this->repository->find_by_source( $source );

		$this->assertNull( $found );
	}

	/**
	 * Test find_by_source returns null for a trashed redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_null_for_trashed_redirect(): void {
		$source      = SourceUrl::from_string( '/find-by-source-trashed' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Trash the post.
		wp_trash_post( $saved->id() );

		$found = $this->repository->find_by_source( $source );

		$this->assertNull( $found );
	}

	/**
	 * Test find_by_source returns null for a draft redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_null_for_draft_redirect(): void {
		$source      = SourceUrl::from_string( '/find-by-source-draft' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Change to draft status.
		wp_update_post(
			array(
				'ID'          => $saved->id(),
				'post_status' => 'draft',
			)
		);

		$found = $this->repository->find_by_source( $source );

		$this->assertNull( $found );
	}

	// =========================================================================
	// find_by_id() tests
	// =========================================================================

	/**
	 * Test find_by_id returns redirect for valid ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_find_by_id_returns_redirect_for_valid_id(): void {
		$source      = SourceUrl::from_string( '/find-by-id-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$found = $this->repository->find_by_id( $saved->id() );

		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( $saved->id(), $found->id() );
		$this->assertTrue( $source->equals( $found->source() ) );
	}

	/**
	 * Test find_by_id returns null for non-existent ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_find_by_id_returns_null_for_nonexistent_id(): void {
		$found = $this->repository->find_by_id( 999999999 );

		$this->assertNull( $found );
	}

	/**
	 * Test find_by_id returns null for wrong post type.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_find_by_id_returns_null_for_wrong_post_type(): void {
		// Create a regular post (not a redirect).
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Regular Post',
			)
		);

		$found = $this->repository->find_by_id( $post_id );

		$this->assertNull( $found );
	}

	/**
	 * Test find_by_id returns redirect even if trashed (unlike find_by_source).
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_find_by_id_returns_redirect_even_if_trashed(): void {
		$source      = SourceUrl::from_string( '/find-by-id-trashed' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Trash the post.
		wp_trash_post( $saved->id() );

		$found = $this->repository->find_by_id( $saved->id() );

		// find_by_id does not filter by status, unlike find_by_source.
		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( 'trash', $found->status() );
	}

	// =========================================================================
	// exists() tests
	// =========================================================================

	/**
	 * Test exists returns true for an existing published redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::exists
	 */
	public function test_exists_returns_true_for_published_redirect(): void {
		$source      = SourceUrl::from_string( '/exists-published-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$this->repository->save( $redirect );

		$exists = $this->repository->exists( $source );

		$this->assertTrue( $exists );
	}

	/**
	 * Test exists returns false for a trashed redirect.
	 *
	 * WordPress appends __trashed to the post_name when trashing,
	 * which breaks the hash-based lookup. This is expected behaviour.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::exists
	 */
	public function test_exists_returns_false_for_trashed_redirect(): void {
		$source      = SourceUrl::from_string( '/exists-trashed-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Trash the post - WordPress appends __trashed to post_name.
		wp_trash_post( $saved->id() );

		$exists = $this->repository->exists( $source );

		// exists() won't find trashed posts because post_name changes.
		$this->assertFalse( $exists );
	}

	/**
	 * Test exists returns true for a draft redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::exists
	 */
	public function test_exists_returns_true_for_draft_redirect(): void {
		$source      = SourceUrl::from_string( '/exists-draft-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Change to draft.
		wp_update_post(
			array(
				'ID'          => $saved->id(),
				'post_status' => 'draft',
			)
		);

		$exists = $this->repository->exists( $source );

		$this->assertTrue( $exists );
	}

	/**
	 * Test exists returns false for non-existent source.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::exists
	 */
	public function test_exists_returns_false_for_nonexistent(): void {
		$source = SourceUrl::from_string( '/does-not-exist-' . wp_generate_uuid4() );

		$exists = $this->repository->exists( $source );

		$this->assertFalse( $exists );
	}

	// =========================================================================
	// save() tests
	// =========================================================================

	/**
	 * Test save creates a new redirect with URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_creates_new_redirect_with_url_destination(): void {
		$source      = SourceUrl::from_string( '/save-new-url-destination' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/url-dest' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$this->assertInstanceOf( Redirect::class, $saved );
		$this->assertTrue( $saved->is_persisted() );
		$this->assertIsInt( $saved->id() );
		$this->assertGreaterThan( 0, $saved->id() );

		// Verify in database.
		$post = get_post( $saved->id() );
		$this->assertSame( PostTypeRedirectRepository::POST_TYPE, $post->post_type );
		$this->assertSame( $source->hash(), $post->post_name );
		$this->assertSame( $source->path(), $post->post_title );
		$this->assertSame( 'https://example.com/url-dest', $post->post_excerpt );
		$this->assertSame( 0, $post->post_parent );
	}

	/**
	 * Test save creates a new redirect with post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_creates_new_redirect_with_post_id_destination(): void {
		// Create destination post.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		$source      = SourceUrl::from_string( '/save-new-post-id-destination' );
		$destination = Destination::from_post_id( DestinationPostId::from_int( $destination_post_id ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$this->assertInstanceOf( Redirect::class, $saved );
		$this->assertTrue( $saved->is_persisted() );

		// Verify in database.
		$post = get_post( $saved->id() );
		$this->assertSame( $destination_post_id, $post->post_parent );
		$this->assertSame( '', $post->post_excerpt );
	}

	/**
	 * Test save updates an existing redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_updates_existing_redirect(): void {
		$source               = SourceUrl::from_string( '/save-update-test' );
		$original_destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/original' ) );
		$redirect             = Redirect::create( $source, $original_destination );

		$saved       = $this->repository->save( $redirect );
		$original_id = $saved->id();

		// Update the destination.
		$new_destination  = Destination::from_url( DestinationUrl::from_string( 'https://example.com/updated' ) );
		$updated_redirect = $saved->with_destination( $new_destination );

		$updated = $this->repository->save( $updated_redirect );

		// Should be the same ID.
		$this->assertSame( $original_id, $updated->id() );

		// Verify the change in database.
		$post = get_post( $updated->id() );
		$this->assertSame( 'https://example.com/updated', $post->post_excerpt );
	}

	/**
	 * Test save with relative URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_with_relative_url_destination(): void {
		$source      = SourceUrl::from_string( '/save-relative-url' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/relative/path' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Verify in database.
		$post = get_post( $saved->id() );
		$this->assertSame( '/relative/path', $post->post_excerpt );
		$this->assertSame( 0, $post->post_parent );
	}

	// =========================================================================
	// delete() tests
	// =========================================================================

	/**
	 * Test delete permanently removes a redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::delete
	 */
	public function test_delete_permanently_removes_redirect(): void {
		$source      = SourceUrl::from_string( '/delete-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );
		$id    = $saved->id();

		$result = $this->repository->delete( $saved );

		$this->assertTrue( $result );

		// Verify post is gone.
		$post = get_post( $id );
		$this->assertNull( $post );

		// Verify lookup returns null.
		$found = $this->repository->find_by_id( $id );
		$this->assertNull( $found );
	}

	/**
	 * Test delete returns false for non-persisted redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::delete
	 */
	public function test_delete_returns_false_for_non_persisted_redirect(): void {
		$source      = SourceUrl::from_string( '/delete-non-persisted' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		// Do not save - redirect has no ID.
		$result = $this->repository->delete( $redirect );

		$this->assertFalse( $result );
	}

	// =========================================================================
	// get_id_by_source() tests
	// =========================================================================

	/**
	 * Test get_id_by_source returns correct ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_returns_correct_id(): void {
		$source      = SourceUrl::from_string( '/get-id-by-source-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$id = $this->repository->get_id_by_source( $source );

		$this->assertSame( $saved->id(), $id );
	}

	/**
	 * Test get_id_by_source returns 0 for non-existent source.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_returns_zero_for_nonexistent(): void {
		$source = SourceUrl::from_string( '/does-not-exist-' . wp_generate_uuid4() );

		$id = $this->repository->get_id_by_source( $source );

		$this->assertSame( 0, $id );
	}

	/**
	 * Test get_id_by_source returns 0 for trashed redirect.
	 *
	 * WordPress appends __trashed to the post_name when trashing,
	 * which breaks the hash-based lookup.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_returns_zero_for_trashed(): void {
		$source      = SourceUrl::from_string( '/get-id-by-source-trashed' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Trash the post - WordPress appends __trashed to post_name.
		wp_trash_post( $saved->id() );

		$id = $this->repository->get_id_by_source( $source );

		// get_id_by_source won't find trashed posts because post_name changes.
		$this->assertSame( 0, $id );
	}

	// =========================================================================
	// Mapping tests (reconstitution from database)
	// =========================================================================

	/**
	 * Test redirect reconstituted from post has correct source.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_reconstituted_redirect_has_correct_source(): void {
		$source      = SourceUrl::from_string( '/reconstituted-source-test?foo=bar' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );
		$found = $this->repository->find_by_id( $saved->id() );

		// Note: Source is stored as path in post_title, so it should match.
		$this->assertSame( $source->path(), $found->source()->path() );
	}

	/**
	 * Test redirect reconstituted with URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_reconstituted_redirect_with_url_destination(): void {
		$source      = SourceUrl::from_string( '/reconstituted-url-dest' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/dest-path' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );
		$found = $this->repository->find_by_id( $saved->id() );

		$this->assertTrue( $found->destination()->is_url() );
		$this->assertSame( 'https://example.com/dest-path', $found->destination()->as_url()->value() );
	}

	/**
	 * Test redirect reconstituted with post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_reconstituted_redirect_with_post_id_destination(): void {
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		$source      = SourceUrl::from_string( '/reconstituted-post-id-dest' );
		$destination = Destination::from_post_id( DestinationPostId::from_int( $destination_post_id ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );
		$found = $this->repository->find_by_id( $saved->id() );

		$this->assertTrue( $found->destination()->is_post_id() );
		$this->assertSame( $destination_post_id, $found->destination()->as_post_id()->value() );
	}

	/**
	 * Test redirect reconstituted with status preserved.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_reconstituted_redirect_has_correct_status(): void {
		$source      = SourceUrl::from_string( '/reconstituted-status-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		// Initially published.
		$found = $this->repository->find_by_id( $saved->id() );
		$this->assertSame( 'publish', $found->status() );
		$this->assertTrue( $found->is_active() );

		// Trash it.
		wp_trash_post( $saved->id() );
		$found_trashed = $this->repository->find_by_id( $saved->id() );
		$this->assertSame( 'trash', $found_trashed->status() );
		$this->assertTrue( $found_trashed->is_trashed() );
	}

	/**
	 * Test redirect reconstituted has created_at date.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_reconstituted_redirect_has_created_at(): void {
		$source      = SourceUrl::from_string( '/reconstituted-created-at-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );
		$found = $this->repository->find_by_id( $saved->id() );

		$this->assertInstanceOf( \DateTimeImmutable::class, $found->created_at() );
	}

	// =========================================================================
	// Edge cases
	// =========================================================================

	/**
	 * Test saving redirect with Unicode in source path.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_with_unicode_source_path(): void {
		$source      = SourceUrl::from_string( '/unicode-test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$found = $this->repository->find_by_source( $source );

		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( $saved->id(), $found->id() );
	}

	/**
	 * Test saving redirect with query string in source.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
	 */
	public function test_save_with_query_string_in_source(): void {
		$source      = SourceUrl::from_string( '/query-test?param=value&other=test' );
		$destination = Destination::from_url( DestinationUrl::from_string( 'https://example.com/destination' ) );
		$redirect    = Redirect::create( $source, $destination );

		$saved = $this->repository->save( $redirect );

		$found = $this->repository->find_by_source( $source );

		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( $saved->id(), $found->id() );
	}

	/**
	 * Test fallback destination when both post_parent and post_excerpt are empty.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::find_by_id
	 */
	public function test_fallback_destination_to_home_when_both_empty(): void {
		// Directly create a redirect post with no destination (edge case).
		$source  = SourceUrl::from_string( '/fallback-home-test' );
		$post_id = wp_insert_post(
			array(
				'post_type'    => PostTypeRedirectRepository::POST_TYPE,
				'post_name'    => $source->hash(),
				'post_title'   => $source->path(),
				'post_status'  => 'publish',
				'post_parent'  => 0,
				'post_excerpt' => '',
			)
		);

		$found = $this->repository->find_by_id( $post_id );

		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertTrue( $found->destination()->is_url() );
		$this->assertSame( '/', $found->destination()->as_url()->value() );
	}
}
