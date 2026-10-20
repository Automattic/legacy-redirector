<?php
/**
 * Add/Edit Redirect screen setup integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Tests the title and help the form pages get from their screen.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup
 */
final class FormScreenSetupTest extends TestCase {

	/**
	 * Clean up the request and title.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $GLOBALS['title'] );
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Test the hidden Edit page gets a title, since it has no menu entry to take one from.
	 *
	 * @dataProvider data_page_titles
	 *
	 * @param string      $page     The requested page slug.
	 * @param string|null $title    The title already set, if any.
	 * @param string|null $expected The title after the screen is set up.
	 */
	public function test_the_edit_page_gets_a_title( string $page, ?string $title, ?string $expected ): void {
		$_GET['page'] = $page;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The admin title core reads; unset in tear_down().
		$GLOBALS['title'] = $title;

		( new FormScreenSetup() )->set_edit_page_title();

		$this->assertSame( $expected, $GLOBALS['title'] );
	}

	/**
	 * Data provider for the Edit page title.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string|null}>
	 */
	public static function data_page_titles(): array {
		return array(
			'edit page, no title'    => array( 'edit-redirect', null, 'Edit Redirect' ),
			'edit page, title set'   => array( 'edit-redirect', 'Already set', 'Already set' ),
			'another page, no title' => array( 'add-redirect', null, null ),
		);
	}

	/**
	 * Test both form pages get the help tabs, and no other screen does.
	 *
	 * @dataProvider data_help_screens
	 *
	 * @param string   $screen_id The screen.
	 * @param string[] $expected  The help tab IDs it should have.
	 */
	public function test_help_tabs_appear_on_the_form_pages_only( string $screen_id, array $expected ): void {
		set_current_screen( $screen_id );
		$screen = get_current_screen();

		( new FormScreenSetup() )->add_contextual_help( $screen );

		$this->assertSame( $expected, array_keys( $screen->get_help_tabs() ) );
	}

	/**
	 * Data provider for which screens get the form help.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function data_help_screens(): array {
		$tabs = array( 'overview', 'source', 'destination', 'tips' );

		return array(
			'add page'    => array( 'vip-legacy-redirect_page_add-redirect', $tabs ),
			'edit page'   => array( 'vip-legacy-redirect_page_edit-redirect', $tabs ),
			'list screen' => array( 'edit-vip-legacy-redirect', array() ),
		);
	}
}
