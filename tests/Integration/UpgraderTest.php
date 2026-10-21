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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
 * @uses \Automattic\LegacyRedirector\Application\AmbiguousSourceException
 */
final class UpgraderTest extends TestCase {

	use UpgraderTestHelper;

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
		delete_transient( 'wpcom_legacy_redirector_upgrade_backoff' );
		delete_option( 'wpcom_legacy_redirector_upgrade_retry' );
		delete_option( 'wpcom_legacy_redirector_upgrade_rekeyed' );

		$this->upgrader = new Upgrader();
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
	 * A site with no redirects is recorded as current before it creates any.
	 *
	 * A disabled redirect is a draft, exactly as 1.x stored every redirect,
	 * so one created on a fresh install before any page load has run a batch
	 * - `wp legacy-redirector import` on a new site - would be published.
	 *
	 * @return void
	 */
	public function test_fresh_install_is_stamped_before_its_first_redirect() {
		$this->upgrader->stamp_fresh_install();
		$disabled_id = $this->create_legacy_redirect( '/disabled-on-purpose' );

		$this->assertFalse( $this->upgrader->needs_upgrade() );

		$this->upgrader->maybe_upgrade();

		$this->assertSame( 'draft', get_post_status( $disabled_id ) );
	}

	/**
	 * A site holding redirects is not stamped, so its 1.x data is still migrated.
	 *
	 * @return void
	 */
	public function test_site_with_redirects_is_not_stamped() {
		$this->create_legacy_redirect( '/old-page' );

		$this->upgrader->stamp_fresh_install();

		$this->assertTrue( $this->upgrader->needs_upgrade() );
	}

	/**
	 * A site holding redirects records where its upgrade begins before it creates more.
	 *
	 * Under WP-CLI no batch runs on bootstrap, so without this a redirect
	 * created disabled before `wp legacy-redirector migrate` - a never-dated
	 * draft, exactly what 1.x stored - would sit below the ceiling and be
	 * published.
	 *
	 * @return void
	 */
	public function test_site_with_redirects_records_its_anchors_before_new_redirects() {
		$legacy_id = $this->create_legacy_redirect( '/old-page' );

		$this->upgrader->stamp_fresh_install();

		$this->assertNotFalse( get_option( 'wpcom_legacy_redirector_upgrade_started_gmt' ) );
		$this->assertSame( $legacy_id, (int) get_option( 'wpcom_legacy_redirector_upgrade_ceiling' ) );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );

		$disabled_id = $this->create_legacy_redirect( '/disabled-on-purpose' );

		$this->run_to_completion();

		$this->assertSame( 'publish', get_post_status( $legacy_id ) );
		$this->assertSame( 'draft', get_post_status( $disabled_id ) );
	}

	/**
	 * A refused read is not taken for an empty table.
	 *
	 * Stamping a 1.x site would skip its migration for good.
	 *
	 * @return void
	 */
	public function test_refused_read_does_not_stamp() {
		global $wpdb;

		$this->create_legacy_redirect( '/old-page' );

		$this->with_queries_refused( "SELECT ID FROM {$wpdb->posts} WHERE post_type", fn() => $this->upgrader->stamp_fresh_install() );

		$this->assertTrue( $this->upgrader->needs_upgrade() );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_started_gmt' ) );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_ceiling' ) );
	}

	/**
	 * A refused read of the ceiling stops the batch instead of ending the walk.
	 *
	 * Taken for an empty table, a ceiling of 0 would be stored, the first
	 * batch would find no rows below it, and the upgrade would complete with
	 * nothing migrated - for good.
	 *
	 * @return void
	 */
	public function test_refused_ceiling_read_does_not_complete_the_upgrade() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$error = $this->refusal_stops( 'SELECT MAX(ID)', fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertStringContainsString( 'could not read', $error->getMessage() );
		$this->assertTrue( $this->upgrader->needs_upgrade() );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_ceiling' ) );

		$this->run_to_completion();

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A refused read of the ceiling while anchoring records no ceiling, and
	 * does not throw: it runs on every request's init.
	 *
	 * @return void
	 */
	public function test_refused_ceiling_read_does_not_anchor() {
		$this->create_legacy_redirect( '/old-page' );

		$this->with_queries_refused( 'SELECT MAX(ID)', fn() => $this->upgrader->stamp_fresh_install() );

		$this->assertTrue( $this->upgrader->needs_upgrade() );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_ceiling' ) );
	}

	/**
	 * A batch clears the caches its writes left stale once, not once per row.
	 *
	 * On a persistent object cache each clear is a network round trip.
	 *
	 * @return void
	 */
	public function test_batch_clears_caches_once() {
		foreach ( array( '/a/', '/b/', '/c/' ) as $source ) {
			$this->create_legacy_redirect( $source );
		}
		$bumps = 0;
		$count = static function ( string $group ) use ( &$bumps ): void {
			$bumps += (int) ( 'posts' === $group );
		};

		add_action( 'wp_cache_set_last_changed', $count );
		$result = $this->upgrader->run_batch( 100 );
		remove_action( 'wp_cache_set_last_changed', $count );

		$this->assertSame( 3, $result['repathed'] );
		$this->assertSame( 1, $bumps );
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
	 * Each batch that changes a status leaves wp_count_posts() current.
	 *
	 * Core clears its cached counts only on transition_post_status, which the
	 * migration's direct writes never fire, so the list screen's status counts
	 * and the audit sweep's total would stay as they were before the batch.
	 * The first batch only bulk-publishes and the second only trashes, so each
	 * write path is checked on its own.
	 *
	 * @return void
	 */
	public function test_status_changes_clear_the_cached_post_counts() {
		$published_id = $this->create_legacy_redirect( '/old-page', 'https://example.com/same' );
		$trashed_id   = $this->create_legacy_redirect( '/old-page/', 'https://example.com/same' );

		$this->assertSame( 2, (int) wp_count_posts( PostType::POST_TYPE )->draft );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( 'publish', get_post_status( $published_id ) );
		$counts = wp_count_posts( PostType::POST_TYPE );
		$this->assertSame( 1, (int) $counts->publish, 'The bulk publish should be counted.' );
		$this->assertSame( 1, (int) $counts->draft );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( 'trash', get_post_status( $trashed_id ) );
		$counts = wp_count_posts( PostType::POST_TYPE );
		$this->assertSame( 0, (int) $counts->draft, 'The trashed duplicate should no longer count as a draft.' );
		$this->assertSame( 1, (int) $counts->trash );
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
	 * Re-walking the set does not republish a redirect someone disabled.
	 *
	 * From 2.0 on, 'draft' means "deliberately disabled". The publish pass is
	 * for 1.x data only, so a later version bump must leave those alone even
	 * though it re-walks every redirect.
	 *
	 * @return void
	 */
	public function test_deliberately_disabled_redirect_survives_a_later_upgrade() {
		update_option( Upgrader::VERSION_OPTION, 3 );

		// Dated so that no redirect can be older than the run: the separate
		// "touched since the upgrade began" guard is taken out of play, leaving
		// the version gate as the only thing standing between this redirect and
		// being republished.
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2100-01-01 00:00:00' );

		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post( $post_id )->post_status, 'A disabled redirect should stay disabled.' );
		$this->assertSame( 0, $result['published'] );
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
		$disabled_id = $this->insert_redirect_post(
			array(
				'post_name'  => md5( '/disabled-under-2x' ),
				'post_title' => '/disabled-under-2x',
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

		$this->run_to_completion( 2 );

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

		$stored  = static fn(): array => array(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT p.*, ( SELECT COUNT(*) FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID ) AS meta FROM {$wpdb->posts} p WHERE p.post_type = %s ORDER BY p.ID", PostType::POST_TYPE ),
				ARRAY_A
			),
			$wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpcom\\_legacy\\_redirector\\_%' ORDER BY option_name", ARRAY_A ),
		);
		$before  = $stored();
		$pending = $this->upgrader->count_pending();

		// The dry run writes nothing: no row, no marker, no upgrade state.
		$this->assertSame( $before, $stored() );
		$this->assertTrue( $this->upgrader->needs_upgrade() );

		$result = $this->upgrader->run_batch( 100 );

		// '/dupe/' is trashed and '/clash/' and '/converge//' drafted, so none
		// of them is published.
		$this->assertSame( 6, $pending['published'] );
		// '/already', plus '/clash/' and '/converge//': 1.x drafts already, so
		// drafting them as conflicts writes nothing.
		$this->assertSame( 3, $pending['unchanged'] );
		$this->assertSame( 1, $pending['skipped'] );
		$this->assertSame( 1, $pending['deduped'] );
		$this->assertCount( 2, $pending['conflicts'] );
		$this->assertSame( 1, $pending['normalized'] );

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
	 * Failures from every batch of a walk await a retry, not only the last batch's.
	 *
	 * @return void
	 */
	public function test_failures_from_every_batch_of_a_walk_await_a_retry() {
		$first_id  = $this->create_legacy_redirect( '/first/' );
		$second_id = $this->create_legacy_redirect( '/second/' );

		$this->with_row_writes_refused( fn() => $this->run_to_completion( 1 ) );

		$this->assertSame( 2, $this->upgrader->pending_retries() );

		$this->upgrader->retry_failed();

		$this->assertSame( '/first', get_post( $first_id )->post_title );
		$this->assertSame( '/second', get_post( $second_id )->post_title );
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

		$error = $this->refusal_stops( "UPDATE {$wpdb->posts} SET post_status", fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertStringContainsString( 'could not publish', $error->getMessage() );
		$this->assertSame( 0, (int) get_option( 'wpcom_legacy_redirector_upgrade_cursor', 0 ) );
		$this->assertTrue( $this->upgrader->needs_upgrade() );

		$this->upgrader->run_batch( 100 );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A batch does not run while another holds the lock, but takes over one left by a runner that died.
	 *
	 * @return void
	 */
	public function test_batch_waits_for_another_to_finish() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page' );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wpcom_legacy_redirector_upgrade_lock',
				'option_value' => (string) time(),
				'autoload'     => 'off',
			)
		);

		$waited = microtime( true );
		try {
			$this->upgrader->run_batch( 100, 1 );
			$this->fail( 'The batch should not have run.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'holds its lock', $e->getMessage() );
		}
		$this->assertGreaterThanOrEqual( 1.0, microtime( true ) - $waited );

		try {
			$this->upgrader->retry_failed();
			$this->fail( 'The retry should not have run.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'holds its lock', $e->getMessage() );
		}
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - HOUR_IN_SECONDS ) ), array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * A batch starts from where the walk is now, not from the cursor this process read earlier.
	 *
	 * `migrate` reads the cursor to report progress, then may wait while a
	 * web batch moves it on; starting from its own copy would plan that
	 * batch's rows again.
	 *
	 * @return void
	 */
	public function test_batch_starts_from_the_cursor_as_it_is_now() {
		global $wpdb;

		$ids = array(
			$this->create_legacy_redirect( '/first' ),
			$this->create_legacy_redirect( '/second' ),
			$this->create_legacy_redirect( '/third' ),
		);
		$this->upgrader->run_batch( 1 );
		$this->assertSame( $ids[0], (int) get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );

		// Another runner does the second row, writing past this process's cache.
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) $ids[1] ), array( 'option_name' => 'wpcom_legacy_redirector_upgrade_cursor' ) );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( 'draft', get_post_status( $ids[1] ) );
		$this->assertSame( 'publish', get_post_status( $ids[2] ) );
	}

	/**
	 * A retry leaves the record of re-keyed rows while the walk goes on, and clears it once the walk is done.
	 *
	 * Completing the walk clears it too, but a retry can record rows after that.
	 *
	 * @return void
	 */
	public function test_retry_clears_the_rekey_record_once_the_walk_is_done() {
		update_option( 'wpcom_legacy_redirector_upgrade_rekeyed', array( 1 => md5( '/x' ) ), false );

		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );
		$this->upgrader->retry_failed();
		$this->assertNotFalse( get_option( 'wpcom_legacy_redirector_upgrade_rekeyed' ) );

		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION );
		$this->upgrader->retry_failed();
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rekeyed' ) );
	}

	/**
	 * A database error taking the lock is reported as one, not as another batch running.
	 *
	 * @return void
	 */
	public function test_database_error_taking_the_lock_is_reported() {
		global $wpdb;

		$error = $this->refusal_stops( "INSERT IGNORE INTO {$wpdb->options}", fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertStringContainsString( 'could not take the migration lock', $error->getMessage() );
	}

	/**
	 * A batch whose lock was taken over leaves the new holder's lock in place.
	 *
	 * @return void
	 */
	public function test_batch_releases_only_its_own_lock() {
		global $wpdb;

		$this->create_legacy_redirect( '/old-page' );
		$take_over = static function ( string $query ) use ( $wpdb ): string {
			if ( str_starts_with( $query, "UPDATE {$wpdb->posts} SET post_status" ) ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => 'another runner' ), array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
			}
			return $query;
		};

		add_filter( 'query', $take_over );
		try {
			$this->upgrader->run_batch( 100 );
		} finally {
			remove_filter( 'query', $take_over );
		}

		$this->assertSame( 'another runner', $this->lock_value() );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
	}

	/**
	 * A batch running past five minutes renews its lock and its hold on web batches, so neither is taken over.
	 *
	 * @return void
	 */
	public function test_long_batch_keeps_its_lock() {
		global $wpdb;

		$this->create_legacy_redirect( '/old-page' );
		$this->upgrader->hold_web_batches();

		$aged    = false;
		$checked = false;
		$age     = $this->ager();
		$check   = function ( string $query ) use ( &$aged, &$checked, $wpdb, $age ): string {
			if ( ! $aged && str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" ) ) {
				$aged = true;
				$age( 6 * MINUTE_IN_SECONDS );
				delete_transient( 'wpcom_legacy_redirector_upgrade_cli' );
			}

			if ( ! $checked && str_starts_with( $query, "UPDATE {$wpdb->posts} SET post_status" ) ) {
				$checked = true;
				$this->assertNotFalse( get_transient( 'wpcom_legacy_redirector_upgrade_cli' ), 'The hold on web batches should be renewed.' );
				try {
					( new Upgrader() )->run_batch( 100 );
					$this->fail( 'A second batch should not take the lock over.' );
				} catch ( \RuntimeException $e ) {
					$this->assertStringContainsString( 'holds its lock', $e->getMessage() );
				}
			}
			return $query;
		};

		add_filter( 'query', $check );
		try {
			$result = $this->upgrader->run_batch( 100 );
		} finally {
			remove_filter( 'query', $check );
		}

		$this->assertTrue( $checked, 'The second batch should have been tried.' );
		$this->assertTrue( $result['complete'] );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * A batch whose lock was taken over stops before writing anything more.
	 *
	 * The new holder plans the same rows, against a re-key record it read
	 * before this batch's writes.
	 *
	 * @return void
	 */
	public function test_batch_stops_once_its_lock_is_taken_over() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page/' );
		$age     = $this->ager();
		$taken   = false;
		$take    = static function ( string $query ) use ( &$taken, $wpdb, $age ): string {
			if ( ! $taken && str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" ) ) {
				$taken = true;
				$age( 6 * MINUTE_IN_SECONDS );
				$wpdb->update( $wpdb->options, array( 'option_value' => 'another runner' ), array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
			}
			return $query;
		};

		add_filter( 'query', $take );
		try {
			$this->upgrader->run_batch( 100 );
			$this->fail( 'The batch should have stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'took over the lock', $e->getMessage() );
		} finally {
			remove_filter( 'query', $take );
		}

		$this->assertSame( '/old-page/', get_post( $post_id )->post_title );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );
		$this->assertSame( 'another runner', $this->lock_value() );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
	}

	/**
	 * A callback that makes the upgrader's batch lock look a number of seconds older, as if its batch had run that long.
	 *
	 * @return \Closure(int): void
	 */
	private function ager(): \Closure {
		global $wpdb;

		return \Closure::bind(
			function ( int $seconds ) use ( $wpdb ): void {
				$this->locked_at -= $seconds;
				$this->lock_value = (string) ( (int) $this->lock_value - $seconds );
				$wpdb->update( $wpdb->options, array( 'option_value' => $this->lock_value ), array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
			},
			$this->upgrader,
			Upgrader::class
		);
	}

	/**
	 * A batch that finds, once it holds the lock, that another has finished the upgrade does nothing.
	 *
	 * Otherwise it would start a new walk from the first redirect, stripping
	 * the home path from sources that no longer carry it.
	 *
	 * @return void
	 */
	public function test_batch_finds_the_upgrade_finished_under_the_lock() {
		$post_id = $this->create_legacy_redirect( '/old-page' );
		$this->upgrader->run_batch( 100 );
		$this->assertFalse( $this->upgrader->needs_upgrade() );

		$stale = array( Upgrader::VERSION_OPTION => '0' ) + wp_load_alloptions();
		wp_cache_set( 'alloptions', $stale, 'options' );
		$this->assertTrue( $this->upgrader->needs_upgrade() );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertTrue( $result['complete'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_started_gmt' ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * The batch lock's stored value, if any.
	 *
	 * @return string|null The value, or null when no lock is held.
	 */
	private function lock_value(): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wpcom_legacy_redirector_upgrade_lock' ) );
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

		$this->with_queries_refused( "SELECT * FROM {$wpdb->posts} WHERE post_type", fn() => $this->upgrader->maybe_upgrade() );

		$this->assertTrue( $this->upgrader->needs_upgrade(), 'A failed read must not complete the upgrade.' );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertNotFalse( get_transient( 'wpcom_legacy_redirector_upgrade_backoff' ), 'A failed batch should hold the next requests off.' );
	}

	/**
	 * A refused read of who holds each key stops the batch before it writes anything.
	 *
	 * Every plan is made against that map, and the cursor stays put, so the
	 * next run redoes the batch.
	 *
	 * @return void
	 */
	public function test_refused_key_map_stops_the_batch() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page/' );

		$error = $this->refusal_stops( "SELECT ID, post_name, post_date FROM {$wpdb->posts}", fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertStringStartsWith( 'the database could not read the redirects', $error->getMessage() );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( '/old-page/', get_post( $post_id )->post_title );
		$this->assertTrue( $this->upgrader->needs_upgrade() );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
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

		$this->run_to_completion( 1 );

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
		$result = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced, 'The simulated edit should have run.' );
		$this->assertSame( 'draft', get_post_status( $bulk_id ) );
		$this->assertSame( '/other-page/', get_post( $rekey_id )->post_title );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertSame( 0, $result['changed'] );
		$this->assertSame( $result['processed'], $result['changed'] + $result['unchanged'] + $result['skipped'] + count( $result['failed'] ) );
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
	 * A web request that finds the lock held holds the next requests off, until the backoff expires.
	 *
	 * Otherwise every page view sends writes to the primary to try the lock.
	 *
	 * @return void
	 */
	public function test_web_batches_back_off_while_another_holds_the_lock() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/old-page' );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wpcom_legacy_redirector_upgrade_lock',
				'option_value' => (string) time(),
				'autoload'     => 'off',
			)
		);

		$this->upgrader->maybe_upgrade();

		$this->assertNotFalse( get_transient( 'wpcom_legacy_redirector_upgrade_backoff' ) );

		$attempts = 0;
		$count    = static function ( string $query ) use ( &$attempts ): string {
			if ( str_contains( $query, 'wpcom_legacy_redirector_upgrade_lock' ) ) {
				++$attempts;
			}
			return $query;
		};

		add_filter( 'query', $count );
		$this->upgrader->maybe_upgrade();
		remove_filter( 'query', $count );

		$this->assertSame( 0, $attempts, 'A request during the backoff should not try the lock.' );

		$wpdb->delete( $wpdb->options, array( 'option_name' => 'wpcom_legacy_redirector_upgrade_lock' ) );
		update_option( '_transient_timeout_wpcom_legacy_redirector_upgrade_backoff', time() - 1, false );

		$this->upgrader->maybe_upgrade();

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A web batch that succeeds lifts a backoff set while it ran, by a request that lost the lock to it.
	 *
	 * @return void
	 */
	public function test_successful_web_batch_lifts_the_backoff() {
		global $wpdb;

		$this->create_legacy_redirect( '/old-page' );
		$this->create_legacy_redirect( '/other-page' );

		$lost = false;
		$lose = static function ( string $query ) use ( &$lost, $wpdb ): string {
			if ( ! $lost && str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" ) ) {
				$lost = true;
				set_transient( 'wpcom_legacy_redirector_upgrade_backoff', 1, MINUTE_IN_SECONDS );
			}
			return $query;
		};

		add_filter( 'query', $lose );
		$this->upgrader->maybe_upgrade();
		remove_filter( 'query', $lose );

		$this->assertTrue( $lost, 'The losing request should have run.' );
		$this->assertFalse( get_transient( 'wpcom_legacy_redirector_upgrade_backoff' ) );
	}

	/**
	 * The destination pass rewrites what needs it, and leaves the rest exactly as stored.
	 *
	 * The pass runs on every future version walk, so a rewrite that fired on
	 * an already-canonical row would report work forever. The dry run must
	 * count each rewrite as the run makes it, and whatever is written relative
	 * must survive the round trip back through the domain layer, or the
	 * redirect silently stops resolving.
	 *
	 * @dataProvider data_destination_pass
	 *
	 * @param string $stored     The destination as stored, with {home} for the home URL.
	 * @param string $expected   The destination after the pass, likewise.
	 * @param int    $normalized How many rows the pass should count as rewritten.
	 * @return void
	 */
	public function test_destination_pass( string $stored, string $expected, int $normalized ) {
		$home     = untrailingslashit( home_url() );
		$expected = str_replace( '{home}', $home, $expected );
		$post_id  = $this->create_legacy_redirect( '/old-page', str_replace( '{home}', $home, $stored ) );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( $normalized, $result['normalized'] );
		$this->assertSame( $normalized, $pending['normalized'] );
		$this->assertSame( $expected, get_post( $post_id )->post_excerpt );
		if ( str_starts_with( $expected, '/' ) ) {
			$this->assertTrue( DestinationUrl::from_string( $expected )->is_relative() );
		}
	}

	/**
	 * Destinations as stored, what the destination pass leaves, and whether it counts a rewrite.
	 *
	 * The double-slash case is built by concatenation, because home_url( '//foo' )
	 * would collapse the double slash it exists to preserve: the domain layer
	 * would reject '//foo' as scheme-relative, so it must be left as stored
	 * rather than corrupted.
	 *
	 * @return array<string, array{string, string, int}>
	 */
	public static function data_destination_pass(): array {
		return array(
			// Before version 4, whichever encoding was typed was what got
			// stored. The path decodes; the query keeps its encoding, because
			// its values have sub-structure a decode would corrupt.
			'encoded relative'      => array( '/caf%C3%A9?q=a%26b', '/café?q=a%26b', 1 ),
			'canonical relative'    => array( '/café', '/café', 0 ),
			'internal absolute'     => array( '{home}/new-page?a=1', '/new-page?a=1', 1 ),
			'internal double slash' => array( '{home}//foo', '{home}//foo', 0 ),
			'external'              => array( 'https://external.example.net/x', 'https://external.example.net/x', 0 ),
			// 1.x sent visitors to the kses-escaped URL, whose query has a
			// parameter named 'amp;b' where 'b' was meant.
			'kses-escaped relative' => array( '/new?a=1&amp;b=2', '/new?a=1&b=2', 1 ),
			'kses-escaped absolute' => array( 'https://example.com/new?a=1&amp;b=2', 'https://example.com/new?a=1&b=2', 1 ),
		);
	}

	/**
	 * The source pass strips a trailing slash from the path, and leaves canonical sources uncounted.
	 *
	 * The pass runs on every future version walk, so rewriting rows that are
	 * already canonical would report work forever.
	 *
	 * @dataProvider data_source_pass
	 *
	 * @param string $stored   The source as 1.x stored it.
	 * @param string $expected The source after the pass.
	 * @param int    $repathed How many rows the pass should count as re-keyed.
	 * @return void
	 */
	public function test_source_pass( string $stored, string $expected, int $repathed ) {
		$post_id = $this->create_legacy_redirect( $stored );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( $repathed, $result['repathed'] );
		$this->assertSame( $expected, get_post( $post_id )->post_title );
		$this->assertSame( md5( $expected ), get_post( $post_id )->post_name );
	}

	/**
	 * Sources as 1.x stored them, what the source pass leaves, and whether it counts a re-key.
	 *
	 * @return array<string, array{string, string, int}>
	 */
	public static function data_source_pass(): array {
		return array(
			'trailing slash'     => array( '/old-page/', '/old-page', 1 ),
			'slash in the query' => array( '/old-page/?ref=a/', '/old-page?ref=a/', 1 ),
			'already canonical'  => array( '/old-page', '/old-page', 0 ),
			// The site root keeps its slash rather than being emptied.
			'the site root'      => array( '/', '/', 0 ),
		);
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
	 * A collision between different destinations drafts the loser on its own key, reports it, and lists it until saved.
	 *
	 * Only a human can decide which destination was meant, so the survivor is
	 * left firing and the other is disabled rather than silently discarded.
	 * The loser keeps its own slug: two rows sharing a post_name would go
	 * through wp_unique_post_slug() and be silently suffixed, leaving the
	 * redirect findable under neither its old key nor its new one. Browsers
	 * did request its spelling, so its visitors now reach the live redirect's
	 * destination, and it is not marked as never having fired: it needs a
	 * decision rather than a delete. The save that settles it goes through
	 * the plugin's own save_post hook.
	 *
	 * @return void
	 */
	public function test_colliding_different_destination_is_drafted_and_reported() {
		$kept     = $this->create_legacy_redirect( '/old-page', 'https://example.com/one' );
		$loser_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/two' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['deduped'] );
		$this->assertSame( 0, $result['unfired'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'has the same source as', $result['conflicts'][0] );
		$this->assertStringEndsNotWith( '(never fired under 1.x)', $result['conflicts'][0] );

		$this->assertSame( 'draft', get_post( $loser_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept )->post_status );
		$this->assertSame( md5( '/old-page' ), get_post( $kept )->post_name );
		$this->assertSame( md5( '/old-page/' ), get_post( $loser_id )->post_name );

		$this->assertSame(
			array(
				$loser_id => array(
					'of'          => $kept,
					'never_fired' => false,
				),
			),
			$this->upgrader->duplicates()
		);

		wp_update_post(
			array(
				'ID'           => $loser_id,
				'post_excerpt' => 'https://example.com/one',
			)
		);

		$this->assertSame( array(), $this->upgrader->duplicates() );
	}

	/**
	 * A collision involving a redirect to a post is settled by the post ID.
	 *
	 * 1.x stored such a destination in post_parent with an empty excerpt, so
	 * comparing excerpts alone would call any two of them the same.
	 *
	 * @dataProvider data_post_destination_collisions
	 *
	 * @param string $first  The kept row's destination: 'post', 'other' (a second post) or 'url'.
	 * @param string $second The colliding row's destination, likewise.
	 * @param string $status The colliding row's expected status.
	 * @return void
	 */
	public function test_post_destination_collision_is_settled_by_post_id( string $first, string $second, string $status ) {
		$destinations = array(
			'post'  => self::factory()->post->create(),
			'other' => self::factory()->post->create(),
			'url'   => 'https://example.com/new',
		);
		$labels       = array(
			'post'  => 'post #' . $destinations['post'],
			'other' => 'post #' . $destinations['other'],
			'url'   => 'https://example.com/new',
		);

		$kept     = $this->create_legacy_redirect( '/a', $destinations[ $first ] );
		$loser_id = $this->create_legacy_redirect( '/a/', $destinations[ $second ] );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $kept ) );
		$this->assertSame( $status, get_post_status( $loser_id ) );

		$expected = 'trash' === $status
			? array()
			: array( sprintf( '/a/ → %s (#%d) has the same source as /a → %s (#%d)', $labels[ $second ], $loser_id, $labels[ $first ], $kept ) );
		$this->assertSame( $expected, $result['conflicts'] );
		$this->assertSame( (int) ( 'trash' === $status ), $result['deduped'] );
		$this->assertSame( 0, $result['normalized'] );
	}

	/**
	 * Pairs of colliding destinations involving a post, and how the colliding row ends up.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function data_post_destination_collisions(): array {
		return array(
			'the same post'   => array( 'post', 'post', 'trash' ),
			'different posts' => array( 'post', 'other', 'draft' ),
			'a post and URL'  => array( 'post', 'url', 'draft' ),
			'a URL and post'  => array( 'url', 'post', 'draft' ),
		);
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
			'trailing slash'             => array( '/old-page/', array( '/old-page', '/old-page/' ) ),
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

	/**
	 * A title kses escaped after its key was hashed is re-keyed from the text that was hashed.
	 *
	 * Saved in a web request by a user without unfiltered_html, a 1.x source
	 * '/search/?q=a+b&page=2' keeps the key of that text but has the title
	 * '/search/?q=a+b&amp;page=2'. Re-keying from the title would move it to a
	 * key no request produces.
	 *
	 * @return void
	 */
	public function test_kses_escaped_title_is_rekeyed_from_the_hashed_text() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/search/?q=a+b&page=2' );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '/search/?q=a+b&amp;page=2' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/search?q=a b&page=2', get_post( $post_id )->post_title );
		$this->assertInstanceOf(
			Redirect::class,
			( new PostTypeRedirectRepository() )->find_by_source( SourceUrl::from_string( '/search/?q=a+b&page=2' ) )
		);
	}

	/**
	 * A kses-escaped title over a key that is already canonical gets back the text it was hashed from.
	 *
	 * Nothing about the key changes, but every save rebuilds the key from the
	 * title, so leaving '&amp;' there would move the row on its next edit.
	 *
	 * @return void
	 */
	public function test_kses_escaped_title_over_a_canonical_key_is_unescaped() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/find?q=a&page=2' );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '/find?q=a&amp;page=2' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$this->assertSame( 1, $this->upgrader->count_pending()['repathed'] );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( '/find?q=a&page=2', get_post( $post_id )->post_title );
		$this->assertSame( md5( '/find?q=a&page=2' ), get_post( $post_id )->post_name );
	}

	/**
	 * A duplicate with a kses-escaped title keeps its key but gets back the text it was hashed from.
	 *
	 * Otherwise enabling it later would re-key it from the escaped title, to a
	 * key nobody holds, past the guard against taking the live row's source.
	 *
	 * @return void
	 */
	public function test_duplicate_with_a_kses_escaped_title_gets_back_the_hashed_text() {
		global $wpdb;

		$live_id      = $this->create_legacy_redirect( '/a?x=1&y=2', 'https://example.com/one' );
		$duplicate_id = $this->create_legacy_redirect( '/a/?x=1&y=2', 'https://example.com/two' );
		foreach ( array( $live_id, $duplicate_id ) as $id ) {
			$wpdb->update( $wpdb->posts, array( 'post_title' => str_replace( '&', '&amp;', get_post( $id )->post_title ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}

		$this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post_status( $duplicate_id ) );
		$this->assertSame( '/a/?x=1&y=2', get_post( $duplicate_id )->post_title );
		$this->assertSame( md5( '/a/?x=1&y=2' ), get_post( $duplicate_id )->post_name );
	}

	/**
	 * A trashed row's kses-escaped title is recognised through the key core gave it in the trash.
	 *
	 * @return void
	 */
	public function test_trashed_row_with_a_kses_escaped_title_gets_back_the_hashed_text() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/find?q=a&page=2' );
		wp_trash_post( $post_id );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '/find?q=a&amp;page=2' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		$this->assertSame( md5( '/find?q=a&page=2' ) . '__trashed', get_post( $post_id )->post_name );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/find?q=a&page=2', get_post( $post_id )->post_title );
	}

	/**
	 * A redirect core trashed under 1.x is restored onto the key the migration gave it.
	 *
	 * Core restores a trashed post onto the slug it had when trashed, which
	 * for this row is its 1.x key.
	 *
	 * @return void
	 */
	public function test_trashed_row_is_restored_onto_its_migrated_key() {
		$post_id = $this->create_legacy_redirect( '/old-page/' );
		wp_trash_post( $post_id );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( 1, $pending['repathed'] );

		wp_untrash_post( $post_id );

		$this->assertSame( md5( '/old-page' ), get_post( $post_id )->post_name );
		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
	}

	/**
	 * A trashed row re-keyed onto a key a live row then takes does not hide the live one.
	 *
	 * A lookup ignores the trash when the walk settles who holds a key, so the
	 * trashed '/x/' and the live '/x//' both end up on '/x'.
	 *
	 * @return void
	 */
	public function test_trashed_row_on_a_live_key_does_not_hide_it() {
		$trashed_id = $this->create_legacy_redirect( '/x/', 'https://example.com/old' );
		wp_trash_post( $trashed_id );
		$live_id = $this->create_legacy_redirect( '/x//' );

		$this->upgrader->run_batch( 100 );
		wp_cache_flush();

		$this->assertSame( md5( '/x' ), get_post( $trashed_id )->post_name );
		$this->assertSame( $live_id, ( new PostTypeRedirectRepository() )->find_by_source( SourceUrl::from_string( '/x' ) )?->id() );
	}

	/**
	 * A redirect trashed under 1.x and enabled after the migration goes live on its 2.0 key.
	 *
	 * Enabling saves it with a new status, and core puts a post leaving the
	 * trash back on the slug it had when trashed, as a restore does.
	 *
	 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
	 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
	 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
	 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
	 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
	 *
	 * @return void
	 */
	public function test_enabling_a_trashed_row_puts_it_on_its_migrated_key() {
		$post_id = $this->create_legacy_redirect( '/old-page/' );
		wp_trash_post( $post_id );
		$this->upgrader->run_batch( 100 );

		$this->assertTrue( $this->manager()->enable( $post_id ) );

		$this->assertSame( md5( '/old-page' ), get_post( $post_id )->post_name );
		$this->clear_lookup_cache( '/old-page' );
		$this->assertSame( $post_id, $this->repository()->find_by_source( SourceUrl::from_string( '/old-page' ) )?->id() );
	}

	/**
	 * A kses-escaped 1.x row restored before the migration reaches it stays on the key it was hashed to.
	 *
	 * Keying it by its escaped title would move it off the right key for good:
	 * the migration would then take the escaped text as what was hashed.
	 *
	 * @return void
	 */
	public function test_escaped_row_restored_before_the_walk_keeps_its_key() {
		global $wpdb;

		$post_id = $this->create_legacy_redirect( '/find?q=a&page=2' );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '/find?q=a&amp;page=2' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		wp_trash_post( $post_id );

		wp_untrash_post( $post_id );

		$this->assertSame( md5( '/find?q=a&page=2' ), get_post( $post_id )->post_name );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/find?q=a&page=2', get_post( $post_id )->post_title );
	}

	/**
	 * A duplicate the migration trashed is restored onto its own key, and the live redirect still answers.
	 *
	 * @return void
	 */
	public function test_restored_duplicate_keeps_its_own_key() {
		$kept_id  = $this->create_legacy_redirect( '/page' );
		$spare_id = $this->create_legacy_redirect( '/page/' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'trash', get_post_status( $spare_id ) );
		$this->assertSame( 1, $result['deduped'] );
		$this->assertSame( $result['changed'], $pending['changed'] );

		wp_untrash_post( $spare_id );

		$this->assertSame( md5( '/page/' ), get_post( $spare_id )->post_name );
		$this->assertSame( $kept_id, ( new PostTypeRedirectRepository() )->find_by_source( SourceUrl::from_string( '/page' ) )?->id() );
	}

	/**
	 * A walk over 2.0 data leaves '&amp;' in a destination alone.
	 *
	 * Only 1.x saved destinations through kses unchecked; a 2.0 one holding
	 * '&amp;' was saved that way on purpose, and a later walk must not change
	 * it.
	 *
	 * @return void
	 */
	public function test_later_walk_leaves_an_escaped_ampersand_in_a_destination() {
		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );
		$post_id = $this->create_legacy_redirect( '/deliberate', '/a?b=&amp;c' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/a?b=&amp;c', get_post( $post_id )->post_excerpt );
	}

	/**
	 * A disabled duplicate stored with raw non-ASCII is marked as never having fired.
	 *
	 * Browsers send non-ASCII percent-encoded, and 1.x compared the request
	 * with the stored text as it was, so '/café-x/' never matched. The encoded
	 * spelling did, and it is the one left live.
	 *
	 * @return void
	 */
	public function test_raw_non_ascii_duplicate_is_marked_as_never_fired() {
		$encoded_id = $this->create_legacy_redirect( '/caf%C3%A9-x', 'https://example.com/one' );
		$raw_id     = $this->create_legacy_redirect( '/café-x/', 'https://example.com/two' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['unfired'] );
		$this->assertSame( 'publish', get_post_status( $encoded_id ) );
		$this->assertSame( 'draft', get_post_status( $raw_id ) );
		$this->assertTrue( $this->upgrader->duplicates()[ $raw_id ]['never_fired'] );

		wp_trash_post( $raw_id );

		$this->assertSame( '', get_post_meta( $raw_id, Upgrader::NEVER_FIRED_META_KEY, true ) );
	}

	/**
	 * Writing a batch in bulk gives exactly what writing each row alone gives.
	 *
	 * The dry run must predict the bulk result exactly, and the same mixed set
	 * is migrated two more ways: with every bulk statement made to fall short,
	 * so each row is read back and written alone; and in batches of seven, so
	 * rows interact across batches. Every row, marker, count and report line
	 * must come out the same.
	 *
	 * @return void
	 */
	public function test_bulk_writes_match_writes_made_alone() {
		global $wpdb;

		$bulk_ids = $this->create_mixed_legacy_set();
		$pending  = $this->upgrader->count_pending();
		$small    = $this->upgrader->count_pending( null, 9 );
		$seen     = array(
			'bulk'    => 0,
			'per-row' => 0,
			'read'    => 0,
		);
		$watch    = static function ( string $query ) use ( &$seen, $wpdb ): string {
			if ( str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) ) {
				++$seen[ str_contains( $query, 'CASE ID' ) ? 'bulk' : 'per-row' ];
			}
			$seen['read'] += (int) str_starts_with( $query, 'SELECT ID, post_title, post_name, post_status, post_excerpt, post_modified_gmt' );
			return $query;
		};

		add_filter( 'query', $watch );
		$bulk = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $watch );

		// Every change went in bulk statements, all of which landed, and
		// converging rows made them flush part-way.
		$this->assertGreaterThan( 1, $seen['bulk'] );
		$this->assertSame( 0, $seen['per-row'] );
		$this->assertSame( 0, $seen['read'] );
		$bulk_state = $this->snapshot( $bulk_ids );

		// The dry run predicted exactly this.
		$keys = array( 'changed', 'unchanged', 'skipped', 'published', 'repathed', 'deduped', 'normalized', 'unfired' );
		$this->assertSame( wp_array_slice_assoc( $bulk, $keys ), wp_array_slice_assoc( $pending, $keys ), 'dry run' );
		$this->assertSame( self::by_position( $bulk['conflicts'], $bulk_ids ), self::by_position( $pending['conflicts'], $bulk_ids ), 'dry run' );

		// So does one read in batches of nine, which splits the '/converge/'
		// pair: the second must collide with the key the first would take in
		// the previous batch, which the database does not show yet.
		$this->assertSame( wp_array_slice_assoc( $pending, $keys ), wp_array_slice_assoc( $small, $keys ), 'dry run in batches of nine' );
		$this->assertSame( $pending['conflicts'], $small['conflicts'], 'dry run in batches of nine' );

		$keys[] = 'processed';
		$runs   = array(
			'every bulk statement falling short' => static fn( string $query ): string => str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $query, 'CASE ID' ) ? $query . ' AND 1 = 0' : $query,
			'batches of seven'                   => null,
		);

		foreach ( $runs as $label => $filter ) {
			$this->forget_every_redirect();
			$ids      = $this->create_mixed_legacy_set();
			$altered  = 0;
			$tracking = static function ( string $query ) use ( $filter, &$altered ): string {
				$changed  = null === $filter ? $query : $filter( $query );
				$altered += (int) ( $changed !== $query );
				return $changed;
			};

			$this->upgrader = new Upgrader();
			add_filter( 'query', $tracking );
			$result = $this->run_to_completion( null === $filter ? 7 : 100 );
			remove_filter( 'query', $tracking );

			if ( null !== $filter ) {
				$this->assertGreaterThan( 0, $altered, $label . ': the run should have taken its intended path.' );
			}
			$this->assertSame( $bulk_state, $this->snapshot( $ids ), $label );
			$this->assertSame( wp_array_slice_assoc( $result, $keys ), wp_array_slice_assoc( $bulk, $keys ), $label );
			$this->assertSame( self::by_position( $result['conflicts'], $ids ), self::by_position( $bulk['conflicts'], $bulk_ids ), $label );
		}

		$this->assertNotSame( array(), $bulk['conflicts'] );
		$this->assertSame( array(), $bulk['failed'] );
	}

	/**
	 * A held write that turns out skipped is taken back out of the key map before the next row is planned.
	 *
	 * '/k/' is held to move onto '/k'; '/k//' wants '/k' too, so the held
	 * write is made first - and a user edits '/k/' just then. Row by row,
	 * '/k/' would be skipped and '/k//' would take '/k', so that is what must
	 * happen here, not a collision with a move that never landed.
	 *
	 * @return void
	 */
	public function test_skipped_held_write_frees_its_key_for_the_next_row() {
		global $wpdb;

		$edited_id = $this->create_legacy_redirect( '/k/', 'https://example.com/one' );
		$next_id   = $this->create_legacy_redirect( '/k//', 'https://example.com/two' );

		$raced = false;
		$race  = static function ( string $query ) use ( &$raced, $wpdb, $edited_id ): string {
			if ( ! $raced && str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $query, 'CASE ID' ) ) {
				$raced = true;
				$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2099-01-01 00:00:00' ), array( 'ID' => $edited_id ) );
			}
			return $query;
		};

		add_filter( 'query', $race );
		$result = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( '/k/', get_post( $edited_id )->post_title );
		$this->assertSame( '/k', get_post( $next_id )->post_title );
		$this->assertSame( 'publish', get_post_status( $next_id ) );
	}

	/**
	 * A held write that did not land, and was not edited, is written alone.
	 *
	 * Simulated by a bulk statement that leaves one row out, as a statement
	 * partly applied by a non-transactional table would.
	 *
	 * @return void
	 */
	public function test_held_write_that_did_not_land_is_written_alone() {
		global $wpdb;

		$left_out_id = $this->create_legacy_redirect( '/one/' );
		$other_id    = $this->create_legacy_redirect( '/two/' );

		$partial = static fn( string $query ): string => str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $query, 'CASE ID' )
			? $query . ' AND ID <> ' . $left_out_id
			: $query;

		add_filter( 'query', $partial );
		$result = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $partial );

		$this->assertSame( 2, $result['changed'] );
		$this->assertSame( 2, $result['repathed'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( '/one', get_post( $left_out_id )->post_title );
		$this->assertSame( '/two', get_post( $other_id )->post_title );
	}

	/**
	 * A value too long for its column is written alone, so it is refused and reported, not truncated.
	 *
	 * The query of a relative destination is kept percent-encoded, so one
	 * stored raw can grow threefold. $wpdb->update() refuses what no longer
	 * fits; a multi-row statement would silently cut it short.
	 *
	 * @return void
	 */
	public function test_too_long_value_is_refused_not_truncated() {
		$long_id  = $this->create_legacy_redirect( '/long', '/p?q=' . str_repeat( 'é', 30000 ) );
		$other_id = $this->create_legacy_redirect( '/other/' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertCount( 1, $result['failed'] );
		$this->assertStringStartsWith( '#' . $long_id . ' (/long): ', $result['failed'][0] );
		$this->assertSame( '/p?q=' . str_repeat( 'é', 30000 ), get_post( $long_id )->post_excerpt );
		$this->assertSame( '/other', get_post( $other_id )->post_title );
	}

	/**
	 * A value that is not valid UTF-8 is written alone, so it is refused and reported, not mangled.
	 *
	 * A UTF-8 table cannot hold such a value, so the read is made to return
	 * one. In a bulk statement it would take the other rows' writes down with
	 * it, or be stored mangled; alone, $wpdb->update() refuses it against its
	 * own row while the rest still go in bulk.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_value_is_written_alone_and_reported() {
		global $wpdb;

		$bad_id = $this->create_legacy_redirect( '/bad' );
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'https://example.com/x?a=1&amp;b=2' ), array( 'ID' => $bad_id ) );
		$other_id = $this->create_legacy_redirect( '/other/' );

		$read_back = 0;
		$invalid   = static function ( string $query ) use ( $wpdb, $bad_id, &$read_back ): string {
			$read_back += (int) str_starts_with( $query, 'SELECT ID, post_title, post_name, post_status, post_excerpt, post_modified_gmt' );

			return str_starts_with( $query, "SELECT * FROM {$wpdb->posts} WHERE post_type" )
				? "SELECT *, IF(ID = {$bad_id}, CONCAT(CAST(post_excerpt AS BINARY), 0xFF), post_excerpt) AS post_excerpt" . substr( $query, strlen( 'SELECT *' ) )
				: $query;
		};

		add_filter( 'query', $invalid );
		$result = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $invalid );

		$this->assertCount( 1, $result['failed'] );
		$this->assertStringStartsWith( '#' . $bad_id . ' (/bad): ', $result['failed'][0] );
		$this->assertSame( 'https://example.com/x?a=1&amp;b=2', $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $bad_id ) ) );
		$this->assertSame( '/other', get_post( $other_id )->post_title );
		$this->assertSame( 0, $read_back, 'The other row should have gone in a bulk statement that landed.' );
	}

	/**
	 * The dry run predicts a trashed row's key the way the run treats it.
	 *
	 * A lookup ignores the trash, so a row re-keyed while in the trash does
	 * not hold its new key, and a live row converging on it takes it.
	 *
	 * @return void
	 */
	public function test_dry_run_ignores_a_trashed_row_on_a_key_as_the_run_does() {
		global $wpdb;

		$trashed_id = $this->create_legacy_redirect( '/tr/', 'https://example.com/one' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $trashed_id ) );
		$live_id = $this->create_legacy_redirect( '/tr//', 'https://example.com/two' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( 'publish', get_post_status( $live_id ) );
		$this->assertSame( '/tr', get_post( $live_id )->post_title );

		$keys = array( 'changed', 'unchanged', 'published', 'repathed', 'deduped' );
		$this->assertSame( wp_array_slice_assoc( $pending, $keys ), wp_array_slice_assoc( $result, $keys ) );
		$this->assertSame( array(), $pending['conflicts'] );
	}

	/**
	 * A row written in bulk loses its cached copies, as one written alone does.
	 *
	 * Its post object, cached while it was a draft, and the lookup cache's
	 * "nothing here" for its new source would otherwise hide the change.
	 *
	 * @return void
	 */
	public function test_bulk_written_row_loses_its_cached_copies() {
		$post_id   = $this->create_legacy_redirect( '/cached/' );
		$cache_key = CachingRedirectRepository::cache_key( SourceUrl::from_string( '/cached' )->hash() );

		$this->assertSame( 'draft', get_post( $post_id )->post_status );
		wp_cache_set( $cache_key, 0, CachingRedirectRepository::CACHE_GROUP );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post( $post_id )->post_status );
		$this->assertSame( '/cached', get_post( $post_id )->post_title );
		$this->assertFalse( wp_cache_get( $cache_key, CachingRedirectRepository::CACHE_GROUP ) );
	}

	/**
	 * When the rows cannot be read back, every held row loses its cached copies before the batch stops.
	 *
	 * Some may have landed, and a rerun will find them already done, so
	 * nothing would clear their cached copies later.
	 *
	 * @return void
	 */
	public function test_failed_read_back_still_clears_the_cached_copies() {
		global $wpdb;

		$left_out_id = $this->create_legacy_redirect( '/one/' );
		$landed_id   = $this->create_legacy_redirect( '/two/' );
		$this->assertSame( 'draft', get_post( $landed_id )->post_status );

		$break = static function ( string $query ) use ( $wpdb, $left_out_id ): string {
			if ( str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $query, 'CASE ID' ) ) {
				return $query . ' AND ID <> ' . $left_out_id;
			}
			return str_starts_with( $query, 'SELECT ID, post_title, post_name, post_status, post_excerpt, post_modified_gmt' ) ? '' : $query;
		};

		add_filter( 'query', $break );
		try {
			$this->upgrader->run_batch( 100 );
			$this->fail( 'The batch should have stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not read back', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
		}

		$this->assertSame( 'publish', get_post( $landed_id )->post_status );
		$this->assertSame( '/two', get_post( $landed_id )->post_title );
	}

	/**
	 * A user's edit that leaves the planned values in place is still the user's, not ours.
	 *
	 * The row is to be disabled as a duplicate; a user disables it first. Row
	 * by row, the write would find it edited and skip it, marking nothing.
	 *
	 * @return void
	 */
	public function test_edit_matching_the_plan_is_skipped_not_counted_as_written() {
		global $wpdb;

		$this->create_legacy_redirect( '/match', 'https://example.com/one' );
		$edited_id = $this->create_legacy_redirect( '/match/', 'https://example.com/two' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $edited_id ) );

		$raced = false;
		$race  = static function ( string $query ) use ( &$raced, $wpdb, $edited_id ): string {
			if ( ! $raced && str_starts_with( $query, "UPDATE `{$wpdb->posts}` SET" ) && str_contains( $query, 'CASE ID' ) ) {
				$raced = true;
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_status'       => 'draft',
						'post_modified_gmt' => '2099-01-01 00:00:00',
					),
					array( 'ID' => $edited_id )
				);
			}
			return $query;
		};

		add_filter( 'query', $race );
		$result = $this->upgrader->run_batch( 100 );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( array(), $this->upgrader->duplicates() );
	}

	/**
	 * Create one of every shape the migration handles, in an order that exercises its interactions.
	 *
	 * @return int[] The created IDs, in creation order.
	 */
	private function create_mixed_legacy_set(): array {
		global $wpdb;

		$ids = array();
		foreach (
			array(
				array( '/plain', 'https://example.com/new' ),
				array( '/slash/', 'https://example.com/new' ),
				array( '/caf%C3%A9-e', 'https://example.com/new' ),
				array( '/café-raw/', 'https://example.com/new' ),
				array( '/dupe', 'https://example.com/same' ),
				array( '/dupe/', 'https://example.com/same' ),
				array( '/clash', 'https://example.com/one' ),
				array( '/clash/', 'https://example.com/two' ),
				array( '/converge/', 'https://example.com/one' ),
				array( '/converge//', 'https://example.com/two' ),
				array( '/caf%C3%A9-x', 'https://example.com/one' ),
				array( '/café-x/', 'https://example.com/two' ),
				array( '/internal', home_url( '/target' ) ),
				array( '/relative', '/caf%C3%A9' ),
				array( '//?q=1', 'https://example.com/same-two' ),
				array( '/?q=1', 'https://example.com/same-two' ),
				array( '/plus+sign/', 'https://example.com/new' ),
				array( '/sp%20ace', 'https://example.com/new' ),
				// A live row whose destination is internal and pending its
				// rewrite, and a row colliding with it: the report names it.
				array( '/label', home_url( '/label-target' ) ),
				array( '/label/', 'https://example.com/elsewhere' ),
			) as list( $source, $destination )
		) {
			$ids[] = $this->create_legacy_redirect( $source, $destination );
		}

		// Stored by a web request under kses: the key is of the '&' text.
		$ids[] = $this->create_legacy_redirect( '/k/?a=1&b=2' );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '/k/?a=1&amp;b=2' ), array( 'ID' => end( $ids ) ) );

		// A trashed row and an auto-draft each re-keyed onto a source, then a
		// live row converging on each: lookups ignore both statuses.
		foreach ( array( 'trash', 'auto-draft' ) as $status ) {
			$ids[] = $this->create_legacy_redirect( "/{$status}-first/", 'https://example.com/one' );
			$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => end( $ids ) ) );
			$ids[] = $this->create_legacy_redirect( "/{$status}-first//", 'https://example.com/two' );
		}

		// Two rows already sharing one key, a year apart, and a third
		// converging on it: it must collide with the newer.
		foreach ( array( '2019-06-01 12:00:00', '2020-06-01 12:00:00' ) as $date ) {
			$ids[] = $this->create_legacy_redirect( '/twin', 'https://example.com/twin-' . substr( $date, 0, 4 ) );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => $date,
					'post_date_gmt' => $date,
				),
				array( 'ID' => end( $ids ) )
			);
		}
		$ids[] = $this->create_legacy_redirect( '/twin/', 'https://example.com/twin-other' );

		// Already in the trash, already published, and edited since the upgrade began.
		$ids[] = $this->create_legacy_redirect( '/in-trash/' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => end( $ids ) ) );
		$ids[] = $this->create_legacy_redirect( '/already' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => end( $ids ) ) );

		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2000-01-01 00:00:00' );
		$ids[] = $this->create_legacy_redirect( '/edited/' );
		wp_update_post(
			array(
				'ID'          => end( $ids ),
				'post_status' => 'draft',
			)
		);

		wp_cache_flush();

		return $ids;
	}

	/**
	 * Each row's migrated state, with duplicate markers given as positions in the set.
	 *
	 * @param int[] $ids The set's IDs, in creation order.
	 * @return array<int, array<int, mixed>> One entry per row.
	 */
	private function snapshot( array $ids ): array {
		global $wpdb;

		$position = array_flip( $ids );

		return array_map(
			static function ( int $id ) use ( $wpdb, $position ): array {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_name, post_status, post_excerpt, post_date, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
				$of  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, Upgrader::DUPLICATE_META_KEY ) );

				return array(
					$row['post_title'],
					$row['post_name'],
					$row['post_status'],
					$row['post_excerpt'],
					$of > 0 ? $position[ $of ] : null,
					null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, Upgrader::NEVER_FIRED_META_KEY ) ),
				);
			},
			$ids
		);
	}

	/**
	 * Report lines with each redirect ID replaced by its position in the set.
	 *
	 * @param string[] $lines The report lines.
	 * @param int[]    $ids   The set's IDs, in creation order.
	 * @return string[] The lines, comparable across two copies of the set.
	 */
	private static function by_position( array $lines, array $ids ): array {
		$position = array_flip( $ids );

		return array_map(
			static fn( string $line ): string => (string) preg_replace_callback( '/#(\d+)/', static fn( array $m ): string => '#' . $position[ (int) $m[1] ], $line ),
			$lines
		);
	}

	/**
	 * Remove every redirect, whatever its status, and every trace of an upgrade.
	 *
	 * @return void
	 */
	private function forget_every_redirect(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s", PostType::POST_TYPE ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type = %s", PostType::POST_TYPE ) );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpcom\\_legacy\\_redirector\\_%'" );
		wp_cache_flush();
	}
}
