<?php
/**
 * UpgradeNotice unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * UpgradeNoticeTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 */
final class UpgradeNoticeTest extends MonkeyStubs {

	/**
	 * The notice under test.
	 *
	 * @var UpgradeNotice
	 */
	private UpgradeNotice $notice;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\stubs( array( 'wp_kses', 'number_format_i18n' ) );

		// Upgrader is final, so drive needs_upgrade() through its option read
		// rather than mocking it.
		$this->notice = new UpgradeNotice( new Upgrader() );
	}

	/**
	 * Test the notice renders on the plugin screen while an upgrade is pending.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_renders_notice_when_upgrade_pending(): void {
		$this->prime_screen( PostType::POST_TYPE );

		$output = $this->render();

		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'wp legacy-redirector migrate', $output );
	}

	/**
	 * Test no notice is rendered once the upgrade has completed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_renders_nothing_when_up_to_date(): void {
		$this->prime_screen( PostType::POST_TYPE, Upgrader::DB_VERSION );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test a completed upgrade still warns while failed writes await a retry.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::show
	 */
	public function test_display_warns_of_failed_writes_once_up_to_date(): void {
		$this->prime_screen( PostType::POST_TYPE, Upgrader::DB_VERSION );
		$this->prime_retries( array( 5, 6 ), array( 9 ) );

		$output = $this->render();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( '3 redirects could not be migrated', $output );
		$this->assertStringContainsString( '<code>wp legacy-redirector migrate</code>', $output );
	}

	/**
	 * Test the in-progress notice takes precedence over failed writes.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_shows_only_progress_while_upgrade_pending(): void {
		$this->prime_screen( PostType::POST_TYPE );
		$this->prime_retries( array( 5 ) );

		$output = $this->render();

		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringNotContainsString( 'could not be migrated', $output );
	}

	/**
	 * Test no notice is rendered on unrelated admin screens.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_renders_nothing_on_other_screens(): void {
		$this->prime_screen( 'post' );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test no notice is rendered when no screen is available.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_renders_nothing_without_screen(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_current_screen' )->justReturn( null );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Test no notice is rendered for users without the manage redirects capability.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice::display
	 */
	public function test_display_requires_capability(): void {
		$this->prime_screen( PostType::POST_TYPE, 0, false );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Stub the option, screen, and capability the notice checks.
	 *
	 * @param string $screen_post_type Post type of the current screen.
	 * @param int    $db_version      Stored data schema version.
	 * @param bool   $can             Whether the user has the capability.
	 * @return void
	 */
	private function prime_screen( string $screen_post_type, int $db_version = 0, bool $can = true ): void {
		Functions\when( 'get_option' )->justReturn( $db_version );
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'post_type' => $screen_post_type ) );
		Functions\when( 'current_user_can' )->justReturn( $can );
	}

	/**
	 * Stub the recorded failures, one set of IDs per failed walk, alongside the data version.
	 *
	 * @param int[] ...$sets The failed IDs of each walk.
	 * @return void
	 */
	private function prime_retries( array ...$sets ): void {
		$version = get_option( Upgrader::VERSION_OPTION );
		$retries = array();
		foreach ( $sets as $index => $ids ) {
			$retries[ '2026-01-0' . ( $index + 1 ) . ' 00:00:00' ] = array(
				'publish' => true,
				'ids'     => $ids,
			);
		}

		Functions\when( 'get_option' )->alias(
			static fn( string $name ) => 'wpcom_legacy_redirector_upgrade_retry' === $name ? $retries : $version
		);
	}

	/**
	 * Capture the notice output.
	 *
	 * @return string Rendered output.
	 */
	private function render(): string {
		ob_start();
		$this->notice->display();

		return (string) ob_get_clean();
	}
}
