<?php
/**
 * Data upgrade integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderTest class.
 *
 * Covers the migration of redirect data created by version 1.x.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class UpgraderTest extends TestCase {

	/**
	 * The upgrade routine under test.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Reset upgrade state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( 'wpcom_legacy_redirector_upgrade_started_gmt' );
		delete_option( 'wpcom_legacy_redirector_upgrade_cursor' );

		// The shared Integration TestCase does not call parent::set_up(), so
		// WP_UnitTestCase never opens its rollback transaction and posts leak
		// between tests. Clear the post type explicitly.
		foreach ( get_posts(
			array(
				'post_type'      => PostType::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) as $stale_id ) {
			wp_delete_post( (int) $stale_id, true );
		}

		$this->upgrader = new Upgrader();
	}

	/**
	 * Create a redirect exactly as version 1.x would have stored it.
	 *
	 * 1.x called wp_insert_post() with no post_status, so WordPress defaulted
	 * every redirect to 'draft'.
	 *
	 * @param string $source_path The source path as 1.x stored it.
	 * @param string $destination The destination URL.
	 * @return int The created post ID.
	 */
	private function create_legacy_redirect( string $source_path, string $destination = 'https://example.com/new' ): int {
		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $source_path ),
				'post_title'   => $source_path,
				'post_excerpt' => $destination,
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * A site with no redirects completes immediately.
	 *
	 * @return void
	 */
	public function test_fresh_install_completes_without_work() {
		$result = $this->upgrader->run_batch( 100 );

		$this->assertTrue( $result['complete'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $this->upgrader->needs_upgrade() );
	}

	/**
	 * Redirects stored as drafts by 1.x are published.
	 *
	 * @return void
	 */
	public function test_legacy_drafts_are_published() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$this->assertSame( 'draft', get_post_status( $post_id ) );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 1, $result['published'] );
		$this->assertTrue( $result['complete'] );
	}

	/**
	 * Publishing a 1.x draft drops the stale "nothing here" cache entry.
	 *
	 * A path requested while its redirect was still an unpublished 1.x draft
	 * is cached as missing. If the upgrade publishes the redirect without
	 * clearing that entry, the redirect stays dead for the life of the cache
	 * entry despite being live in the database.
	 *
	 * The assertion is really about two owners agreeing on one key format: the
	 * upgrade routine invalidates by stored post_name hash, the repository
	 * caches by source URL, and a drift between them fails here rather than in
	 * production.
	 *
	 * @return void
	 */
	public function test_publishing_a_draft_clears_the_stale_negative_cache_entry() {
		$source  = '/cached-as-missing';
		$post_id = $this->create_legacy_redirect( $source );

		$cache_key = CachingRedirectRepository::cache_key( SourceUrl::from_string( $source )->hash() );
		wp_cache_set( $cache_key, 0, CachingRedirectRepository::CACHE_GROUP );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertFalse(
			wp_cache_get( $cache_key, CachingRedirectRepository::CACHE_GROUP ),
			'The upgrade should have dropped the entry saying no redirect lives at this path.'
		);
		$this->assertSame(
			$post_id,
			$this->repository()->get_id_by_source( SourceUrl::from_string( $source ) ),
			'The redirect should be findable once the upgrade has published it.'
		);
	}

	/**
	 * A site at the previous data version still has work to do.
	 *
	 * The version constant is what makes a pass run in production at all:
	 * needs_upgrade() short-circuits at DB_VERSION, so none of the migrations
	 * below are ever reached on an already-current site.
	 *
	 * @return void
	 */
	public function test_site_at_the_previous_version_still_needs_upgrading() {
		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );

		$this->assertTrue( $this->upgrader->needs_upgrade() );
	}

	/**
	 * Once complete, the routine reports no further work.
	 *
	 * @return void
	 */
	public function test_upgrade_runs_only_once() {
		$this->create_legacy_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$this->assertFalse( $this->upgrader->needs_upgrade() );

		// A redirect disabled after the upgrade must stay disabled.
		$post_id = $this->create_legacy_redirect( '/disabled-later' );
		$this->upgrader->maybe_upgrade();

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * A redirect disabled since the upgrade began is not republished.
	 *
	 * This is the ambiguity the migration has to resolve: under 2.0 'draft'
	 * means "deliberately disabled", but under 1.x it meant nothing at all.
	 *
	 * @return void
	 */
	public function test_redirect_disabled_after_upgrade_started_is_left_alone() {
		// Mark the upgrade as having begun in the past.
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2000-01-01 00:00:00' );

		// A redirect created and then disabled under 2.0: because it was
		// published at some point, it carries a real post_modified_gmt, which
		// is what distinguishes it from a 1.x redirect that never was.
		$disabled_id = (int) wp_insert_post(
			array(
				'post_name'    => md5( '/disabled-under-2x' ),
				'post_title'   => '/disabled-under-2x',
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $disabled_id,
				'post_status' => 'draft',
			)
		);

		$this->assertNotSame(
			'0000-00-00 00:00:00',
			get_post( $disabled_id )->post_modified_gmt,
			'A redirect disabled under 2.0 should carry a real modified date.'
		);

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post_status( $disabled_id ) );
		$this->assertSame( 0, $result['published'] );
	}

	/**
	 * A 1.x redirect is recognized by never having been published.
	 *
	 * WordPress stores 0000-00-00 00:00:00 as the modified date for a draft
	 * that was never published, which is exactly what 1.x produced.
	 *
	 * @return void
	 */
	public function test_legacy_redirect_has_no_modified_date() {
		$legacy_id = $this->create_legacy_redirect( '/old-page' );

		$this->assertSame( '0000-00-00 00:00:00', get_post( $legacy_id )->post_modified_gmt );

		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2000-01-01 00:00:00' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $legacy_id ) );
		$this->assertSame( 1, $result['published'] );
	}

	/**
	 * Batching processes the whole set across several runs.
	 *
	 * @return void
	 */
	public function test_batches_process_the_whole_set() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->create_legacy_redirect( '/old-page-' . $i );
		}

		$first = $this->upgrader->run_batch( 2 );
		$this->assertFalse( $first['complete'] );
		$this->assertSame( 2, $first['processed'] );

		do {
			$batch = $this->upgrader->run_batch( 2 );
		} while ( ! $batch['complete'] );

		foreach ( $ids as $id ) {
			$this->assertSame( 'publish', get_post_status( $id ), 'Redirect ' . $id . ' should have been published.' );
		}

		$this->assertFalse( $this->upgrader->needs_upgrade() );
	}

	/**
	 * A dry run reports the work without performing it.
	 *
	 * @return void
	 */
	public function test_count_pending_does_not_change_anything() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['total'] );
		$this->assertSame( 1, $pending['to_publish'] );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertTrue( $this->upgrader->needs_upgrade() );
	}
	/**
	 * An absolute destination pointing at this site is rewritten to its relative form.
	 *
	 * @return void
	 */
	public function test_internal_absolute_destination_is_normalized() {
		$post_id = $this->create_legacy_redirect( '/old-page', home_url( '/new-page?a=1' ) );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['normalized'] );
		$this->assertSame( '/new-page?a=1', get_post( $post_id )->post_excerpt );

		// The written value must survive the round trip back through the
		// domain layer, or the redirect silently stops resolving.
		$destination = DestinationUrl::from_string( get_post( $post_id )->post_excerpt );
		$this->assertTrue( $destination->is_relative() );
	}

	/**
	 * A double-slash path would be rejected as scheme-relative by the domain
	 * layer, so it must be left as stored rather than corrupted.
	 *
	 * @return void
	 */
	public function test_double_slash_destination_is_not_normalized() {
		// Built by concatenation: home_url( '//foo' ) would collapse the
		// double slash this test exists to preserve.
		$destination = untrailingslashit( home_url() ) . '//foo';
		$post_id     = $this->create_legacy_redirect( '/old-page', $destination );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['normalized'] );
		$this->assertSame( $destination, get_post( $post_id )->post_excerpt );
	}

	/**
	 * An external destination is left exactly as stored.
	 *
	 * @return void
	 */
	public function test_external_destination_is_not_normalized() {
		$post_id = $this->create_legacy_redirect( '/old-page', 'https://external.example.net/x' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['normalized'] );
		$this->assertSame( 'https://external.example.net/x', get_post( $post_id )->post_excerpt );
	}

	/**
	 * A relative destination stored percent-encoded is canonicalized.
	 *
	 * Before version 4, whichever encoding was typed was what got stored, so
	 * one target could be two different strings. Existing rows converge on
	 * the decoded form the normalizer now produces on save.
	 *
	 * @return void
	 */
	public function test_encoded_relative_destination_is_canonicalized() {
		$post_id = $this->create_legacy_redirect( '/old-page', '/caf%C3%A9?q=a%26b' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['normalized'] );
		// The path decodes; the query keeps its encoding, because its values
		// have sub-structure a decode would corrupt.
		$this->assertSame( '/café?q=a%26b', get_post( $post_id )->post_excerpt );

		$destination = DestinationUrl::from_string( get_post( $post_id )->post_excerpt );
		$this->assertTrue( $destination->is_relative() );
	}

	/**
	 * A relative destination already in canonical form is left uncounted.
	 *
	 * The destination pass runs on every future version walk, so a rewrite
	 * that fired on already-canonical rows would report work forever.
	 *
	 * @return void
	 */
	public function test_canonical_relative_destination_is_not_recounted() {
		$post_id = $this->create_legacy_redirect( '/old-page', '/café' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['normalized'] );
		$this->assertSame( '/café', get_post( $post_id )->post_excerpt );
	}

	/**
	 * A dry run reports destinations due to be made relative.
	 *
	 * @return void
	 */
	public function test_count_pending_reports_normalization() {
		$this->create_legacy_redirect( '/old-page', home_url( '/new-page' ) );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['to_normalize'] );
	}
	/**
	 * A stored source with a trailing slash is re-keyed without one.
	 *
	 * @return void
	 */
	public function test_trailing_slash_source_is_canonicalized() {
		$post_id = $this->create_legacy_redirect( '/old-page/' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['repathed'] );

		$post = get_post( $post_id );
		$this->assertSame( '/old-page', $post->post_title );
		$this->assertSame( md5( '/old-page' ), $post->post_name );
	}

	/**
	 * The trailing slash comes off the path, not off the query string.
	 *
	 * @return void
	 */
	public function test_trailing_slash_is_stripped_from_the_path_only() {
		$post_id = $this->create_legacy_redirect( '/old-page/?ref=a/' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/old-page?ref=a/', get_post( $post_id )->post_title );
	}

	/**
	 * A source already without a trailing slash is left uncounted.
	 *
	 * The pass runs on every future version walk, so rewriting rows that are
	 * already canonical would report work forever.
	 *
	 * @return void
	 */
	public function test_canonical_source_is_not_recounted() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['repathed'] );
		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
	}

	/**
	 * The site root keeps its slash rather than being emptied.
	 *
	 * @return void
	 */
	public function test_root_source_survives_canonicalization() {
		$post_id = $this->create_legacy_redirect( '/' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['repathed'] );
		$this->assertSame( '/', get_post( $post_id )->post_title );
	}

	/**
	 * Both halves of the old two-row workaround converge, and the copy is trashed.
	 *
	 * Storing '/old-page' and '/old-page/' pointing at the same place was the
	 * documented way to cover both forms before 2.0. They now want one key, so
	 * the redundant row goes to the trash rather than being deleted outright.
	 *
	 * @return void
	 */
	public function test_duplicate_of_the_same_destination_is_trashed() {
		$kept    = $this->create_legacy_redirect( '/old-page', 'https://example.com/new' );
		$dupe_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/new' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['deduped'] );
		$this->assertSame( array(), $result['conflicts'] );

		$this->assertSame( 'trash', get_post( $dupe_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept )->post_status );
		$this->assertSame( md5( '/old-page' ), get_post( $kept )->post_name );
	}

	/**
	 * Trashing a duplicate must not shift later rows out from under the cursor.
	 *
	 * The batch query pages by offset, and WP_Query's 'any' status excludes
	 * trash - so a duplicate trashed in batch one would shrink the result set
	 * and the row straddling the batch boundary would be skipped, silently
	 * left on its old key. The query names every status to keep the set stable.
	 *
	 * @return void
	 */
	public function test_row_after_a_trashed_duplicate_is_still_migrated() {
		$this->create_legacy_redirect( '/old-page', 'https://example.com/new' );
		$this->create_legacy_redirect( '/old-page/', 'https://example.com/new' );
		$straddler = $this->create_legacy_redirect( '/needs-rekey/', 'https://example.com/other' );

		$first = $this->upgrader->run_batch( 2 );
		$this->assertSame( 1, $first['deduped'] );
		$this->assertFalse( $first['complete'] );

		$second = $this->upgrader->run_batch( 2 );

		$this->assertSame( 1, $second['processed'] );
		$this->assertSame( '/needs-rekey', get_post( $straddler )->post_title );
		$this->assertSame( md5( '/needs-rekey' ), get_post( $straddler )->post_name );
	}

	/**
	 * A collision between different destinations drafts the loser and reports it.
	 *
	 * Only a human can decide which destination was meant, so the survivor is
	 * left firing and the other is disabled rather than silently discarded.
	 *
	 * @return void
	 */
	public function test_colliding_different_destination_is_drafted_and_reported() {
		$kept     = $this->create_legacy_redirect( '/old-page', 'https://example.com/one' );
		$loser_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/two' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['deduped'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'drafted', $result['conflicts'][0] );

		$this->assertSame( 'draft', get_post( $loser_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept )->post_status );
	}

	/**
	 * The losing row keeps its own slug rather than contending for the winner's.
	 *
	 * Two rows sharing a post_name would go through wp_unique_post_slug() and
	 * be silently suffixed, leaving the redirect findable under neither its old
	 * key nor its new one.
	 *
	 * @return void
	 */
	public function test_colliding_row_does_not_take_the_winners_slug() {
		$kept     = $this->create_legacy_redirect( '/old-page', 'https://example.com/one' );
		$loser_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/two' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( md5( '/old-page' ), get_post( $kept )->post_name );
		$this->assertSame( md5( '/old-page/' ), get_post( $loser_id )->post_name );
	}

	/**
	 * A dry run reports duplicates and conflicts without writing anything.
	 *
	 * @return void
	 */
	public function test_count_pending_reports_duplicates_and_conflicts() {
		$this->create_legacy_redirect( '/dupe', 'https://example.com/same' );
		$dupe_id = $this->create_legacy_redirect( '/dupe/', 'https://example.com/same' );
		$this->create_legacy_redirect( '/clash', 'https://example.com/one' );
		$this->create_legacy_redirect( '/clash/', 'https://example.com/two' );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['to_dedupe'] );
		$this->assertCount( 1, $pending['conflicts'] );

		// Nothing was written.
		$this->assertSame( 'draft', get_post( $dupe_id )->post_status );
		$this->assertTrue( $this->upgrader->needs_upgrade() );
	}

	/**
	 * A canonicalized source is reachable by a request in either spelling.
	 *
	 * The point of the migration: the stored row moves to the canonical key,
	 * and lookups canonicalize the request the same way, so both forms land.
	 *
	 * @return void
	 */
	public function test_canonicalized_source_is_reachable_by_either_spelling() {
		$this->create_legacy_redirect( '/old-page/' );

		$this->upgrader->run_batch( 100 );

		$repository = new PostTypeRedirectRepository();

		foreach ( array( '/old-page', '/old-page/' ) as $request ) {
			$this->assertInstanceOf(
				Redirect::class,
				$repository->find_by_source( SourceUrl::from_string( $request ) ),
				$request . ' should resolve after migration'
			);
		}
	}
}
