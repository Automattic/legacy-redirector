<?php
/**
 * Data upgrade integration tests for a single site installed in a subdirectory.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\LoopDetector;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderSubdirectoryTest class.
 *
 * A single site installed at example.com/blog has a non-empty home path, so
 * 1.x stored sources as '/blog/old-page' — it read the raw REQUEST_URI path
 * for both storage and matching, so the two agreed. 2.0 strips the home path
 * before lookup and searches for '/old-page', which never matches.
 *
 * The repath pass exists to reconcile exactly that, but it used to be gated on
 * is_multisite() and so never ran here, leaving every legacy redirect inert
 * while the migration reported success. These tests pin the predicate to the
 * home path instead.
 *
 * A subdirectory subsite's 1.x data has exactly the same shape, so this is
 * also where the home-path logic is tested for multisite: the home_url
 * filter gives every CI cell a home path, where UpgraderMultisiteTest runs
 * only in the multisite one.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class UpgraderSubdirectoryTest extends TestCase {

	use UpgraderTestHelper;

	/**
	 * The subdirectory the site is pretending to live in, without slashes.
	 */
	private const SUBDIR = 'blog';

	/**
	 * The upgrade routine under test.
	 *
	 * @var Upgrader
	 */
	private Upgrader $upgrader;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'home_url', array( $this, 'filter_home_url' ) );

		$this->upgrader = new Upgrader();

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( 'wpcom_legacy_redirector_upgrade_started_gmt' );
		delete_option( 'wpcom_legacy_redirector_upgrade_cursor' );
		delete_option( 'wpcom_legacy_redirector_upgrade_ceiling' );
		delete_option( 'wpcom_legacy_redirector_upgrade_rekeyed' );
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'home_url', array( $this, 'filter_home_url' ) );

		parent::tear_down();
	}

	/**
	 * Move home into a subdirectory, so the home path is non-empty.
	 *
	 * @param string $url The home URL.
	 * @return string The home URL with the subdirectory appended.
	 */
	public function filter_home_url( $url ): string {
		return untrailingslashit( $url ) . '/' . self::SUBDIR;
	}

	/**
	 * Create a redirect exactly as 1.x stored it on a subdirectory install.
	 *
	 * @param string     $path_within_site The path below the subdirectory, e.g. '/old-page'.
	 * @param string|int $destination      The destination URL, or the ID of the post to redirect to.
	 * @return int The created post ID.
	 */
	private function create_prefixed_redirect( string $path_within_site, string|int $destination = 'https://example.com/new' ): int {
		return $this->create_legacy_redirect( '/' . self::SUBDIR . $path_within_site, $destination );
	}

	/**
	 * The home path is stripped and the source is rehashed, on a single site.
	 *
	 * @return void
	 */
	public function test_home_path_is_stripped_from_legacy_source_on_single_site(): void {
		$post_id = $this->create_prefixed_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( '/old-page', $post->post_title, 'The home path should have been stripped.' );
		$this->assertSame( md5( '/old-page' ), $post->post_name, 'The source hash should match the rewritten path.' );
		$this->assertSame( 'publish', $post->post_status, 'The redirect should also have been published.' );
		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( 1, $result['published'] );
	}

	/**
	 * After migrating, the redirect resolves for the URL a visitor requests.
	 *
	 * This is the assertion the old is_multisite() gate failed: publishing
	 * alone left the redirect stored under a key no request ever produces.
	 *
	 * @return void
	 */
	public function test_migrated_redirect_is_reachable_by_lookup(): void {
		$this->create_prefixed_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$redirect_data = $this->resolver()->get_redirect_data( '/' . self::SUBDIR . '/old-page' );

		$this->assertNotEmpty( $redirect_data, 'The migrated redirect should resolve for the real request path.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A unicode legacy source is repathed and stays reachable.
	 *
	 * HomePath::make_relative() strips the home path segment by segment, and
	 * must leave the multibyte remainder whole: a path cut mid-sequence would
	 * give a post_title that is not valid UTF-8 and a hash no request can
	 * match. The rehash has to agree with what SourceUrl produces from the
	 * percent-encoded request a browser actually sends.
	 *
	 * @return void
	 */
	public function test_unicode_legacy_source_is_repathed_and_reachable(): void {
		$path    = '/привет-🎉';
		$post_id = $this->create_prefixed_redirect( $path );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( $path, $post->post_title, 'The home path should have been stripped without damaging the unicode.' );
		$this->assertSame( md5( $path ), $post->post_name, 'The source hash should match the rewritten unicode path.' );
		$this->assertSame( 1, $result['repathed'] );

		$redirect_data = $this->resolver()->get_redirect_data( '/' . self::SUBDIR . '/' . rawurlencode( 'привет-🎉' ) );

		$this->assertNotEmpty( $redirect_data, 'The migrated unicode redirect should resolve for the encoded request path.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A unicode path that only resembles the home prefix is left alone.
	 *
	 * The subdirectory is ASCII, but the byte-prefix test still has to hold a
	 * segment boundary when the rest of the path is not.
	 *
	 * @return void
	 */
	public function test_unicode_path_outside_the_home_prefix_is_not_repathed(): void {
		$prefixed = '/' . self::SUBDIR . 'ging/привет';

		$post_id = $this->create_legacy_redirect( $prefixed );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( $prefixed, $post->post_title, 'A path outside the home prefix should keep its title.' );
		$this->assertSame( md5( $prefixed ), $post->post_name );
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * The dry run reports the same work the real run would do.
	 *
	 * @return void
	 */
	public function test_dry_run_counts_the_repath_on_single_site(): void {
		$this->create_prefixed_redirect( '/old-page' );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['repathed'], 'The dry run should count the repath it would perform.' );
	}

	/**
	 * The dry run frees a key an earlier batch's row would leave, as the run does.
	 *
	 * '/blog/old-page' moves off md5( '/blog/old-page' ) onto md5( '/old-page' ),
	 * and the later '/blog/blog/old-page' strips to '/blog/old-page' and wants
	 * the key it left. The run writes the first move before planning the
	 * second, so the later row simply takes the key; the dry run writes
	 * nothing, and only forgetting the rows it marked as gone keeps the
	 * database's stale owner from making the later row a duplicate. A batch
	 * of one puts the two rows in separate batches.
	 *
	 * @return void
	 */
	public function test_dry_run_frees_a_key_an_earlier_batch_would_leave(): void {
		$this->create_prefixed_redirect( '/old-page' );
		$later = $this->create_prefixed_redirect( '/blog/old-page' );

		$pending = $this->upgrader->count_pending( null, 1 );
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( md5( '/blog/old-page' ), get_post( $later )->post_name, 'The run should hand the later row the freed key.' );
		$this->assertSame( 2, $result['repathed'] );
		$this->assertSame( 0, $result['deduped'] );
		$this->assertSame( 2, $pending['repathed'], 'The dry run should predict both repaths.' );
		$this->assertSame( 0, $pending['deduped'], 'The dry run should not predict a duplicate the run does not find.' );
		$this->assertSame( array(), $pending['conflicts'] );
	}

	/**
	 * A retry strips the home path, as the walk that failed the row would have.
	 *
	 * The retry comes once the upgrade has completed, when a walk would no
	 * longer take the data for 1.x.
	 *
	 * @return void
	 */
	public function test_retry_strips_the_home_path(): void {
		$post_id = $this->create_prefixed_redirect( '/old-page' );

		$this->with_row_writes_refused( fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertFalse( $this->upgrader->needs_upgrade() );
		$this->assertSame( 1, $this->upgrader->pending_retries() );

		$retry = $this->upgrader->retry_failed();

		$post = get_post( $post_id );
		$this->assertSame( '/old-page', $post->post_title );
		$this->assertSame( md5( '/old-page' ), $post->post_name );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 1, $retry['repathed'] );
	}

	/**
	 * A refused final version write keeps the walk's state, so the home path is not stripped twice.
	 *
	 * Were the state cleared, the next batch would take the site for 1.x data
	 * with nothing walked yet and strip the home path from every source again.
	 *
	 * @return void
	 */
	public function test_refused_version_write_does_not_strip_the_home_path_twice(): void {
		global $wpdb;

		$post_id = $this->create_prefixed_redirect( '/blog/old-page' );
		$refuse  = static fn( string $query ): string => str_contains( $query, Upgrader::VERSION_OPTION ) && ! str_starts_with( $query, 'SELECT' ) ? '' : $query;

		add_filter( 'query', $refuse );
		try {
			$this->upgrader->run_batch( 100 );
			$this->fail( 'The batch should have stopped.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not record', $e->getMessage() );
		} finally {
			remove_filter( 'query', $refuse );
		}

		$this->assertSame( $post_id, (int) get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );
		$this->assertSame( $post_id, (int) get_option( 'wpcom_legacy_redirector_upgrade_ceiling' ) );
		$this->assertNotEmpty( get_option( 'wpcom_legacy_redirector_upgrade_rekeyed' ) );

		$this->upgrader->run_batch( 100 );

		$this->assertFalse( $this->upgrader->needs_upgrade() );
		$this->assertSame( '/blog/old-page', get_post( $post_id )->post_title );
	}

	/**
	 * A path that already omits the prefix is left untouched.
	 *
	 * @return void
	 */
	public function test_already_relative_source_is_not_rewritten() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * A prefixed source written under 2.0 is never rewritten by a re-walk.
	 *
	 * On a site at /blog, a stored '/blog/old-page' created under
	 * 2.0 is indistinguishable from a deliberate redirect for the real URL
	 * /blog/blog/old-page, so the repath pass must leave it alone once
	 * the site's data is past the 1.x boundary.
	 *
	 * @return void
	 */
	public function test_prefixed_source_written_under_2_0_is_not_rewritten() {
		update_option( Upgrader::VERSION_OPTION, 2 );
		$post_id = $this->create_prefixed_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame(
			'/' . self::SUBDIR . '/old-page',
			get_post( $post_id )->post_title,
			'A prefixed source under 2.0 may be a deliberate double-prefix redirect and must not be touched.'
		);
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * A rewrite that collides with an identical redirect is trashed.
	 *
	 * Both rows send visitors to the same place, so the prefixed one is pure
	 * redundancy once it has been made home-relative. It cannot simply be
	 * left as it was: lookups are keyed on the relative form, so a row keeping
	 * the prefix would never fire again while still appearing to be live.
	 *
	 * @return void
	 */
	public function test_colliding_rewrite_of_the_same_destination_is_trashed() {
		$prefixed_id = $this->create_prefixed_redirect( '/old-page' );
		$kept_id     = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['deduped'] );
		$this->assertSame( array(), $result['conflicts'] );

		$this->assertSame( 'trash', get_post( $prefixed_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept_id )->post_status );
	}

	/**
	 * A rewrite that collides with a different destination is drafted and reported.
	 *
	 * @return void
	 */
	public function test_colliding_rewrite_of_a_different_destination_is_drafted() {
		$prefixed_id = $this->create_prefixed_redirect( '/old-page' );

		$kept_id = $this->create_legacy_redirect( '/old-page', 'https://example.com/somewhere-else' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['deduped'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'has the same source as', $result['conflicts'][0] );

		$this->assertSame( 'draft', get_post( $prefixed_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept_id )->post_status );

		// The loser keeps its own slug rather than contending for the winner's.
		$this->assertSame( md5( '/' . self::SUBDIR . '/old-page' ), get_post( $prefixed_id )->post_name );
	}

	/**
	 * A disabled duplicate stored without the home path is marked as never having fired.
	 *
	 * Under a home path, every 1.x request carried the home path, so '/old-page/'
	 * stored without it never matched anything. Disabling it changed nothing
	 * for visitors, and the mark says so.
	 *
	 * @return void
	 */
	public function test_unprefixed_duplicate_is_marked_as_never_fired() {
		$prefixed_id = $this->create_prefixed_redirect( '/old-page' );

		$unprefixed_id = $this->create_legacy_redirect( '/old-page/', 'https://example.com/somewhere-else' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['unfired'] );
		$this->assertStringEndsWith( '(never fired under 1.x)', $result['conflicts'][0] );
		$this->assertSame( 'publish', get_post( $prefixed_id )->post_status );
		$this->assertSame( 'draft', get_post( $unprefixed_id )->post_status );
		$this->assertSame(
			array(
				$unprefixed_id => array(
					'of'          => $prefixed_id,
					'never_fired' => true,
				),
			),
			$this->upgrader->duplicates()
		);
	}
	/**
	 * A row can take a key another row in the same batch has just left.
	 *
	 * Under a home path, the 1.x '/blog/x' becomes '/x', and the 1.x '/blog/blog/x'
	 * becomes '/blog/x' - the key the first row held when the batch began. A
	 * batch planned before any of it is written must know that key is free.
	 *
	 * @return void
	 */
	public function test_row_takes_a_key_freed_earlier_in_the_batch() {
		$first_id  = $this->create_prefixed_redirect( '/x' );
		$second_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( '/x', get_post( $first_id )->post_title );
		$this->assertSame( '/' . self::SUBDIR . '/x', get_post( $second_id )->post_title );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $second_id )->post_name );
		$this->assertSame( 'publish', get_post_status( $first_id ) );
		$this->assertSame( 'publish', get_post_status( $second_id ) );

		// The dry run, which writes nothing, must see the key freed too.
		$this->assertSame( array(), $pending['conflicts'] );
		$this->assertSame( 2, $pending['repathed'] );
	}
	/**
	 * A double-prefixed source created before its prefixed twin still takes the key the twin leaves.
	 *
	 * '/blog/blog/x' re-keys onto '/blog/x', which the 1.x '/blog/x' holds until
	 * the walk reaches it and makes it '/x'. Visited first, the double-prefixed
	 * row waits for that rather than colliding. The pair is split across
	 * batches too, so the wait outlives the request that began it.
	 *
	 * @dataProvider data_waiting_cases
	 *
	 * @param int $between Rows between the two.
	 * @return void
	 */
	public function test_double_prefixed_source_created_first_takes_the_freed_key( int $between ) {
		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		for ( $i = 0; $i < $between; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_prefixed_redirect( '/x' );

		$pending = $this->upgrader->count_pending();

		$totals = $this->run_to_completion();

		$this->assertSame( '/x', get_post( $single_id )->post_title );
		$this->assertSame( '/' . self::SUBDIR . '/x', get_post( $double_id )->post_title );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $double_id )->post_name );
		$this->assertSame( 'publish', get_post_status( $single_id ) );
		$this->assertSame( 'publish', get_post_status( $double_id ) );
		$this->assertSame( array(), $totals['conflicts'] );
		$this->assertSame( $between + 2, $totals['processed'] );
		$this->assertSame( $between + 2, $totals['repathed'] );
		$this->assertSame( 0, $this->waiting_rows() );

		$this->assertSame( array(), $pending['conflicts'] );
		$this->assertSame( $between + 2, $pending['total'] );
		$this->assertSame( $between + 2, $pending['repathed'] );
	}

	/**
	 * Data provider: the pair in one batch and in two.
	 *
	 * @return array<string, array{int}>
	 */
	public static function data_waiting_cases(): array {
		return array(
			'one batch'   => array( 0 ),
			'two batches' => array( Upgrader::BATCH_SIZE ),
		);
	}

	/**
	 * A chain of prefixed sources created newest-prefix first all reach their keys.
	 *
	 * '/blog/blog/blog/x' waits for '/blog/blog/x', which waits for '/blog/x'.
	 *
	 * @return void
	 */
	public function test_chain_of_waiting_sources_all_reach_their_keys() {
		$prefix = '/' . self::SUBDIR;
		$ids    = array(
			$this->create_prefixed_redirect( $prefix . $prefix . '/x' ),
			$this->create_prefixed_redirect( $prefix . '/x' ),
			$this->create_prefixed_redirect( '/x' ),
		);

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( $prefix . $prefix . '/x', get_post( $ids[0] )->post_title );
		$this->assertSame( $prefix . '/x', get_post( $ids[1] )->post_title );
		$this->assertSame( '/x', get_post( $ids[2] )->post_title );
		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( 3, $result['repathed'] );
		$this->assertSame( array(), $pending['conflicts'] );
		$this->assertSame( 3, $pending['repathed'] );
	}

	/**
	 * A row waiting for a redirect deleted before the walk reached it is still migrated.
	 *
	 * @return void
	 */
	public function test_row_waiting_for_a_deleted_redirect_is_migrated_at_the_end() {
		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_prefixed_redirect( '/x' );

		$first = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		wp_delete_post( $single_id, true );
		$rest = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );

		$this->assertSame( Upgrader::BATCH_SIZE - 1, $first['processed'] );
		$this->assertTrue( $rest['complete'] );
		$this->assertSame( 'publish', get_post_status( $double_id ) );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $double_id )->post_name );
	}

	/**
	 * The dry run counts a row waiting for a redirect deleted before it was reached.
	 *
	 * @return void
	 */
	public function test_dry_run_counts_a_row_waiting_for_a_deleted_redirect() {
		$this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_prefixed_redirect( '/x' );

		$pending = $this->upgrader->count_pending(
			static function () use ( $single_id ): void {
				wp_delete_post( $single_id, true );
			}
		);

		$this->assertSame( Upgrader::BATCH_SIZE + 1, $pending['total'] );
		$this->assertSame( Upgrader::BATCH_SIZE + 1, $pending['repathed'] );
		$this->assertSame( array(), $pending['conflicts'] );
	}

	/**
	 * A waiting row collides as before when the redirect it waited for could not move.
	 *
	 * '/blog/x' re-keys onto '/x', which a redirect going elsewhere holds, so it
	 * is disabled on '/blog/x' - and '/blog/blog/x' then collides with it there,
	 * as it does when created in the other order.
	 *
	 * @return void
	 */
	public function test_waiting_row_collides_when_its_holder_cannot_move() {
		$this->create_legacy_redirect( '/x', 'https://example.com/elsewhere' );
		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		$single_id = $this->create_prefixed_redirect( '/x', 'https://example.com/third' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post_status( $single_id ) );
		$this->assertSame( 'draft', get_post_status( $double_id ) );
		$this->assertSame( $single_id, $this->upgrader->duplicates()[ $double_id ]['of'] );
		$this->assertCount( 2, $result['conflicts'] );
		$this->assertSame( $result['conflicts'], $pending['conflicts'] );
	}

	/**
	 * Of two rows waiting for one key, the lower ID takes it, as it would without the wait.
	 *
	 * @return void
	 */
	public function test_first_of_two_rows_waiting_for_one_key_takes_it() {
		$first_id  = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		$second_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x/' );
		$this->create_prefixed_redirect( '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $first_id ) );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $first_id )->post_name );
		$this->assertSame( 'trash', get_post_status( $second_id ) );
		$this->assertSame( 1, $result['deduped'] );
		$this->assertSame( 1, $pending['deduped'] );
	}

	/**
	 * A row already in the trash waits too, and takes the key as it would in the other order.
	 *
	 * @return void
	 */
	public function test_trashed_row_waits_like_any_other() {
		global $wpdb;

		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $double_id ) );
		clean_post_cache( $double_id );
		$this->create_prefixed_redirect( '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'trash', get_post_status( $double_id ) );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $double_id )->post_name );
		$this->assertSame( 2, $result['repathed'] );
		$this->assertSame( 2, $pending['repathed'] );
	}

	/**
	 * A chain waiting across batches on a redirect deleted before the walk reached it still settles.
	 *
	 * '/blog/blog/blog/x' waits for '/blog/blog/x', which waits for '/blog/x', a
	 * batch later. Progress counts neither waiting row as done.
	 *
	 * @return void
	 */
	public function test_chain_waiting_on_a_deleted_redirect_settles_at_the_end() {
		$prefix = '/' . self::SUBDIR;
		$third  = $this->create_prefixed_redirect( $prefix . $prefix . '/x' );
		$second = $this->create_prefixed_redirect( $prefix . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$first = $this->create_prefixed_redirect( '/x' );

		$this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		$this->assertSame( 2, $this->waiting_rows() );
		$this->assertSame( Upgrader::BATCH_SIZE - 2, $this->upgrader->position()['done'] );

		wp_delete_post( $first, true );
		$totals = $this->run_to_completion();

		$this->assertSame( md5( $prefix . '/x' ), get_post( $second )->post_name );
		$this->assertSame( md5( $prefix . $prefix . '/x' ), get_post( $third )->post_name );
		$this->assertSame( 'publish', get_post_status( $third ) );
		$this->assertSame( array(), $totals['conflicts'] );
		$this->assertSame( 0, $this->waiting_rows() );
	}

	/**
	 * A released row stops being recorded as waiting as soon as its holder's batch is done.
	 *
	 * Otherwise a later batch would read it back as still waiting, and plan it
	 * a second time at the end.
	 *
	 * @return void
	 */
	public function test_released_row_is_no_longer_recorded_as_waiting() {
		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$this->create_prefixed_redirect( '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_prefixed_redirect( '/later-' . $i );
		}

		$this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		$this->assertSame( 1, $this->waiting_rows() );

		$second = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		$this->assertFalse( $second['complete'] );
		$this->assertSame( 0, $this->waiting_rows() );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $double_id )->post_name );
	}

	/**
	 * Planning a batch's rows again leaves those it re-keyed as it wrote them.
	 *
	 * The migrated '/blog/x' - the 1.x '/blog/blog/x' - looks exactly like a 1.x
	 * '/blog/x', and re-keyed again would lose its prefix a second time and
	 * collide with '/x'. A batch is planned again when it throws, when the
	 * process dies part-way, and when two batches overlap.
	 *
	 * @dataProvider data_interruptions
	 *
	 * @param string $interruption How the first batch is cut short.
	 * @return void
	 */
	public function test_rows_planned_again_keep_the_keys_they_were_given( string $interruption ) {
		global $wpdb;

		$single_id = $this->create_prefixed_redirect( '/x' );
		$double_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x' );
		$plain_id  = $this->create_legacy_redirect( '/plain' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}

		if ( 'overlap' === $interruption ) {
			$this->upgrader->run_batch( 3 );
			update_option( 'wpcom_legacy_redirector_upgrade_cursor', 0, false );
		} else {
			$stop = static function ( string $query ) use ( $wpdb, $interruption ): string {
				if ( ! str_starts_with( $query, "UPDATE {$wpdb->posts} SET post_status" ) ) {
					return $query;
				}
				if ( 'dies' === $interruption ) {
					throw new \Error( 'The process died.' );
				}
				return '';
			};

			add_filter( 'query', $stop );
			try {
				$this->upgrader->run_batch( 3 );
				$this->fail( 'The batch should have stopped.' );
			} catch ( \RuntimeException | \Error $e ) {
				$this->assertNotSame( 'The batch should have stopped.', $e->getMessage() );
			} finally {
				remove_filter( 'query', $stop );
			}
		}

		$result = $this->upgrader->run_batch( 3 );

		$this->assertSame( md5( '/x' ), get_post( $single_id )->post_name );
		$this->assertSame( md5( '/' . self::SUBDIR . '/x' ), get_post( $double_id )->post_name );
		$this->assertSame( 'publish', get_post_status( $double_id ) );
		$this->assertSame( 'publish', get_post_status( $plain_id ) );
		$this->assertSame( array(), $result['conflicts'] );

		$this->run_to_completion();
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rekeyed' ) );
	}

	/**
	 * Data provider: the ways a batch comes to be planned again.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_interruptions(): array {
		return array(
			'the batch throws'    => array( 'throws' ),
			'the process dies'    => array( 'dies' ),
			'two batches overlap' => array( 'overlap' ),
		);
	}

	/**
	 * How many redirects are recorded as waiting.
	 *
	 * @return int The number of rows.
	 */
	private function waiting_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_legacy_redirector_upgrade_waits_for' ) );
	}

	/**
	 * A key held by a row trashed as a duplicate is free for a later row.
	 *
	 * '/blog/y' re-keys onto '/y', which a redirect with the same destination
	 * already holds, so it goes to the trash on its old key, '/blog/y'. A
	 * lookup ignores the trash, so '/blog/blog/y' then takes '/blog/y'. The dry
	 * run must agree, whether the two land in one batch or in two.
	 *
	 * @dataProvider data_rows_between
	 *
	 * @param int $between Rows between the trashed one and the one taking its key.
	 * @return void
	 */
	public function test_key_of_a_trashed_duplicate_is_free_for_a_later_row( int $between ) {
		$this->create_legacy_redirect( '/y' );
		$trashed_id = $this->create_prefixed_redirect( '/y' );
		for ( $i = 0; $i < $between; $i++ ) {
			$this->create_prefixed_redirect( '/filler-' . $i );
		}
		$taker_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/y' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 1000 );

		$this->assertSame( 'trash', get_post_status( $trashed_id ) );
		$this->assertSame( 'publish', get_post_status( $taker_id ) );
		$this->assertSame( '/' . self::SUBDIR . '/y', get_post( $taker_id )->post_title );
		$this->assertSame( array(), $result['conflicts'] );

		$keys = array( 'changed', 'unchanged', 'published', 'repathed', 'deduped' );
		$this->assertSame( wp_array_slice_assoc( $result, $keys ), wp_array_slice_assoc( $pending, $keys ) );
		$this->assertSame( array(), $pending['conflicts'] );
	}

	/**
	 * Data provider: the pair in one dry-run batch, and in two.
	 *
	 * @return array<string, array{int}>
	 */
	public static function data_rows_between(): array {
		return array(
			'one batch'   => array( 0 ),
			'two batches' => array( Upgrader::BATCH_SIZE ),
		);
	}

	/**
	 * An auto-draft drafted as a duplicate becomes a holder of its key.
	 *
	 * A lookup ignores an auto-draft but sees a draft, so once '/blog/x' is
	 * drafted as a duplicate of '/x', '/blog/blog/x' collides with it on
	 * '/blog/x', as it would row by row.
	 *
	 * @return void
	 */
	public function test_auto_draft_drafted_as_a_duplicate_holds_its_key() {
		global $wpdb;

		$this->create_legacy_redirect( '/x' );
		$drafted_id = $this->create_prefixed_redirect( '/x', 'https://example.com/elsewhere' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'auto-draft' ), array( 'ID' => $drafted_id ) );
		clean_post_cache( $drafted_id );
		$later_id = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x', 'https://example.com/third' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post_status( $drafted_id ) );
		$this->assertSame( 'draft', get_post_status( $later_id ) );
		$this->assertSame( $drafted_id, $this->upgrader->duplicates()[ $later_id ]['of'] );
		$this->assertCount( 2, $result['conflicts'] );
		$this->assertCount( 2, $pending['conflicts'] );
	}

	/**
	 * A 1.x relative destination still sends visitors where 1.x sent them.
	 *
	 * 1.x handed the stored destination to wp_safe_redirect() as it was, so it
	 * was relative to the domain. 2.0 prepends home_url(), so it is relative to
	 * home. Left alone, a 1.x '/blog/new' would send visitors to /blog/blog/new
	 * and a 1.x '/new' to /blog/new.
	 *
	 * @dataProvider data_1x_destinations
	 *
	 * @param string $stored   The destination as 1.x stored it, with {origin} for the scheme and host.
	 * @param string $migrated The destination the migration should leave.
	 * @param string $location Where the redirect should send a visitor.
	 * @return void
	 */
	public function test_1x_destination_keeps_where_1x_sent_visitors( string $stored, string $migrated, string $location ): void {
		$post_id = $this->create_prefixed_redirect( '/old', $this->with_origin( $stored ) );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( $this->with_origin( $migrated ), get_post( $post_id )->post_excerpt );
		$this->assertSame( $result['normalized'], $pending['normalized'], 'The dry run should predict the rewrite.' );
		$this->assertSame( $result['changed'], $pending['changed'], 'The dry run should predict the change.' );
		$this->assertSame( $this->with_origin( $location ), $this->resolver()->get_redirect_data( '/' . self::SUBDIR . '/old' )['url'] );
	}

	/**
	 * Data provider: destinations as 1.x stored them.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> Stored, migrated, location.
	 */
	public static function data_1x_destinations(): array {
		return array(
			'inside home'             => array( '/blog/new', '/new', '{origin}/blog/new' ),
			'home itself'             => array( '/blog', '/', '{origin}/blog/' ),
			'outside home'            => array( '/new', '{origin}/new', '{origin}/new' ),
			'domain root'             => array( '/', '{origin}/', '{origin}/' ),
			'shares home prefix only' => array( '/blogging', '{origin}/blogging', '{origin}/blogging' ),
			'double prefix'           => array( '/blog/blog/x', '/blog/x', '{origin}/blog/blog/x' ),
			'query inside home'       => array( '/blog/new?a=1&b=2#top', '/new?a=1&b=2#top', '{origin}/blog/new?a=1&b=2#top' ),
			'query outside home'      => array( '/new?a=1#top', '{origin}/new?a=1#top', '{origin}/new?a=1#top' ),
			'query on the root'       => array( '/?a=1', '{origin}/?a=1', '{origin}/?a=1' ),
			'escaped inside home'     => array( '/blog/new?a=1&amp;b=2', '/new?a=1&b=2', '{origin}/blog/new?a=1&b=2' ),
			'escaped outside home'    => array( '/new?a=1&amp;b=2', '{origin}/new?a=1&b=2', '{origin}/new?a=1&b=2' ),
			'encoded outside home'    => array( '/caf%C3%A9', '{origin}/café', '{origin}/café' ),
			'absolute inside home'    => array( '{origin}/blog/new', '/new', '{origin}/blog/new' ),
			'absolute outside home'   => array( '{origin}/new', '{origin}/new', '{origin}/new' ),
			'another host'            => array( 'https://elsewhere.example/blog/new', 'https://elsewhere.example/blog/new', 'https://elsewhere.example/blog/new' ),
		);
	}

	/**
	 * A scheme-relative destination names another host, so it is not rebased.
	 *
	 * @return void
	 */
	public function test_scheme_relative_destination_is_not_rebased(): void {
		$post_id = $this->create_prefixed_redirect( '/old', '//elsewhere.example/new' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '//elsewhere.example/new', get_post( $post_id )->post_excerpt );
	}

	/**
	 * Rebasing goes by the home URL's own host and port, and a unicode home path in either encoding.
	 *
	 * @dataProvider data_unicode_home_destinations
	 *
	 * @param string $stored   The destination as 1.x stored it.
	 * @param string $migrated The destination the migration should leave.
	 * @return void
	 */
	public function test_1x_destination_under_a_unicode_home_with_a_port( string $stored, string $migrated ): void {
		$post_id = $this->create_legacy_redirect( '/old', $stored );
		$home    = static fn(): string => 'https://example.com:8443/日本';

		add_filter( 'home_url', $home, 20 );
		try {
			$this->upgrader->run_batch( 100 );
		} finally {
			remove_filter( 'home_url', $home, 20 );
		}

		$this->assertSame( $migrated, get_post( $post_id )->post_excerpt );
	}

	/**
	 * Data provider: destinations on a site at https://example.com:8443/日本.
	 *
	 * @return array<string, array{0: string, 1: string}> Stored, migrated.
	 */
	public static function data_unicode_home_destinations(): array {
		return array(
			'raw, inside home'     => array( '/日本/new', '/new' ),
			'encoded, inside home' => array( '/%E6%97%A5%E6%9C%AC/new', '/new' ),
			'outside home'         => array( '/new', 'https://example.com:8443/new' ),
			'absolute, same port'  => array( 'https://example.com:8443/日本/new', '/new' ),
			'absolute, other port' => array( 'https://example.com/日本/new', 'https://example.com/日本/new' ),
		);
	}

	/**
	 * A batch that stopped part-way is planned again without rewriting what it wrote.
	 *
	 * The cursor only moves once a batch is done, so the rerun plans rows it has
	 * already written, and a migrated '/new' looks exactly like a 1.x '/new'.
	 * The bulk publish runs last, so refusing it stops the batch after the rows
	 * with a destination to rewrite have landed.
	 *
	 * @return void
	 */
	public function test_batch_replanned_after_stopping_keeps_what_it_wrote(): void {
		global $wpdb;

		$rows = array(
			$this->create_prefixed_redirect( '/inside', '/blog/new' ),
			$this->create_prefixed_redirect( '/double', '/blog/blog/x' ),
			$this->create_prefixed_redirect( '/outside', '/new' ),
			$this->create_prefixed_redirect( '/absolute', $this->origin() . '/blog/abs' ),
		);
		$this->create_legacy_redirect( '/publish-only' );

		$expected = array( '/new', '/blog/x', $this->origin() . '/new', '/abs' );
		$stored   = static fn(): array => array_map( static fn( int $id ): string => get_post( $id )->post_excerpt, $rows );

		$this->refusal_stops( "UPDATE {$wpdb->posts} SET post_status", fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertSame( $expected, $stored(), 'The stopped batch should have written its rows.' );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_cursor' ), 'The cursor should not have moved.' );
		$this->assertSame( 0, $this->upgrader->count_pending()['normalized'], 'A dry run should not count them again.' );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( $expected, $stored(), 'The rerun should leave them alone.' );
		$this->assertFalse( $this->upgrader->needs_upgrade() );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rebased' ) );
	}

	/**
	 * A refused cursor write keeps the record, as the batch is planned again.
	 *
	 * @return void
	 */
	public function test_refused_cursor_write_keeps_the_rebased_record(): void {
		$post_id = $this->create_prefixed_redirect( '/inside', '/blog/new' );
		$this->create_prefixed_redirect( '/later' );

		$refuse = static fn( string $query ): string => str_contains( $query, 'upgrade_cursor' ) && ! str_starts_with( $query, 'SELECT' ) ? '' : $query;

		add_filter( 'query', $refuse );
		try {
			$this->upgrader->run_batch( 1 );
		} finally {
			remove_filter( 'query', $refuse );
		}

		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_cursor' ) );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( '/new', get_post( $post_id )->post_excerpt );
	}

	/**
	 * The record of rebased rows does not grow with the walk.
	 *
	 * Most rows on such a site can have a relative destination, so rewriting a
	 * growing option every batch would be quadratic. An entry is only needed
	 * until the cursor passes its row.
	 *
	 * @return void
	 */
	public function test_rebased_record_is_cleared_as_the_cursor_moves(): void {
		$first = $this->create_prefixed_redirect( '/a', '/blog/to-a' );
		$this->create_prefixed_redirect( '/b', '/blog/to-b' );
		$last = $this->create_prefixed_redirect( '/c', '/blog/to-c' );

		$saved = array();
		$spy   = static function ( string $option, mixed $value ) use ( &$saved ): void {
			if ( 'wpcom_legacy_redirector_upgrade_rebased' === $option ) {
				$saved[] = count( $value );
			}
		};

		add_action( 'added_option', $spy, 10, 2 );
		for ( $batch = 0; $batch < 3; $batch++ ) {
			$this->upgrader->run_batch( 1 );
			$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rebased' ), 'A finished batch should leave no record.' );
		}
		remove_action( 'added_option', $spy, 10 );

		$this->assertSame( array( 1, 1, 1 ), $saved, 'Each batch should record only its own rows.' );
		$this->assertSame( '/to-a', get_post( $first )->post_excerpt );
		$this->assertSame( '/to-c', get_post( $last )->post_excerpt );
	}

	/**
	 * A failed write leaves the row in its 1.x form, so a retry still rebases it.
	 *
	 * The record is saved before the write, so it names a destination the row
	 * never came to hold, and the retry comes once the walk has finished.
	 *
	 * @return void
	 */
	public function test_retry_rebases_a_destination_whose_write_failed(): void {
		$inside  = $this->create_prefixed_redirect( '/inside', '/blog/new' );
		$outside = $this->create_prefixed_redirect( '/outside', '/new' );

		$this->with_row_writes_refused( fn() => $this->upgrader->run_batch( 100 ) );

		$this->assertSame( '/blog/new', get_post( $inside )->post_excerpt );
		$this->assertSame( 2, $this->upgrader->pending_retries() );

		$this->upgrader->retry_failed();

		$this->assertSame( '/new', get_post( $inside )->post_excerpt );
		$this->assertSame( $this->origin() . '/new', get_post( $outside )->post_excerpt );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rebased' ) );
	}

	/**
	 * A retry once the walk has finished takes every row outside its set as migrated.
	 *
	 * The cursor has gone by then, but the live row is no less migrated.
	 *
	 * @return void
	 */
	public function test_retry_after_the_walk_compares_a_duplicate_with_the_migrated_row(): void {
		$this->create_prefixed_redirect( '/clash', '/blog/new' );
		$this->upgrader->run_batch( 100 );

		$failed = $this->create_prefixed_redirect( '/clash/', '/blog/new' );
		update_option(
			'wpcom_legacy_redirector_upgrade_retry',
			array(
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) => array(
					'publish' => true,
					'ids'     => array( $failed ),
				),
			),
			false
		);

		$this->upgrader->retry_failed();

		$this->assertSame( 'trash', get_post_status( $failed ) );
	}

	/**
	 * A row a stopped retry wrote keeps its record until the retry has run again.
	 *
	 * It stays in the retry set, so it is planned again, even when the walk has
	 * moved its cursor in between. The publish-only row is put in the set by
	 * hand, as refusing its bulk publish is what stops the retry part-way.
	 *
	 * @return void
	 */
	public function test_retry_replanned_after_stopping_keeps_what_it_wrote(): void {
		global $wpdb;

		$written      = $this->create_prefixed_redirect( '/written', '/blog/new' );
		$publish_only = $this->create_legacy_redirect( '/publish-only' );
		$this->create_prefixed_redirect( '/later' );
		$this->create_prefixed_redirect( '/last' );

		$this->with_row_writes_refused( fn() => $this->upgrader->run_batch( 2 ) );

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $publish_only ) );
		clean_post_cache( $publish_only );

		$sets = get_option( 'wpcom_legacy_redirector_upgrade_retry' );
		foreach ( array_keys( $sets ) as $started ) {
			$sets[ $started ]['ids'][] = $publish_only;
		}
		update_option( 'wpcom_legacy_redirector_upgrade_retry', $sets, false );

		$this->refusal_stops( "UPDATE {$wpdb->posts} SET post_status", fn() => $this->upgrader->retry_failed() );
		$this->assertSame( '/new', get_post( $written )->post_excerpt );

		$this->upgrader->run_batch( 1 );
		$this->upgrader->retry_failed();

		$this->assertSame( '/new', get_post( $written )->post_excerpt );
	}

	/**
	 * Two rows converging on one source are compared by where each really sends visitors.
	 *
	 * With a batch of one the earlier row has been migrated and its record
	 * cleared by the time the later one finds it, so the same stored text can
	 * mean something different on each.
	 *
	 * @dataProvider data_duplicate_pairs
	 *
	 * @param string $first      The destination of the row that keeps the key.
	 * @param string $second     The destination of the row that collides.
	 * @param string $status     The status the later row should end up with.
	 * @param int    $batch_size The batch size.
	 * @return void
	 */
	public function test_duplicate_pair_compares_where_each_row_lands( string $first, string $second, string $status, int $batch_size ): void {
		$live  = $this->create_prefixed_redirect( '/clash', $this->with_origin( $first ) );
		$later = $this->create_prefixed_redirect( '/clash/', $this->with_origin( $second ) );

		$pending = $this->upgrader->count_pending( null, $batch_size );
		$result  = $this->run_to_completion( $batch_size );

		$this->assertSame( 'publish', get_post_status( $live ) );
		$this->assertSame( $status, get_post_status( $later ) );
		$this->assertSame( (int) ( 'trash' === $status ), $result['deduped'] );
		$this->assertCount( (int) ( 'draft' === $status ), $result['conflicts'] );
		$this->assertSame( $result['deduped'], $pending['deduped'], 'The dry run should agree about a duplicate.' );
		$this->assertSame( $result['conflicts'], $pending['conflicts'], 'The dry run should report the same conflict.' );
	}

	/**
	 * Data provider: pairs of destinations on rows converging on one source.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: int}> First, second, later row's status, batch size.
	 */
	public static function data_duplicate_pairs(): array {
		$pairs = array(
			'same, inside home'           => array( '/blog/new', '/blog/new', 'trash' ),
			'same, outside home'          => array( '/new', '/new', 'trash' ),
			'same, relative and absolute' => array( '/blog/new', '{origin}/blog/new', 'trash' ),
			'same, absolute and relative' => array( '{origin}/new', '/new', 'trash' ),
			'different, one looks home'   => array( '/blog/new', '/new', 'draft' ),
			'different, inside home'      => array( '/blog/a', '/blog/b', 'draft' ),
		);

		$cases = array();
		foreach ( $pairs as $name => $pair ) {
			$cases[ $name . ', one batch' ]   = array( ...$pair, 100 );
			$cases[ $name . ', two batches' ] = array( ...$pair, 1 );
		}

		return $cases;
	}

	/**
	 * A row a user edited since the upgrade began already holds a 2.0 destination.
	 *
	 * So a 1.x row converging on its source compares against it as it stands.
	 *
	 * @return void
	 */
	public function test_duplicate_of_an_edited_row_compares_its_destination_as_it_stands(): void {
		global $wpdb;

		$later  = $this->create_prefixed_redirect( '/clash/', '/blog/new' );
		$edited = $this->insert_redirect_post(
			array(
				'post_title'   => '/clash',
				'post_name'    => md5( '/clash' ),
				'post_excerpt' => '/new',
			)
		);

		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ), array( 'ID' => $edited ) );
		clean_post_cache( $edited );

		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/new', get_post( $edited )->post_excerpt );
		$this->assertSame( 'trash', get_post_status( $later ) );
	}

	/**
	 * A dry run after a partly finished walk does not take migrated rows for 1.x ones.
	 *
	 * It walks from ID 0, over rows the walk has already passed.
	 *
	 * @return void
	 */
	public function test_dry_run_after_a_partly_finished_walk(): void {
		$this->create_prefixed_redirect( '/a', '/blog/a' );
		$this->create_prefixed_redirect( '/b', '/b' );
		$this->create_prefixed_redirect( '/c', '/blog/c' );
		$this->create_prefixed_redirect( '/d', '/d' );

		$this->upgrader->run_batch( 2 );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 2, $result['normalized'] );
		$this->assertSame( 2, $pending['normalized'], 'The rows already passed should not be counted.' );
		$this->assertSame( 2, $pending['unchanged'], 'The rows already passed should count as done.' );
	}

	/**
	 * A row waiting for a holder has not been migrated, though the cursor has passed it.
	 *
	 * @return void
	 */
	public function test_waiting_row_is_rebased_when_a_later_batch_releases_it(): void {
		$waiting = $this->create_prefixed_redirect( '/' . self::SUBDIR . '/x', '/blog/new' );
		$this->create_prefixed_redirect( '/x', 'https://elsewhere.example/x' );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( '/blog/new', get_post( $waiting )->post_excerpt, 'A waiting row is not written.' );
		$this->assertSame( 1, $this->upgrader->count_pending()['normalized'], 'A dry run should still count it.' );

		$this->upgrader->run_batch( 1 );

		$this->assertSame( '/new', get_post( $waiting )->post_excerpt );
	}

	/**
	 * A walk over 2.0 data leaves relative destinations alone: they already mean home.
	 *
	 * @return void
	 */
	public function test_walk_over_2_0_data_does_not_rebase_destinations(): void {
		$inside  = $this->create_prefixed_redirect( '/inside', '/blog/new' );
		$outside = $this->create_prefixed_redirect( '/outside', '/new' );

		update_option( Upgrader::VERSION_OPTION, Upgrader::DB_VERSION - 1 );
		$this->upgrader->run_batch( 100 );

		$this->assertSame( '/blog/new', get_post( $inside )->post_excerpt );
		$this->assertSame( '/new', get_post( $outside )->post_excerpt );
		$this->assertFalse( get_option( 'wpcom_legacy_redirector_upgrade_rebased' ) );
	}

	/**
	 * The loop detector and the audit read migrated destinations correctly.
	 *
	 * A 1.x loop stays a loop, and an absolute destination outside home is
	 * neither a hop on this site nor a host WordPress would refuse.
	 *
	 * @return void
	 */
	public function test_audit_reads_migrated_destinations(): void {
		$looping = $this->create_prefixed_redirect( '/loop-a', '/blog/loop-b' );
		$this->create_prefixed_redirect( '/loop-b', '/blog/loop-a' );
		$outside = $this->create_prefixed_redirect( '/outside', '/loop-a' );

		$this->upgrader->run_batch( 100 );

		$detector = new LoopDetector( $this->repository() );
		$redirect = $this->repository()->find_by_id( $outside );

		$this->assertSame( array( '/loop-a', '/loop-b', '/loop-a' ), $detector->find_cycle( $this->repository()->find_by_id( $looping ) ), 'The 1.x loop should still be found.' );
		$this->assertSame( array(), $detector->find_cycle( $redirect ), 'Outside home is no hop on this site.' );
		$this->assertSame( array(), $this->auditor()->destination_checks( $redirect->destination() ), 'WordPress should accept the site\'s own host.' );
	}

	/**
	 * The scheme, host and port of the site, which a destination outside home is written with.
	 *
	 * @return string The home option, which the home_url filter does not touch.
	 */
	private function origin(): string {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	/**
	 * Fill in the origin in a destination from a data provider, which runs too early to know it.
	 *
	 * @param string $destination The destination, with {origin} for the scheme and host.
	 * @return string The destination.
	 */
	private function with_origin( string $destination ): string {
		return str_replace( '{origin}', $this->origin(), $destination );
	}
}
