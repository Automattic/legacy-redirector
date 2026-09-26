<?php
/**
 * Corrupt redirect row handling tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\AuditFindingType;

/**
 * Tests the whole corrupt-row story: a vip-legacy-redirect row whose stored
 * source or destination no longer validates (hand-edited, partial 1.x import)
 * must never fatal or be served, but must stay visible to management
 * surfaces so it can be reported, deleted, or repaired.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @covers \Automattic\LegacyRedirector\Application\RedirectManager
 * @covers \Automattic\LegacyRedirector\Domain\Redirect
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 */
final class CorruptRedirectRowsTest extends TestCase {

	/**
	 * A corrupt row must read back as "no redirect" on the front-end lookup,
	 * without poisoning the shared cache entry: the duplicate-check lookup
	 * must still see the row afterwards.
	 */
	public function test_find_by_source_returns_null_but_row_stays_visible_to_duplicate_checks(): void {
		$source  = SourceUrl::from_string( '/corrupt-source-row' );
		$post_id = $this->insert_redirect_post(
			array(
				'post_name'  => $source->hash(),
				'post_title' => '',
			)
		);

		$repository = $this->repository();

		$this->assertNull( $repository->find_by_source( $source ) );

		// The find_by_source() call above populated the cache; the shared
		// entry must hold the true row ID, not "no redirect exists".
		$this->assertSame( $post_id, $repository->get_id_by_source( $source ) );
		$this->assertTrue( $repository->exists( $source ) );
	}

	/**
	 * A row with an unreadable destination must not be served either.
	 */
	public function test_find_by_source_returns_null_for_invalid_destination(): void {
		$source = SourceUrl::from_string( '/corrupt-destination-row' );
		$this->insert_redirect_post(
			array(
				'post_name'    => $source->hash(),
				'post_title'   => $source->path(),
				'post_excerpt' => 'ftp://example.com/nope',
			)
		);

		$this->assertNull( $this->repository()->find_by_source( $source ) );
	}

	/**
	 * A lookup by ID returns the row as a corrupt entity with the reason attached.
	 */
	public function test_find_by_id_returns_corrupt_entity(): void {
		$post_id = $this->insert_redirect_post( array( 'post_title' => '' ) );

		$redirect = $this->repository()->find_by_id( $post_id );

		$this->assertInstanceOf( Redirect::class, $redirect );
		$this->assertTrue( $redirect->is_corrupt() );
		$this->assertStringContainsString( 'Invalid source', $redirect->corruption() );
	}

	/**
	 * Listings include corrupt rows, so find_matching() agrees with count_matching().
	 */
	public function test_find_matching_includes_corrupt_rows_and_agrees_with_count(): void {
		$good_id    = $this->create_redirect( '/healthy-row', 'https://example.com/destination' );
		$corrupt_id = $this->insert_redirect_post( array( 'post_title' => '' ) );

		$criteria         = new RedirectCriteria();
		$query_repository = $this->query_repository();
		$redirects        = $query_repository->find_matching( $criteria );

		$this->assertCount( $query_repository->count_matching( $criteria ), $redirects );

		$by_id = array_combine(
			array_map( static fn( Redirect $redirect ) => $redirect->id(), $redirects ),
			$redirects
		);

		$this->assertArrayHasKey( $good_id, $by_id );
		$this->assertArrayHasKey( $corrupt_id, $by_id );
		$this->assertFalse( $by_id[ $good_id ]->is_corrupt() );
		$this->assertTrue( $by_id[ $corrupt_id ]->is_corrupt() );
	}

	/**
	 * The auditor reports a corrupt row as a CORRUPT_DATA issue, so the
	 * validate command and ability surface it instead of skipping it.
	 */
	public function test_auditor_reports_corrupt_row(): void {
		$post_id  = $this->insert_redirect_post( array( 'post_title' => '' ) );
		$redirect = $this->repository()->find_by_id( $post_id );

		$issue = ( new RedirectAuditor() )->audit_destination( $redirect );

		$this->assertNotNull( $issue );
		$this->assertSame( AuditFindingType::CORRUPT_DATA, $issue->type() );
		$this->assertStringContainsString( 'Invalid source', $issue->extra_info() );
	}

	/**
	 * A corrupt row can be deleted through the manager.
	 */
	public function test_corrupt_row_can_be_deleted(): void {
		$post_id = $this->insert_redirect_post( array( 'post_title' => '' ) );

		$this->assertTrue( $this->manager()->delete_by_id( $post_id ) );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Partial updates are refused: a status change alone cannot repair a
	 * corrupt row, and persisting it would overwrite the stored data with
	 * placeholders.
	 */
	public function test_corrupt_row_cannot_be_partially_updated(): void {
		$post_id = $this->insert_redirect_post( array( 'post_title' => '' ) );

		$this->assertFalse( $this->manager()->disable( $post_id ) );

		$result = $this->manager()->update_destination(
			$post_id,
			Destination::from_url( DestinationUrl::from_string( '/somewhere' ) )
		);
		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'corrupt-redirect', $result->error_code() );

		// The stored row is untouched.
		$post = get_post( $post_id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '', $post->post_title );
	}

	/**
	 * Re-saving a corrupt entity directly is refused at the repository level.
	 */
	public function test_save_refuses_corrupt_entity(): void {
		$post_id  = $this->insert_redirect_post( array( 'post_title' => '' ) );
		$redirect = $this->repository()->find_by_id( $post_id );

		$this->expectException( RedirectPersistenceException::class );

		$this->repository()->save( $redirect );
	}

	/**
	 * A full update (new source and destination) repairs the row in place -
	 * the admin edit screen path.
	 */
	public function test_update_redirect_repairs_corrupt_row(): void {
		$post_id = $this->insert_redirect_post( array( 'post_title' => '' ) );

		$updated = $this->manager()->update_redirect(
			$post_id,
			'/repaired-source',
			Destination::from_url( DestinationUrl::from_string( '/repaired-destination' ) )
		);

		$this->assertTrue( $updated->is_success() );

		$repaired = $this->repository()->find_by_id( $post_id );
		$this->assertFalse( $repaired->is_corrupt() );
		$this->assertSame( '/repaired-source', $repaired->source()->path() );

		// The repaired row resolves on the front-end lookup again.
		$found = $this->repository()->find_by_source( SourceUrl::from_string( '/repaired-source' ) );
		$this->assertNotNull( $found );
		$this->assertSame( $post_id, $found->id() );
	}

	/**
	 * Upserting by source repairs a corrupt row - the CSV re-import recovery
	 * path for a botched migration.
	 */
	public function test_update_by_source_repairs_corrupt_row(): void {
		$source  = SourceUrl::from_string( '/broken-import' );
		$post_id = $this->insert_redirect_post(
			array(
				'post_name'    => $source->hash(),
				'post_title'   => '',
				'post_excerpt' => 'ftp://example.com/nope',
			)
		);

		$updated = $this->manager()->update_by_source(
			$source,
			Destination::from_url( DestinationUrl::from_string( 'https://example.com/reimported' ) )
		);

		$this->assertTrue( $updated->is_success() );

		$repaired = $this->repository()->find_by_id( $post_id );
		$this->assertFalse( $repaired->is_corrupt() );
		$this->assertSame( '/broken-import', $repaired->source()->path() );
		$this->assertSame( 'https://example.com/reimported', $repaired->destination()->as_url()->value() );
	}
}
