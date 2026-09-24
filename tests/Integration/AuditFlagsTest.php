<?php
/**
 * Per-row audit flags and "Has issues" view integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Integration tests for the stored per-row flags and the list-table view
 * that reads them.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 */
final class AuditFlagsTest extends TestCase {

	/**
	 * Clean up the run timestamp and request state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( AuditFlags::CHECKED_AT_OPTION );
		unset( $_GET['audit_flagged'] );

		parent::tear_down();
	}

	/**
	 * The flags store under test.
	 *
	 * @return AuditFlags
	 */
	private function flags(): AuditFlags {
		return new AuditFlags();
	}

	/**
	 * Test a row with a problem finding is flagged 'problem'.
	 */
	public function test_problem_findings_flag_the_row_as_problem(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/flag-problem', $post_id );
		wp_delete_post( $post_id, true );

		$redirect = $this->repository()->find_by_id( $redirect_id );
		$this->flags()->record_row( $redirect_id, $this->auditor()->audit( $redirect ) );

		$this->assertSame( 'problem', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test a row with only warnings is flagged 'warning'.
	 */
	public function test_warning_only_findings_flag_the_row_as_warning(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/wp-admin/', $post_id );

		$redirect = $this->repository()->find_by_id( $redirect_id );
		$this->flags()->record_row( $redirect_id, $this->auditor()->audit( $redirect ) );

		$this->assertSame( 'warning', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test a clean row gets no flag, and a re-check clears a stale one.
	 */
	public function test_no_findings_leave_no_flag_and_clear_a_stale_one(): void {
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$redirect_id = $this->create_redirect( '/flag-clean', $post_id );

		update_post_meta( $redirect_id, AuditFlags::META_KEY, 'problem' );

		$redirect = $this->repository()->find_by_id( $redirect_id );
		$this->flags()->record_row( $redirect_id, $this->auditor()->audit( $redirect ) );

		$this->assertSame( '', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test saving a redirect clears its flag, so a flag never outlives the
	 * row it described.
	 */
	public function test_saving_a_redirect_clears_its_flag(): void {
		$flags = $this->flags();
		$flags->register();

		$redirect_id = $this->create_redirect( '/flag-save-clears', 'https://example.com/destination' );
		update_post_meta( $redirect_id, AuditFlags::META_KEY, 'problem' );

		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( '', get_post_meta( $redirect_id, AuditFlags::META_KEY, true ) );
	}

	/**
	 * Test the counts come from the stored flags, by severity.
	 */
	public function test_counts_report_flagged_rows_by_severity(): void {
		$problem_id = $this->create_redirect( '/flag-count-problem', 'https://example.com/a' );
		$warning_id = $this->create_redirect( '/flag-count-warning', 'https://example.com/b' );
		$this->create_redirect( '/flag-count-clean', 'https://example.com/c' );

		update_post_meta( $problem_id, AuditFlags::META_KEY, 'problem' );
		update_post_meta( $warning_id, AuditFlags::META_KEY, 'warning' );

		$this->assertSame(
			array(
				'problem' => 1,
				'warning' => 1,
			),
			$this->flags()->counts()
		);
	}

	/**
	 * Test no run timestamp exists until a run completes.
	 */
	public function test_checked_at_null_until_a_run_completes(): void {
		$flags = $this->flags();

		$this->assertNull( $flags->checked_at() );

		$flags->mark_run_complete();

		$this->assertIsInt( $flags->checked_at() );
	}

	/**
	 * The view filters wired like production.
	 *
	 * @return ViewFilters
	 */
	private function view_filters(): ViewFilters {
		return new ViewFilters( $this->query_repository(), $this->flags() );
	}

	/**
	 * Test the "Has issues" view only appears once a run has completed, and
	 * carries the flagged count and the check time.
	 */
	public function test_has_issues_view_appears_after_a_completed_run(): void {
		$this->assertArrayNotHasKey( 'audit_flagged', $this->view_filters()->customize_views( array() ) );

		$redirect_id = $this->create_redirect( '/flag-view', 'https://example.com/a' );
		update_post_meta( $redirect_id, AuditFlags::META_KEY, 'problem' );
		$this->flags()->mark_run_complete();

		$views = $this->view_filters()->customize_views( array() );

		$this->assertArrayHasKey( 'audit_flagged', $views );
		$this->assertStringContainsString( 'Has issues (scanned ', $views['audit_flagged'] );
		$this->assertStringContainsString( '(1)', $views['audit_flagged'] );
		$this->assertStringContainsString( 'audit_flagged=1', $views['audit_flagged'] );
	}

	/**
	 * Test the view's query restricts the list to flagged rows.
	 */
	public function test_audit_flagged_query_returns_only_flagged_rows(): void {
		$flagged_id = $this->create_redirect( '/flag-query-flagged', 'https://example.com/a' );
		$this->create_redirect( '/flag-query-clean', 'https://example.com/b' );
		update_post_meta( $flagged_id, AuditFlags::META_KEY, 'problem' );

		$_GET['audit_flagged'] = '1';
		$this->view_filters()->register();

		$query = new \WP_Query();
		// The filter only touches the main admin query.
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored by the WP test framework per test.

		$posts = $query->query(
			array(
				'post_type'   => PostType::POST_TYPE,
				'post_status' => array( 'publish', 'draft' ),
				'fields'      => 'ids',
			)
		);

		$this->assertSame( array( $flagged_id ), $posts );
	}
}
