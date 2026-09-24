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
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
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
		delete_option( 'wpcom_legacy_redirector_upgrade_ceiling' );
		delete_transient( 'wpcom_legacy_redirector_upgrade_cli' );
		delete_option( 'wpcom_legacy_redirector_upgrade_retry' );

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
	 * The cursor stores the last processed post ID, not an offset.
	 *
	 * The web-request path and the CLI share this option, so a run
	 * interrupted mid-way resumes correctly from the other side - but only
	 * while both agree on what the stored number means.
	 *
	 * @return void
	 */
	public function test_cursor_stores_the_last_processed_post_id() {
		$first = $this->create_legacy_redirect( '/one' );
		$this->create_legacy_redirect( '/two' );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( $first, (int) get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );
	}

	/**
	 * Walking the set does not load every row into the post cache.
	 *
	 * WP_Query primes the post cache whenever it splits a query, whatever
	 * cache_results says, so a walk built on it holds every redirect in memory
	 * by the end of a dry run - about 2 GB at a million rows.
	 *
	 * @return void
	 */
	public function test_walking_the_set_does_not_prime_the_post_cache() {
		$post_id = $this->create_legacy_redirect( '/old-page' );
		wp_cache_flush();

		$this->upgrader->count_pending();

		$this->assertFalse( wp_cache_get( $post_id, 'posts' ) );
	}

	/**
	 * The migration changes the planned fields and nothing else.
	 *
	 * Covers each kind of write: the bulk publish, a re-key, and trashing a
	 * duplicate. Someone auditing which redirects were added in 2020 needs the
	 * dates as they were, and wp_update_post() would also have left an old
	 * slug in post meta and suffixed the trashed row's slug.
	 *
	 * @return void
	 */
	public function test_migration_leaves_dates_meta_and_slugs_alone() {
		global $wpdb;

		$ids = array(
			'bulk'    => $this->create_legacy_redirect( '/old-page', 'https://example.com/same' ),
			'rekey'   => $this->create_legacy_redirect( '/other-page/' ),
			'trashed' => $this->create_legacy_redirect( '/old-page/', 'https://example.com/same' ),
		);

		$dates  = array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' );
		$before = array();
		foreach ( $ids as $key => $id ) {
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => '2020-03-01 09:00:00',
					'post_modified' => '2020-03-01 09:00:00',
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );
			$before[ $key ] = wp_array_slice_assoc( get_post( $id, ARRAY_A ), $dates );
		}

		$this->upgrader->run_batch( 100 );

		foreach ( $ids as $key => $id ) {
			$this->assertSame( $before[ $key ], wp_array_slice_assoc( get_post( $id, ARRAY_A ), $dates ), 'The ' . $key . ' row should keep its dates.' );
			$this->assertSame( array(), get_post_meta( $id ), 'The ' . $key . ' row should gain no post meta.' );
		}

		$this->assertSame( 'publish', get_post_status( $ids['bulk'] ) );
		$this->assertSame( md5( '/other-page' ), get_post( $ids['rekey'] )->post_name );
		$this->assertSame( 'trash', get_post_status( $ids['trashed'] ) );
		$this->assertSame( md5( '/old-page/' ), get_post( $ids['trashed'] )->post_name );
	}

	/**
	 * A migrated row loses the audit flag describing its pre-migration self.
	 *
	 * A save clears a row's flag so it never shows a stale verdict; the
	 * migration's direct writes skip save_post, so they must do the same.
	 *
	 * @return void
	 */
	public function test_migrated_rows_lose_their_stale_audit_flags() {
		$bulk_id  = $this->create_legacy_redirect( '/old-page' );
		$rekey_id = $this->create_legacy_redirect( '/other-page/' );
		update_post_meta( $bulk_id, AuditFlags::META_KEY, 'warning' );
		update_post_meta( $rekey_id, AuditFlags::META_KEY, 'problem' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '', get_post_meta( $bulk_id, AuditFlags::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $rekey_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * A dry run reports exactly what the run then does.
	 *
	 * The dry run used to count every draft as due for publishing, including
	 * the ones the run was about to trash as duplicates or draft as conflicts.
	 *
	 * @return void
	 */
	public function test_dry_run_predicts_the_run() {
		$this->create_legacy_redirect( '/plain' );
		$this->create_legacy_redirect( '/slash/' );
		$this->create_legacy_redirect( '/internal', home_url( '/target' ) );
		$this->create_legacy_redirect( '/dupe', 'https://example.com/same' );
		$this->create_legacy_redirect( '/dupe/', 'https://example.com/same' );
		$this->create_legacy_redirect( '/clash', 'https://example.com/one' );
		$this->create_legacy_redirect( '/clash/', 'https://example.com/two' );
		// Both need re-keying onto '/converge', so only the run's own write of
		// the first can make the second collide.
		$this->create_legacy_redirect( '/converge/', 'https://example.com/one' );
		$this->create_legacy_redirect( '/converge//', 'https://example.com/two' );

		// Already published and canonical, so nothing to change.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $this->create_legacy_redirect( '/already' ) ) );

		// Edited after the upgrade began, so left alone.
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2000-01-01 00:00:00' );
		wp_update_post(
			array(
				'ID'          => $this->create_legacy_redirect( '/edited/' ),
				'post_status' => 'draft',
			)
		);

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		// '/dupe/' is trashed and '/clash/' and '/converge//' drafted, so none
		// of them is published.
		$this->assertSame( 6, $pending['published'] );
		// '/already', plus '/clash/' and '/converge//': 1.x drafts already, so
		// drafting them as conflicts writes nothing.
		$this->assertSame( 3, $pending['unchanged'] );
		$this->assertSame( 1, $pending['skipped'] );

		$keys = array( 'changed', 'unchanged', 'skipped', 'published', 'repathed', 'deduped', 'normalized' );
		$this->assertSame( wp_array_slice_assoc( $pending, $keys ), wp_array_slice_assoc( $result, $keys ) );
		$this->assertSame( count( $pending['conflicts'] ), count( $result['conflicts'] ) );
		$this->assertSame( $pending['total'], $result['processed'] );
	}

	/**
	 * Every processed redirect is counted in exactly one outcome.
	 *
	 * Otherwise the summary's numbers do not add up, and nobody can tell what
	 * was left untouched.
	 *
	 * @return void
	 */
	public function test_outcomes_add_up_to_the_redirects_processed() {
		$this->create_legacy_redirect( '/old-page' );
		$this->create_legacy_redirect( '/other-page/' );
		$this->create_legacy_redirect( '/dupe', 'https://example.com/same' );
		$this->create_legacy_redirect( '/dupe/', 'https://example.com/same' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 4, $result['processed'] );
		$this->assertSame( $result['processed'], $result['changed'] + $result['unchanged'] + $result['skipped'] + count( $result['failed'] ) );
	}

	/**
	 * A single row's refused write is reported, not counted as done, and retried later.
	 *
	 * Simulated by blanking the per-row UPDATE, which is how a failed write
	 * looks to the migration: the call returns false. The retry comes after
	 * the upgrade has completed, and must still publish the 1.x draft.
	 *
	 * @return void
	 */
	public function test_failed_row_is_reported_then_retried() {
		$bulk_id  = $this->create_legacy_redirect( '/old-page' );
		$rekey_id = $this->create_legacy_redirect( '/other-page/' );

		$result = $this->with_row_writes_refused( fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertSame( 1, $result['changed'] );
		$this->assertSame( 0, $result['repathed'] );
		$this->assertCount( 1, $result['failed'] );
		$this->assertStringStartsWith( '#' . $rekey_id . ' (/other-page/): ', $result['failed'][0] );
		$this->assertSame( 'publish', get_post_status( $bulk_id ) );
		$this->assertSame( 'draft', get_post_status( $rekey_id ) );
		$this->assertFalse( $this->upgrader->needs_upgrade() );
		$this->assertSame( 1, $this->upgrader->pending_retries() );

		$retry = $this->upgrader->retry_failed();

		$this->assertSame( 1, $retry['processed'] );
		$this->assertSame( 1, $retry['repathed'] );
		$this->assertSame( 1, $retry['published'] );
		$this->assertSame( 'publish', get_post_status( $rekey_id ) );
		$this->assertSame( '/other-page', get_post( $rekey_id )->post_title );
		$this->assertSame( 0, $this->upgrader->pending_retries() );
	}

	/**
	 * A row that fails its retry stays waiting; one deleted meanwhile drops out.
	 *
	 * @return void
	 */
	public function test_retry_keeps_rows_that_fail_again() {
		$failing_id = $this->create_legacy_redirect( '/failing/' );
		$deleted_id = $this->create_legacy_redirect( '/deleted/' );

		$this->with_row_writes_refused( fn() => $this->upgrader->run_batch( 100 ) );
		$this->assertSame( 2, $this->upgrader->pending_retries() );

		wp_delete_post( $deleted_id, true );
		$retry = $this->with_row_writes_refused( fn() => $this->upgrader->retry_failed() );

		$this->assertSame( 1, $retry['processed'] );
		$this->assertCount( 1, $retry['failed'] );
		$this->assertSame( 1, $this->upgrader->pending_retries() );
		$this->assertSame( 'draft', get_post_status( $failing_id ) );
	}

	/**
	 * A refused bulk write stops the batch without advancing the cursor.
	 *
	 * A status flip has no row-specific way to fail, so this is the database
	 * failing: the next run must redo the batch, not skip it.
	 *
	 * @return void
	 */
	public function test_refused_bulk_write_stops_the_batch() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page' );
		$refuse  = static fn( string $query ): string => str_starts_with( $query, "UPDATE {$wpdb->posts} SET post_status" ) ? '' : $query;

		add_filter( 'query', $refuse );
		try {
			$this->upgrader->run_batch( 100 );
			$this->fail( 'The batch should have stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not publish', $e->getMessage() );
		} finally {
			remove_filter( 'query', $refuse );
		}

		$this->assertSame( 0, (int) get_option( 'wpcom_legacy_redirector_upgrade_cursor', 0 ) );
		$this->assertTrue( $this->upgrader->needs_upgrade() );

		$this->upgrader->run_batch( 100 );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A refused read is not mistaken for the end of the walk.
	 *
	 * $wpdb returns an empty result for an error and for no rows alike; read
	 * as "no more rows", a database blip would mark the upgrade complete with
	 * redirects never visited.
	 *
	 * @return void
	 */
	public function test_refused_read_does_not_complete_the_upgrade() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page' );
		$refuse  = static fn( string $query ): string => str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" ) ? '' : $query;

		add_filter( 'query', $refuse );
		$this->upgrader->maybe_upgrade();
		remove_filter( 'query', $refuse );

		$this->assertTrue( $this->upgrader->needs_upgrade(), 'A failed read must not complete the upgrade.' );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * A redirect disabled as a duplicate source can be listed later, until someone saves it.
	 *
	 * @return void
	 */
	public function test_duplicates_are_listed_until_saved() {
		$kept_id  = $this->create_legacy_redirect( '/clash', 'https://example.com/one' );
		$loser_id = $this->create_legacy_redirect( '/clash/', 'https://example.com/two' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( array( $loser_id => $kept_id ), $this->upgrader->duplicates() );

		add_action( 'save_post_' . PostType::POST_TYPE, array( Upgrader::class, 'forget_duplicate' ) );
		wp_update_post(
			array(
				'ID'           => $loser_id,
				'post_excerpt' => 'https://example.com/one',
			)
		);
		remove_action( 'save_post_' . PostType::POST_TYPE, array( Upgrader::class, 'forget_duplicate' ) );

		$this->assertSame( array(), $this->upgrader->duplicates() );
	}

	/**
	 * Run a callback with every per-row redirect write refused.
	 *
	 * Blanks the UPDATE $wpdb->update() builds (its table name is quoted),
	 * leaving the bulk publish, which is not, alone.
	 *
	 * @param callable $callback The code to run.
	 * @return mixed What the callback returned.
	 */
	private function with_row_writes_refused( callable $callback ): mixed {
		global $wpdb;

		$refuse = static fn( string $query ): string => str_starts_with( $query, "UPDATE `{$wpdb->posts}`" ) ? '' : $query;

		add_filter( 'query', $refuse );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $refuse );
		}
	}

	/**
	 * Two rows re-keyed onto the same source in one batch do not both take it.
	 *
	 * The first row's write has to be visible to the collision check for the
	 * second, including through any cached lookup query.
	 *
	 * @return void
	 */
	public function test_rows_converging_on_one_key_in_one_batch_collide() {
		$first  = $this->create_legacy_redirect( '/x/', 'https://example.com/one' );
		$second = $this->create_legacy_redirect( '/x//', 'https://example.com/two' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( md5( '/x' ), get_post( $first )->post_name );
		$this->assertSame( md5( '/x//' ), get_post( $second )->post_name );
		$this->assertSame( 'draft', get_post_status( $second ) );
		$this->assertCount( 1, $result['conflicts'] );
	}

	/**
	 * A trashed row that collides is left in the trash, and not reported.
	 *
	 * It is already out of service. Drafting it as a conflict would quietly
	 * restore it, and trashing it again would count it as a duplicate on
	 * every walk.
	 *
	 * @return void
	 */
	public function test_trashed_row_that_collides_is_left_alone() {
		$this->create_legacy_redirect( '/old-page', 'https://example.com/one' );
		$trashed_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/two' );
		wp_update_post(
			array(
				'ID'          => $trashed_id,
				'post_status' => 'trash',
			)
		);
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2099-01-01 00:00:00' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'trash', get_post_status( $trashed_id ) );
		$this->assertSame( 0, $result['deduped'] );
		$this->assertSame( array(), $result['conflicts'] );
	}

	/**
	 * A redirect created disabled while the upgrade runs is not published.
	 *
	 * Created as a draft and never modified, it looks exactly like a 1.x
	 * redirect, so the walk has to stop at the highest ID that existed when
	 * the upgrade began.
	 *
	 * @return void
	 */
	public function test_redirect_created_after_the_upgrade_began_is_left_alone() {
		$legacy_id = $this->create_legacy_redirect( '/old-a' );
		$this->create_legacy_redirect( '/old-b' );

		$this->upgrader->run_batch( 1 );

		$created_id = $this->create_legacy_redirect( '/created-disabled' );

		do {
			$batch = $this->upgrader->run_batch( 1 );
		} while ( ! $batch['complete'] );

		$this->assertSame( 'publish', get_post_status( $legacy_id ) );
		$this->assertSame( 'draft', get_post_status( $created_id ) );
	}

	/**
	 * An edit that lands between a batch reading a row and writing it survives.
	 *
	 * Simulated by editing both rows at the moment the batch issues its first
	 * UPDATE: one row is by then waiting in the bulk publish queue, the other
	 * is about to be re-keyed.
	 *
	 * @return void
	 */
	public function test_an_edit_landing_mid_batch_is_not_overwritten() {
		global $wpdb;

		$bulk_id  = $this->create_legacy_redirect( '/old-page' );
		$rekey_id = $this->create_legacy_redirect( '/other-page/' );

		$raced = false;
		$race  = static function ( string $query ) use ( &$raced, $wpdb, $bulk_id, $rekey_id ): string {
			if ( ! $raced && preg_match( "/^UPDATE `?{$wpdb->posts}`? /", $query ) ) {
				$raced = true;
				foreach ( array( $bulk_id, $rekey_id ) as $id ) {
					$wpdb->update(
						$wpdb->posts,
						array( 'post_modified_gmt' => '2099-01-01 00:00:00' ),
						array( 'ID' => $id )
					);
				}
			}
			return $query;
		};

		add_filter( 'query', $race );
		$this->upgrader->run_batch( 100 );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced, 'The simulated edit should have run.' );
		$this->assertSame( 'draft', get_post_status( $bulk_id ) );
		$this->assertSame( '/other-page/', get_post( $rekey_id )->post_title );
	}

	/**
	 * Web requests leave the work to a running CLI migration.
	 *
	 * @return void
	 */
	public function test_web_batches_hold_off_while_the_cli_migrates() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$this->upgrader->hold_web_batches();
		$this->upgrader->maybe_upgrade();

		$this->assertSame( 'draft', get_post_status( $post_id ) );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertFalse( get_transient( 'wpcom_legacy_redirector_upgrade_cli' ), 'Completing the upgrade should release the hold.' );
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
		$this->assertSame( 1, $pending['published'] );
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

		$this->assertSame( 1, $pending['normalized'] );
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
	 * The batch query pages by keyset (ID > cursor), so a status change in an
	 * earlier batch cannot move unprocessed rows around the way it would shrink
	 * an offset-paged result set. This pins that invariant: the row straddling
	 * the batch boundary after a trashed duplicate is still migrated.
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
		$this->assertStringContainsString( 'has the same source as', $result['conflicts'][0] );

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

		$this->assertSame( 1, $pending['deduped'] );
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
	/**
	 * A 1.x source in any encoding is reachable by the requests that reached it under 1.x.
	 *
	 * 1.x keyed each source by the md5 of the text exactly as stored, while
	 * 2.0 looks a request up by its SourceUrl form. A row whose stored text
	 * differed from that form kept a key no request produced, and returned a
	 * 404 after upgrading, until the migration re-keyed every source.
	 *
	 * @dataProvider data_legacy_sources_and_requests
	 *
	 * @param string   $stored   The source as 1.x stored it.
	 * @param string[] $requests Request URIs that should find it.
	 * @return void
	 */
	public function test_legacy_source_is_reachable_after_migration( string $stored, array $requests ) {
		$this->create_legacy_redirect( $stored );

		$this->upgrader->run_batch( 100 );

		$repository = new PostTypeRedirectRepository();

		foreach ( $requests as $request ) {
			$this->assertInstanceOf(
				Redirect::class,
				$repository->find_by_source( SourceUrl::from_string( $request ) ),
				$request . ' should resolve after migrating ' . $stored
			);
		}
	}

	/**
	 * Data provider of 1.x sources and the request URIs that should find them.
	 *
	 * @return array<string, array{string, string[]}>
	 */
	public static function data_legacy_sources_and_requests(): array {
		return array(
			'encoded unicode'            => array( '/caf%C3%A9', array( '/caf%C3%A9', '/caf%c3%a9', '/café' ) ),
			'raw unicode'                => array( '/café', array( '/caf%C3%A9', '/café' ) ),
			'literal plus'               => array( '/tag/one+two', array( '/tag/one+two', '/tag/one%2Btwo' ) ),
			'encoded space'              => array( '/a%20b', array( '/a%20b' ) ),
			'raw space'                  => array( '/a b', array( '/a%20b' ) ),
			'plus in the query'          => array( '/p?q=a+b', array( '/p?q=a+b', '/p?q=a%20b' ) ),
			'encoded plus in query'      => array( '/p?q=c%2B%2B', array( '/p?q=c%2B%2B' ) ),
			'encoded and slashed'        => array( '/trail%C3%A9/', array( '/trail%C3%A9', '/trail%C3%A9/' ) ),
			'encoded percent'            => array( '/100%25', array( '/100%25' ) ),
			'encoded percent twice'      => array( '/a%2541', array( '/a%2541' ) ),
			'encoded slash'              => array( '/a%2Fb', array( '/a%2Fb' ) ),
			'encoded question mark'      => array( '/a%3Fb', array( '/a%3Fb' ) ),
			'encoded ampersand in query' => array( '/p?q=a%26b', array( '/p?q=a%26b' ) ),
		);
	}

	/**
	 * Re-walking migrated sources changes nothing.
	 *
	 * The source pass runs on every later version walk, so a canonical form
	 * that moved again on a second pass would drift a step further each
	 * release, and a re-keyed row would lose its key.
	 *
	 * @return void
	 */
	public function test_migrated_sources_are_stable_on_a_later_walk() {
		$stored = array( '/caf%C3%A9', '/tag/one+two', '/p?q=c%2B%2B', '/a%2541', '/a%2Fb', '/a%3Fb', '/100%', '/p?q=a%26b', '/a%7Bb' );
		$ids    = array_map( fn( string $source ): int => $this->create_legacy_redirect( $source ), $stored );

		$this->upgrader->run_batch( 100 );

		$first = array_map( fn( int $id ): string => get_post( $id )->post_title, $ids );

		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );
		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['changed'] );
		$this->assertSame( $first, array_map( fn( int $id ): string => get_post( $id )->post_title, $ids ) );
	}

	/**
	 * A site already on 2.0 data has its sources re-keyed too.
	 *
	 * Sites running a 2.0 development build migrated their 1.x data before
	 * sources were re-keyed, so those rows still hold 1.x keys. The source
	 * pass is not gated on 1.x data, so the next version walk catches them.
	 *
	 * @return void
	 */
	public function test_source_is_re_keyed_on_a_site_already_on_2_0_data() {
		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );
		$post_id = $this->create_legacy_redirect( '/caf%C3%A9' );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( '/café', get_post( $post_id )->post_title );
		$this->assertSame( md5( '/café' ), get_post( $post_id )->post_name );
	}
	/**
	 * A source SourceUrl cannot parse still loses its trailing slash.
	 *
	 * '//?q=1' is not a URL 2.0 can look up, but trimming it to '/?q=1' is
	 * what settles it as a spare copy of the redirect beside it.
	 *
	 * @return void
	 */
	public function test_unparseable_source_still_loses_its_trailing_slash() {
		$spare_id = $this->create_legacy_redirect( '//?q=1', 'https://example.com/one' );
		$kept_id  = $this->create_legacy_redirect( '/?q=1', 'https://example.com/one' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['deduped'] );
		$this->assertSame( 'trash', get_post_status( $spare_id ) );
		$this->assertSame( 'publish', get_post_status( $kept_id ) );
	}
}
