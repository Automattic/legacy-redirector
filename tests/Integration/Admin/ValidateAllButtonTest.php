<?php
/**
 * "Validate all redirects" button integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ValidateAllButton;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for the button's states: ready, under way, and finished.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ValidateAllButton
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Application\PostPathResolver
 * @uses \Automattic\LegacyRedirector\Application\SourceOccupancy
 */
final class ValidateAllButtonTest extends TestCase {

	/**
	 * Sign in as a redirect manager on the redirects list screen.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();
		$user = wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
		set_current_screen( 'edit-' . PostType::POST_TYPE );
	}

	/**
	 * Clean up the sweep's state, the badge, the screen and the user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array( AuditSweep::STATE_OPTION, AuditSweep::LOCK_OPTION, AuditFlags::CHECKED_AT_OPTION, AuditFlags::COUNTS_OPTION ) as $option ) {
			delete_option( $option );
		}

		set_current_screen( 'front' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The sweep the button reports on.
	 *
	 * @return AuditSweep
	 */
	private function sweep(): AuditSweep {
		return new AuditSweep( $this->query_repository(), $this->auditor(), new AuditFlags() );
	}

	/**
	 * Render the button.
	 *
	 * @return string The markup.
	 */
	private function render(): string {
		ob_start();
		( new ValidateAllButton( $this->sweep(), new AuditFlags() ) )->render( 'top' );

		return (string) ob_get_clean();
	}

	/**
	 * Test a pass under way offers to continue it, not restart it, and says leaving is fine.
	 */
	public function test_a_pass_under_way_is_continued_not_restarted(): void {
		for ( $i = 1; $i <= AuditSweep::BATCH_SIZE + 1; $i++ ) {
			$this->insert_redirect_post( array( 'post_title' => "/button-{$i}" ) );
		}

		$sweep = $this->sweep();
		$this->assertTrue( $sweep->lock( 0 ) );
		$sweep->start_pass();
		$sweep->run_batch();
		$sweep->unlock();

		$html = $this->render();

		$this->assertStringContainsString( 'Continue validating', $html );
		$this->assertStringContainsString( 'data-restart="0"', $html );
		$this->assertStringContainsString( 'It carries on in the background if you leave this page.', $html );
	}

	/**
	 * Test a finished pass summarizes what it found and links to the flagged redirects.
	 */
	public function test_a_finished_pass_shows_its_results(): void {
		$this->create_redirect( '/button-broken', 'https://not-allowed.example.net/x' );

		$sweep = $this->sweep();
		$this->assertTrue( $sweep->lock( 0 ) );
		$sweep->start_pass();
		$sweep->run_batch();
		$sweep->unlock();

		$html = $this->render();

		$this->assertStringContainsString( 'Validate all redirects', $html );
		$this->assertStringContainsString( 'data-restart="1"', $html );
		$this->assertStringContainsString( '1 redirect has a problem. 0 redirects have warnings.', $html );
		$this->assertStringContainsString( 'audit_flagged=1', $html );
	}

	/**
	 * Test there is no link to flagged redirects before any pass has run.
	 */
	public function test_no_link_before_a_pass(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Not every redirect has been checked yet.', $html );
		$this->assertStringNotContainsString( 'audit_flagged', $html );
	}

	/**
	 * Test nothing promises background progress when the background check is off.
	 */
	public function test_no_background_promise_when_the_background_check_is_off(): void {
		for ( $i = 1; $i <= AuditSweep::BATCH_SIZE + 1; $i++ ) {
			$this->insert_redirect_post( array( 'post_title' => "/button-off-{$i}" ) );
		}

		$sweep = $this->sweep();
		$this->assertTrue( $sweep->lock( 0 ) );
		$sweep->start_pass();
		$sweep->run_batch();
		$sweep->unlock();

		add_filter( AuditSweep::BUDGET_FILTER, '__return_zero' );
		$html = $this->render();
		remove_filter( AuditSweep::BUDGET_FILTER, '__return_zero' );

		$this->assertStringContainsString( 'Continue validating', $html );
		$this->assertStringNotContainsString( 'carries on in the background', $html );
		$this->assertStringContainsString( 'The background check is turned off.', $html );
	}
}
