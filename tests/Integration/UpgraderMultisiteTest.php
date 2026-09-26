<?php
/**
 * Data upgrade integration tests for subdirectory multisite.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderMultisiteTest class.
 *
 * Version 1.x prefixed home_url() onto every source path before saving, so on
 * a subdirectory multisite it stored '/subsite1/old-page'. Version 2.0 strips
 * the subsite prefix from incoming requests and looks up '/old-page', so those
 * redirects never match until they have been rewritten.
 *
 * @group multisite
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class UpgraderMultisiteTest extends TestCase {

	/**
	 * The subsite ID.
	 *
	 * @var int
	 */
	private int $site_id;

	/**
	 * The subsite's path segment, without slashes.
	 *
	 * Unique per test: the shared Integration TestCase does not call
	 * parent::set_up(), so WP_UnitTestCase never opens its rollback
	 * transaction and created sites persist for the whole run.
	 *
	 * @var string
	 */
	private string $subsite;

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

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required' );
		}

		static $counter = 0;
		++$counter;

		$this->subsite  = 'subsite' . $counter . substr( md5( (string) microtime( true ) ), 0, 6 );
		$this->site_id  = (int) self::factory()->blog->create( array( 'path' => '/' . $this->subsite . '/' ) );
		$this->upgrader = new Upgrader();

		switch_to_blog( $this->site_id );

		delete_option( Upgrader::VERSION_OPTION );
		delete_option( 'wpcom_legacy_redirector_upgrade_started_gmt' );
		delete_option( 'wpcom_legacy_redirector_upgrade_cursor' );
	}

	/**
	 * Tears down test fixtures.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( is_multisite() ) {
			restore_current_blog();
		}

		parent::tear_down();
	}

	/**
	 * Create a redirect exactly as 1.x stored it on a subdirectory subsite.
	 *
	 * @param string $path_within_subsite The path below the subsite, e.g. '/old-page'.
	 * @return int The created post ID.
	 */
	private function create_legacy_redirect( string $path_within_subsite ): int {
		$network_absolute_path = '/' . $this->subsite . $path_within_subsite;

		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $network_absolute_path ),
				'post_title'   => $network_absolute_path,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * Create a redirect already stored in the subsite-relative 2.0 form.
	 *
	 * @param string $path The subsite-relative path.
	 * @return int The created post ID.
	 */
	private function create_relative_redirect( string $path ): int {
		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $path ),
				'post_title'   => $path,
				'post_excerpt' => 'https://example.com/new',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}

	/**
	 * The subsite prefix is stripped and the source is rehashed.
	 *
	 * @return void
	 */
	public function test_subsite_prefix_is_stripped_from_legacy_source() {
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$post = get_post( $post_id );

		$this->assertSame( '/old-page', $post->post_title, 'The subsite prefix should have been stripped.' );
		$this->assertSame( md5( '/old-page' ), $post->post_name, 'The source hash should match the rewritten path.' );
		$this->assertSame( 'publish', $post->post_status, 'The redirect should also have been published.' );
		$this->assertSame( 1, $result['repathed'] );
		$this->assertSame( 1, $result['published'] );
	}

	/**
	 * After migrating, the redirect is found by the path a visitor requests.
	 *
	 * This is the assertion that would have caught the regression: publishing
	 * alone leaves the redirect stored under a key no request ever produces.
	 *
	 * @return void
	 */
	public function test_migrated_redirect_is_reachable_by_lookup() {
		$this->create_legacy_redirect( '/old-page' );

		$this->upgrader->run_batch( 100 );

		$redirect_data = $this->resolver()->get_redirect_data( '/old-page' );

		$this->assertNotEmpty( $redirect_data, 'The migrated redirect should resolve for a subsite-relative request.' );
		$this->assertSame( 'https://example.com/new', $redirect_data['url'] );
	}

	/**
	 * A path that already omits the prefix is left untouched.
	 *
	 * @return void
	 */
	public function test_already_relative_source_is_not_rewritten() {
		$post_id = $this->create_relative_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( '/old-page', get_post( $post_id )->post_title );
		$this->assertSame( 0, $result['repathed'] );
	}

	/**
	 * A prefixed source written under 2.0 is never rewritten by a re-walk.
	 *
	 * On a subsite at /subsite1, a stored '/subsite1/old-page' created under
	 * 2.0 is indistinguishable from a deliberate redirect for the real URL
	 * /subsite1/subsite1/old-page, so the repath pass must leave it alone once
	 * the site's data is past the 1.x boundary.
	 *
	 * @return void
	 */
	public function test_prefixed_source_written_under_2_0_is_not_rewritten() {
		update_option( Upgrader::VERSION_OPTION, 2 );
		$post_id = $this->create_legacy_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame(
			'/' . $this->subsite . '/old-page',
			get_post( $post_id )->post_title,
			'A prefixed source under 2.0 may be a deliberate double-prefix redirect and must not be touched.'
		);
		$this->assertSame( 0, $result['repathed'] );
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

		$post_id = $this->create_relative_redirect( '/old-page' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post( $post_id )->post_status, 'A disabled redirect should stay disabled.' );
		$this->assertSame( 0, $result['published'] );
	}

	/**
	 * A rewrite that collides with an identical redirect is trashed.
	 *
	 * Both rows send visitors to the same place, so the prefixed one is pure
	 * redundancy once it has been made subsite-relative. It cannot simply be
	 * left as it was: lookups are keyed on the relative form, so a row keeping
	 * the prefix would never fire again while still appearing to be live.
	 *
	 * @return void
	 */
	public function test_colliding_rewrite_of_the_same_destination_is_trashed() {
		$prefixed_id = $this->create_legacy_redirect( '/old-page' );
		$kept_id     = $this->create_relative_redirect( '/old-page' );

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
		$prefixed_id = $this->create_legacy_redirect( '/old-page' );

		$kept_id = (int) wp_insert_post(
			array(
				'post_name'    => md5( '/old-page' ),
				'post_title'   => '/old-page',
				'post_excerpt' => 'https://example.com/somewhere-else',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['deduped'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertStringContainsString( 'has the same source as', $result['conflicts'][0] );

		$this->assertSame( 'draft', get_post( $prefixed_id )->post_status );
		$this->assertSame( 'publish', get_post( $kept_id )->post_status );

		// The loser keeps its own slug rather than contending for the winner's.
		$this->assertSame( md5( '/' . $this->subsite . '/old-page' ), get_post( $prefixed_id )->post_name );
	}

	/**
	 * A disabled duplicate stored without the subsite path is marked as never having fired.
	 *
	 * On a subsite, every 1.x request carried the subsite path, so '/old-page/'
	 * stored without it never matched anything. Disabling it changed nothing
	 * for visitors, and the mark says so.
	 *
	 * @return void
	 */
	public function test_unprefixed_duplicate_is_marked_as_never_fired() {
		$prefixed_id = $this->create_legacy_redirect( '/old-page' );

		$unprefixed_id = (int) wp_insert_post(
			array(
				'post_name'    => md5( '/old-page/' ),
				'post_title'   => '/old-page/',
				'post_excerpt' => 'https://example.com/somewhere-else',
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);

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
	 * On a subsite, the 1.x '/sub/x' becomes '/x', and the 1.x '/sub/sub/x'
	 * becomes '/sub/x' - the key the first row held when the batch began. A
	 * batch planned before any of it is written must know that key is free.
	 *
	 * @return void
	 */
	public function test_row_takes_a_key_freed_earlier_in_the_batch() {
		$first_id  = $this->create_legacy_redirect( '/x' );
		$second_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( array(), $result['conflicts'] );
		$this->assertSame( '/x', get_post( $first_id )->post_title );
		$this->assertSame( '/' . $this->subsite . '/x', get_post( $second_id )->post_title );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $second_id )->post_name );
		$this->assertSame( 'publish', get_post_status( $first_id ) );
		$this->assertSame( 'publish', get_post_status( $second_id ) );

		// The dry run, which writes nothing, must see the key freed too.
		$this->assertSame( array(), $pending['conflicts'] );
		$this->assertSame( 2, $pending['repathed'] );
	}
	/**
	 * A double-prefixed source created before its prefixed twin still takes the key the twin leaves.
	 *
	 * '/sub/sub/x' re-keys onto '/sub/x', which the 1.x '/sub/x' holds until
	 * the walk reaches it and makes it '/x'. Visited first, the double-prefixed
	 * row waits for that rather than colliding. The pair is split across
	 * batches too, so the wait outlives the request that began it.
	 * Row by row, as when the key map cannot be read, it waits just the same.
	 *
	 * @dataProvider data_waiting_cases
	 *
	 * @param int  $between Rows between the two.
	 * @param bool $by_row  Whether the run has to go row by row.
	 * @return void
	 */
	public function test_double_prefixed_source_created_first_takes_the_freed_key( int $between, bool $by_row ) {
		global $wpdb;

		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		for ( $i = 0; $i < $between; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_legacy_redirect( '/x' );

		$pending = $this->upgrader->count_pending();

		if ( $by_row ) {
			add_filter( 'query', static fn( string $query ): string => str_starts_with( $query, "SELECT ID, post_name, post_date FROM {$wpdb->posts}" ) ? '' : $query );
		}

		$totals = $this->run_to_completion();

		$this->assertSame( '/x', get_post( $single_id )->post_title );
		$this->assertSame( '/' . $this->subsite . '/x', get_post( $double_id )->post_title );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $double_id )->post_name );
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
	 * Data provider: the pair in one batch and in two, planned in bulk and row by row.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public static function data_waiting_cases(): array {
		return array(
			'one batch'               => array( 0, false ),
			'two batches'             => array( Upgrader::BATCH_SIZE, false ),
			'row by row'              => array( 0, true ),
			'row by row, two batches' => array( Upgrader::BATCH_SIZE, true ),
		);
	}

	/**
	 * A chain of prefixed sources created newest-prefix first all reach their keys.
	 *
	 * '/sub/sub/sub/x' waits for '/sub/sub/x', which waits for '/sub/x'.
	 *
	 * @return void
	 */
	public function test_chain_of_waiting_sources_all_reach_their_keys() {
		$prefix = '/' . $this->subsite;
		$ids    = array(
			$this->create_legacy_redirect( $prefix . $prefix . '/x' ),
			$this->create_legacy_redirect( $prefix . '/x' ),
			$this->create_legacy_redirect( '/x' ),
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
		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_legacy_redirect( '/x' );

		$first = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		wp_delete_post( $single_id, true );
		$rest = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );

		$this->assertSame( Upgrader::BATCH_SIZE - 1, $first['processed'] );
		$this->assertTrue( $rest['complete'] );
		$this->assertSame( 'publish', get_post_status( $double_id ) );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $double_id )->post_name );
	}

	/**
	 * The dry run counts a row waiting for a redirect deleted before it was reached.
	 *
	 * @return void
	 */
	public function test_dry_run_counts_a_row_waiting_for_a_deleted_redirect() {
		$this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$single_id = $this->create_legacy_redirect( '/x' );

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
	 * '/sub/x' re-keys onto '/x', which a redirect going elsewhere holds, so it
	 * is disabled on '/sub/x' - and '/sub/sub/x' then collides with it there,
	 * as it does when created in the other order.
	 *
	 * @return void
	 */
	public function test_waiting_row_collides_when_its_holder_cannot_move() {
		$live_id = $this->create_relative_redirect( '/x' );
		wp_update_post(
			array(
				'ID'           => $live_id,
				'post_excerpt' => 'https://example.com/elsewhere',
			)
		);
		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		$single_id = $this->create_legacy_redirect( '/x' );
		wp_update_post(
			array(
				'ID'           => $single_id,
				'post_excerpt' => 'https://example.com/third',
			)
		);
		update_option( 'wpcom_legacy_redirector_upgrade_started_gmt', '2100-01-01 00:00:00' );

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
		$first_id  = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		$second_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x/' );
		$this->create_legacy_redirect( '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'publish', get_post_status( $first_id ) );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $first_id )->post_name );
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

		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $double_id ) );
		clean_post_cache( $double_id );
		$this->create_legacy_redirect( '/x' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'trash', get_post_status( $double_id ) );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $double_id )->post_name );
		$this->assertSame( 2, $result['repathed'] );
		$this->assertSame( 2, $pending['repathed'] );
	}

	/**
	 * A chain waiting across batches on a redirect deleted before the walk reached it still settles.
	 *
	 * '/sub/sub/sub/x' waits for '/sub/sub/x', which waits for '/sub/x', a
	 * batch later. Progress counts neither waiting row as done.
	 *
	 * @return void
	 */
	public function test_chain_waiting_on_a_deleted_redirect_settles_at_the_end() {
		$prefix = '/' . $this->subsite;
		$third  = $this->create_legacy_redirect( $prefix . $prefix . '/x' );
		$second = $this->create_legacy_redirect( $prefix . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$first = $this->create_legacy_redirect( '/x' );

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
		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$this->create_legacy_redirect( '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/later-' . $i );
		}

		$this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		$this->assertSame( 1, $this->waiting_rows() );

		$second = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );
		$this->assertFalse( $second['complete'] );
		$this->assertSame( 0, $this->waiting_rows() );
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $double_id )->post_name );
	}

	/**
	 * Planning a batch's rows again leaves those it re-keyed as it wrote them.
	 *
	 * The migrated '/sub/x' - the 1.x '/sub/sub/x' - looks exactly like a 1.x
	 * '/sub/x', and re-keyed again would lose its prefix a second time and
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

		$single_id = $this->create_legacy_redirect( '/x' );
		$double_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		$plain_id  = $this->create_relative_redirect( '/plain' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}

		if ( 'overlap' === $interruption ) {
			$this->upgrader->run_batch( 3 );
			update_option( 'wpcom_legacy_redirector_upgrade_cursor', 0, false );
		} else {
			$stop = static function ( string $query ) use ( $wpdb, $interruption ): string {
				if ( 'row by row' === $interruption && str_starts_with( $query, "SELECT ID, post_name, post_date FROM {$wpdb->posts}" ) ) {
					return '';
				}
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
		$this->assertSame( md5( '/' . $this->subsite . '/x' ), get_post( $double_id )->post_name );
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
			'the batch throws'      => array( 'throws' ),
			'row by row, it throws' => array( 'row by row' ),
			'the process dies'      => array( 'dies' ),
			'two batches overlap'   => array( 'overlap' ),
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
	 * Run batches of the web size until the upgrade completes, adding up their totals.
	 *
	 * @return array<string, mixed> The totals.
	 */
	private function run_to_completion(): array {
		$totals = array(
			'processed' => 0,
			'repathed'  => 0,
			'conflicts' => array(),
		);

		do {
			$batch = $this->upgrader->run_batch( Upgrader::BATCH_SIZE );

			$totals['processed'] += $batch['processed'];
			$totals['repathed']  += $batch['repathed'];
			$totals['conflicts']  = array_merge( $totals['conflicts'], $batch['conflicts'] );
		} while ( ! $batch['complete'] );

		return $totals;
	}

	/**
	 * The dry run sees a key freed by a row in an earlier batch.
	 *
	 * The run has written that move by then; the dry run, which writes
	 * nothing, has to remember it across batches.
	 *
	 * @return void
	 */
	public function test_dry_run_sees_a_key_freed_in_an_earlier_batch() {
		$this->create_legacy_redirect( '/x' );
		for ( $i = 0; $i < Upgrader::BATCH_SIZE; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$this->create_legacy_redirect( '/' . $this->subsite . '/x' );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( array(), $pending['conflicts'] );
		// Every row here is re-keyed off its subsite prefix, the fillers too.
		$this->assertSame( Upgrader::BATCH_SIZE + 2, $pending['repathed'] );
	}
	/**
	 * A key held by a row trashed as a duplicate is free for a later row.
	 *
	 * '/sub/y' re-keys onto '/y', which a redirect with the same destination
	 * already holds, so it goes to the trash on its old key, '/sub/y'. A
	 * lookup ignores the trash, so '/sub/sub/y' then takes '/sub/y'. The dry
	 * run must agree, whether the two land in one batch or in two.
	 *
	 * @dataProvider data_rows_between
	 *
	 * @param int $between Rows between the trashed one and the one taking its key.
	 * @return void
	 */
	public function test_key_of_a_trashed_duplicate_is_free_for_a_later_row( int $between ) {
		$this->create_relative_redirect( '/y' );
		$trashed_id = $this->create_legacy_redirect( '/y' );
		for ( $i = 0; $i < $between; $i++ ) {
			$this->create_legacy_redirect( '/filler-' . $i );
		}
		$taker_id = $this->create_legacy_redirect( '/' . $this->subsite . '/y' );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 1000 );

		$this->assertSame( 'trash', get_post_status( $trashed_id ) );
		$this->assertSame( 'publish', get_post_status( $taker_id ) );
		$this->assertSame( '/' . $this->subsite . '/y', get_post( $taker_id )->post_title );
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
	 * A lookup ignores an auto-draft but sees a draft, so once '/sub/x' is
	 * drafted as a duplicate of '/x', '/sub/sub/x' collides with it on
	 * '/sub/x', as it would row by row.
	 *
	 * @return void
	 */
	public function test_auto_draft_drafted_as_a_duplicate_holds_its_key() {
		global $wpdb;

		$this->create_relative_redirect( '/x' );
		$drafted_id = $this->create_legacy_redirect( '/x' );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_status'  => 'auto-draft',
				'post_excerpt' => 'https://example.com/elsewhere',
			),
			array( 'ID' => $drafted_id )
		);
		$later_id = $this->create_legacy_redirect( '/' . $this->subsite . '/x' );
		$wpdb->update( $wpdb->posts, array( 'post_excerpt' => 'https://example.com/third' ), array( 'ID' => $later_id ) );
		clean_post_cache( $drafted_id );
		clean_post_cache( $later_id );

		$pending = $this->upgrader->count_pending();
		$result  = $this->upgrader->run_batch( 100 );

		$this->assertSame( 'draft', get_post_status( $drafted_id ) );
		$this->assertSame( 'draft', get_post_status( $later_id ) );
		$this->assertSame( $drafted_id, $this->upgrader->duplicates()[ $later_id ]['of'] );
		$this->assertCount( 2, $result['conflicts'] );
		$this->assertCount( 2, $pending['conflicts'] );
	}
}
