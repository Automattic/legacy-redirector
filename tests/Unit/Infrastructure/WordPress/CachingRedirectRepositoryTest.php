<?php
/**
 * CachingRedirectRepository unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * CachingRedirectRepositoryTest class.
 *
 * Tests the caching decorator for the redirect repository.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class CachingRedirectRepositoryTest extends MonkeyStubs {

	/**
	 * The mock inner repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $inner;

	/**
	 * The repository under test.
	 *
	 * @var CachingRedirectRepository
	 */
	private CachingRedirectRepository $repository;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$this->inner      = Mockery::mock( RedirectRepositoryInterface::class );
		$this->repository = new CachingRedirectRepository( $this->inner );
	}

	/**
	 * Creates a test source URL.
	 *
	 * @param string $path The URL path.
	 * @return SourceUrl
	 */
	private function create_source( string $path = '/old-page' ): SourceUrl {
		return SourceUrl::from_string( $path );
	}

	/**
	 * Creates a test redirect.
	 *
	 * @param int    $id     The redirect ID.
	 * @param string $path   The source path.
	 * @param string $status The redirect status.
	 * @return Redirect
	 */
	private function create_redirect( int $id, string $path = '/old-page', string $status = 'publish' ): Redirect {
		return Redirect::reconstitute(
			$id,
			SourceUrl::from_string( $path ),
			Destination::from_url( DestinationUrl::from_string( '/new-page' ) ),
			$status
		);
	}

	/**
	 * Test find_by_source resolves and caches the true row ID on cache miss.
	 *
	 * The miss path routes through the shared get_id_by_source() lookup, so
	 * the cached entry always holds the actual ID, never a mapped-entity 0.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_cache_miss_delegates_to_inner(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 123 );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 123, CachingRedirectRepository::CACHE_GROUP, 0 )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->hash() === $source->hash() ) )
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$result = $this->repository->find_by_source( $source );

		$this->assertSame( $redirect, $result );
	}

	/**
	 * Test find_by_source caches 0 on cache miss when no row exists.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_cache_miss_caches_zero_when_not_found(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 0, CachingRedirectRepository::CACHE_GROUP, CachingRedirectRepository::NEGATIVE_CACHE_TTL )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		$this->inner->shouldNotReceive( 'find_by_id' );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source returns null for a corrupt row without caching 0.
	 *
	 * The row exists, so the shared cache entry must hold its true ID, not a
	 * "no redirect" marker that would mislead duplicate checks for 300s.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_corrupt_row_returns_null_but_caches_true_id(): void {
		$source  = $this->create_source();
		$corrupt = Redirect::reconstitute(
			123,
			SourceUrl::from_string( '/__corrupt__/123' ),
			Destination::from_url( DestinationUrl::home() ),
			'publish',
			null,
			'Invalid source: The URL does not validate.'
		);

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 123, CachingRedirectRepository::CACHE_GROUP, 0 )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )->never();

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $corrupt );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source caches a disabled redirect's ID, not a zero.
	 *
	 * The cache entry answers "which post holds this source", so a management
	 * lookup reading it afterwards must still see the disabled redirect. If the
	 * publish-only filter were applied before caching, the entry would say "no
	 * redirect" while the repository's duplicate guard still saw one.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_caches_id_of_disabled_redirect(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 123, '/old-page', 'draft' );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 123, CachingRedirectRepository::CACHE_GROUP, 0 )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$this->assertNull( $this->repository->find_by_source( $source ) );
	}

	/**
	 * Test find_by_source returns null for cached zero without hitting inner.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_cached_zero_returns_null_without_inner_call(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 0 );

		$this->inner->shouldNotReceive( 'find_by_source' );
		$this->inner->shouldNotReceive( 'find_by_id' );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source handles cached zero as string.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_cached_zero_string_returns_null(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( '0' );

		$this->inner->shouldNotReceive( 'find_by_source' );
		$this->inner->shouldNotReceive( 'find_by_id' );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source uses cached ID to find by ID on cache hit.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_cache_hit_uses_find_by_id(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 456 );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 456 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 456 )
			->andReturn( $redirect );

		$this->inner->shouldNotReceive( 'find_by_source' );

		$result = $this->repository->find_by_source( $source );

		$this->assertSame( $redirect, $result );
	}

	/**
	 * Test find_by_source updates cache to zero when cached post no longer exists.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_updates_cache_when_cached_post_deleted(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 789 );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $source->hash(), 0, CachingRedirectRepository::CACHE_GROUP, CachingRedirectRepository::NEGATIVE_CACHE_TTL )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 789 )
			->andReturn( null );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source returns null for inactive (non-published) redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_null_for_trashed_redirect(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 123, '/old-page', 'trash' );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source returns null for draft redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_null_for_draft_redirect(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 123, '/old-page', 'draft' );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$result = $this->repository->find_by_source( $source );

		$this->assertNull( $result );
	}

	/**
	 * Test find_by_source returns published redirect from cache hit.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_source
	 */
	public function test_find_by_source_returns_published_redirect(): void {
		$source   = $this->create_source();
		$redirect = $this->create_redirect( 123, '/old-page', 'publish' );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$result = $this->repository->find_by_source( $source );

		$this->assertSame( $redirect, $result );
		$this->assertTrue( $result->is_active() );
	}

	/**
	 * Test find_by_id passes through to inner repository.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_id
	 */
	public function test_find_by_id_passes_through_to_inner(): void {
		$redirect = $this->create_redirect( 123 );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		$result = $this->repository->find_by_id( 123 );

		$this->assertSame( $redirect, $result );
	}

	/**
	 * Test find_by_id returns null when not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::find_by_id
	 */
	public function test_find_by_id_returns_null_when_not_found(): void {
		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 999 )
			->andReturn( null );

		$result = $this->repository->find_by_id( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test exists passes through to inner repository.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::exists
	 */
	public function test_exists_passes_through_to_inner(): void {
		$source = $this->create_source();

		$this->inner
			->shouldReceive( 'exists' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->hash() === $source->hash() ) )
			->andReturn( true );

		$result = $this->repository->exists( $source );

		$this->assertTrue( $result );
	}

	/**
	 * Test exists returns false when not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::exists
	 */
	public function test_exists_returns_false_when_not_found(): void {
		$source = $this->create_source( '/nonexistent' );

		$this->inner
			->shouldReceive( 'exists' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->hash() === $source->hash() ) )
			->andReturn( false );

		$result = $this->repository->exists( $source );

		$this->assertFalse( $result );
	}

	/**
	 * Test save invalidates cache before saving.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::save
	 */
	public function test_save_invalidates_cache_before_saving(): void {
		$redirect       = $this->create_redirect( 123 );
		$saved_redirect = $this->create_redirect( 123 );

		// Persisted redirect: save() checks the stored version for a source change.
		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $redirect );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $redirect->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $saved_redirect->source()->hash(), 123, CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'save' )
			->once()
			->with( $redirect )
			->andReturn( $saved_redirect );

		$result = $this->repository->save( $redirect );

		$this->assertSame( $saved_redirect, $result );
	}

	/**
	 * Test save invalidates the old source when an update changes the source.
	 *
	 * Without this, the old source's positive cache entry would keep serving
	 * the redirect indefinitely after an edit-screen source change.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::save
	 */
	public function test_save_invalidates_old_source_when_source_changes(): void {
		$existing = $this->create_redirect( 123 );
		$updated  = $existing->with_source( SourceUrl::from_string( '/renamed-source' ) );

		$this->inner
			->shouldReceive( 'find_by_id' )
			->once()
			->with( 123 )
			->andReturn( $existing );

		// Old source invalidated first, then the new source.
		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $existing->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $updated->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $updated->source()->hash(), 123, CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'save' )
			->once()
			->with( $updated )
			->andReturn( $updated );

		$result = $this->repository->save( $updated );

		$this->assertSame( $updated, $result );
	}

	/**
	 * Test save pre-warms cache with new ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::save
	 */
	public function test_save_prewarms_cache_with_new_id(): void {
		$new_redirect   = Redirect::create(
			SourceUrl::from_string( '/new-source' ),
			Destination::from_url( DestinationUrl::from_string( '/destination' ) )
		);
		$saved_redirect = $new_redirect->with_id( 456 );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $new_redirect->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $saved_redirect->source()->hash(), 456, CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'save' )
			->once()
			->with( $new_redirect )
			->andReturn( $saved_redirect );

		$result = $this->repository->save( $new_redirect );

		$this->assertSame( 456, $result->id() );
	}

	/**
	 * Test delete invalidates cache before deleting.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::delete
	 */
	public function test_delete_invalidates_cache_before_deleting(): void {
		$redirect = $this->create_redirect( 123 );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $redirect->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $redirect->source()->hash(), 0, CachingRedirectRepository::CACHE_GROUP, CachingRedirectRepository::NEGATIVE_CACHE_TTL )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'delete' )
			->once()
			->with( $redirect )
			->andReturn( true );

		$result = $this->repository->delete( $redirect );

		$this->assertTrue( $result );
	}

	/**
	 * Test delete marks as deleted (0) in cache on success.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::delete
	 */
	public function test_delete_marks_as_deleted_in_cache(): void {
		$redirect = $this->create_redirect( 789 );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $redirect->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->once()
			->with( '1:' . $redirect->source()->hash(), 0, CachingRedirectRepository::CACHE_GROUP, CachingRedirectRepository::NEGATIVE_CACHE_TTL )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'delete' )
			->once()
			->andReturn( true );

		$this->repository->delete( $redirect );
	}

	/**
	 * Test delete does not mark as deleted in cache on failure.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::delete
	 */
	public function test_delete_does_not_mark_as_deleted_on_failure(): void {
		$redirect = $this->create_redirect( 123 );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( '1:' . $redirect->source()->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( true );

		Functions\expect( 'wp_cache_set' )
			->never();

		$this->inner
			->shouldReceive( 'delete' )
			->once()
			->andReturn( false );

		$result = $this->repository->delete( $redirect );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_id_by_source delegates to inner on cache miss.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_cache_miss_delegates_to_inner(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 123, CachingRedirectRepository::CACHE_GROUP, 0 )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->hash() === $source->hash() ) )
			->andReturn( 123 );

		$result = $this->repository->get_id_by_source( $source );

		$this->assertSame( 123, $result );
	}

	/**
	 * Test get_id_by_source caches zero when not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_caches_zero_when_not_found(): void {
		$source = $this->create_source( '/nonexistent' );

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( false );

		Functions\expect( 'wp_cache_add' )
			->once()
			->with( '1:' . $source->hash(), 0, CachingRedirectRepository::CACHE_GROUP, CachingRedirectRepository::NEGATIVE_CACHE_TTL )
			->andReturn( true );

		$this->inner
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		$result = $this->repository->get_id_by_source( $source );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test get_id_by_source returns cached value on cache hit.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_cache_hit_returns_cached_value(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 456 );

		$this->inner->shouldNotReceive( 'get_id_by_source' );

		$result = $this->repository->get_id_by_source( $source );

		$this->assertSame( 456, $result );
	}

	/**
	 * Test get_id_by_source returns cached zero.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_cache_hit_returns_cached_zero(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( 0 );

		$this->inner->shouldNotReceive( 'get_id_by_source' );

		$result = $this->repository->get_id_by_source( $source );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test get_id_by_source handles string cached value.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::get_id_by_source
	 */
	public function test_get_id_by_source_casts_cached_string_to_int(): void {
		$source = $this->create_source();

		Functions\expect( 'wp_cache_get' )
			->once()
			->with( '1:' . $source->hash(), CachingRedirectRepository::CACHE_GROUP )
			->andReturn( '789' );

		$this->inner->shouldNotReceive( 'get_id_by_source' );

		$result = $this->repository->get_id_by_source( $source );

		$this->assertSame( 789, $result );
	}
}
