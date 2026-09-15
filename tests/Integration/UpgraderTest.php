<?php
/**
 * Data upgrade integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * UpgraderTest class.
 *
 * Covers the migration of redirect data created by version 1.x.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
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
	 * A 1.x redirect is recognised by never having been published.
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
	public function test_internal_absolute_destination_is_normalised() {
		$post_id = $this->create_legacy_redirect( '/old-page', home_url( '/new-page?a=1' ) );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 1, $result['normalised'] );
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
	public function test_double_slash_destination_is_not_normalised() {
		// Built by concatenation: home_url( '//foo' ) would collapse the
		// double slash this test exists to preserve.
		$destination = untrailingslashit( home_url() ) . '//foo';
		$post_id     = $this->create_legacy_redirect( '/old-page', $destination );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['normalised'] );
		$this->assertSame( $destination, get_post( $post_id )->post_excerpt );
	}

	/**
	 * An external destination is left exactly as stored.
	 *
	 * @return void
	 */
	public function test_external_destination_is_not_normalised() {
		$post_id = $this->create_legacy_redirect( '/old-page', 'https://external.example.net/x' );

		$result = $this->upgrader->run_batch( 100 );

		$this->assertSame( 0, $result['normalised'] );
		$this->assertSame( 'https://external.example.net/x', get_post( $post_id )->post_excerpt );
	}

	/**
	 * A dry run reports destinations due to be made relative.
	 *
	 * @return void
	 */
	public function test_count_pending_reports_normalisation() {
		$this->create_legacy_redirect( '/old-page', home_url( '/new-page' ) );

		$pending = $this->upgrader->count_pending();

		$this->assertSame( 1, $pending['to_normalise'] );
	}
}
