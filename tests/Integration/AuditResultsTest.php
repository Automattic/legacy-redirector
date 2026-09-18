<?php
/**
 * Stored audit results, scheduler, and menu badge integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\ValidatePage;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditScheduler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Integration tests for the recorded audit summary and its consumers.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditScheduler
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 */
final class AuditResultsTest extends TestCase {

	/**
	 * Clean up the recorded summary and menu global.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( AuditResults::OPTION );
		unset( $GLOBALS['submenu'] );
		AuditScheduler::unschedule();

		parent::tear_down();
	}

	/**
	 * Build a scheduler wired like production.
	 *
	 * @return AuditScheduler
	 */
	private function scheduler(): AuditScheduler {
		return new AuditScheduler( $this->query_repository(), $this->auditor(), $this->audit_results() );
	}

	/**
	 * Test nothing is recorded until an audit runs.
	 */
	public function test_no_summary_until_an_audit_is_recorded(): void {
		$this->assertNull( $this->audit_results()->summary() );
		$this->assertSame( 0, $this->audit_results()->problem_count() );
	}

	/**
	 * Test the scheduled run records the batch outcome.
	 */
	public function test_scheduled_run_records_the_outcome(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/cron-broken', $post_id );
		$this->create_redirect( '/wp-admin/', $post_id );
		wp_delete_post( $post_id, true );

		$this->scheduler()->run();

		$summary = $this->audit_results()->summary();

		$this->assertNotNull( $summary );
		// Both redirects now point at a deleted post (two problems), and one
		// source is reserved (one warning).
		$this->assertSame( 2, $summary['problems'] );
		$this->assertSame( 1, $summary['warnings'] );
		$this->assertSame( 2, $summary['checked'] );
		$this->assertFalse( $summary['with_urls'] );
		$this->assertSame( 2, $this->audit_results()->problem_count() );
	}

	/**
	 * Test the daily run schedules itself once.
	 */
	public function test_maybe_schedule_schedules_exactly_once(): void {
		$scheduler = $this->scheduler();

		// The plugin's own init hook may already have scheduled a run in this
		// test environment; start from a clean slate.
		AuditScheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( AuditScheduler::HOOK ) );

		$scheduler->maybe_schedule();
		$first = wp_next_scheduled( AuditScheduler::HOOK );
		$this->assertNotFalse( $first );

		$scheduler->maybe_schedule();
		$this->assertSame( $first, wp_next_scheduled( AuditScheduler::HOOK ) );

		AuditScheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( AuditScheduler::HOOK ) );
	}

	/**
	 * Prime the submenu global with a Validate entry.
	 *
	 * @return string The parent menu slug.
	 */
	private function prime_submenu(): string {
		$parent = 'edit.php?post_type=' . PostType::POST_TYPE;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored in tear_down; the badge writes to the real submenu global.
		$GLOBALS['submenu'] = array(
			$parent => array(
				5 => array( 'Validate', 'manage_redirects', ValidatePage::PAGE_SLUG ),
			),
		);

		return $parent;
	}

	/**
	 * Test the badge shows the recorded problem count beside Validate, problems only.
	 */
	public function test_menu_badge_appends_problem_count(): void {
		update_option(
			AuditResults::OPTION,
			array(
				'problems'     => 3,
				'warnings'     => 5,
				'checked'      => 10,
				'with_urls'    => false,
				'completed_at' => time(),
			)
		);

		$parent = $this->prime_submenu();

		( new MenuBadge( $this->audit_results() ) )->add_badge();

		$this->assertStringContainsString( 'awaiting-mod count-3', $GLOBALS['submenu'][ $parent ][5][0] );
		$this->assertStringContainsString( '3 redirects with problems', $GLOBALS['submenu'][ $parent ][5][0] );
	}

	/**
	 * Test the badge stays away when the last audit found no problems.
	 */
	public function test_menu_badge_absent_without_problems(): void {
		$this->audit_results()->record( array(), 5, false );

		$parent = $this->prime_submenu();

		( new MenuBadge( $this->audit_results() ) )->add_badge();

		$this->assertSame( 'Validate', $GLOBALS['submenu'][ $parent ][5][0] );
	}
}
