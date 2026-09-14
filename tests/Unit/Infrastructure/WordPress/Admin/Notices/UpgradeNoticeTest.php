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
		Monkey\Functions\stubs( array( 'wp_kses' ) );

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
		$this->assertStringContainsString( 'wp wpcom-legacy-redirector migrate', $output );
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
