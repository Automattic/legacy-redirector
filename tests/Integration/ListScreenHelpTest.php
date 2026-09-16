<?php
/**
 * Contextual help tab integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * ListScreenHelpTest class.
 *
 * The Base URL tab explains the grey prefix on the list table, so it has to
 * appear under exactly the same condition the prefix does: home is not the
 * domain root. Gating it on is_multisite() left a single site at
 * example.com/blog showing a prefix with nothing to explain it, and gave a
 * subdomain multisite an explanation for a prefix it does not render.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class ListScreenHelpTest extends TestCase {

	/**
	 * The setup under test.
	 *
	 * @var ListScreenSetup
	 */
	private ListScreenSetup $setup;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->setup = new ListScreenSetup();
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
	 * @return string The home URL with a subdirectory appended.
	 */
	public function filter_home_url( $url ): string {
		return untrailingslashit( $url ) . '/blog';
	}

	/**
	 * The Base URL tab appears where home is not the domain root.
	 *
	 * @return void
	 */
	public function test_base_url_tab_is_added_when_home_is_below_the_root(): void {
		add_filter( 'home_url', array( $this, 'filter_home_url' ) );

		$screen = $this->render_help();

		$this->assertNotNull(
			$screen->get_help_tab( 'base-url' ),
			'A site installed below the domain root shows the prefix, so it needs the explanation.'
		);
	}

	/**
	 * The Base URL tab stays away where home is the domain root.
	 *
	 * @return void
	 */
	public function test_base_url_tab_is_absent_when_home_is_the_root(): void {
		$screen = $this->render_help();

		$this->assertNull(
			$screen->get_help_tab( 'base-url' ),
			'No prefix is rendered at the domain root, so there is nothing to explain.'
		);
	}

	/**
	 * The Multisite tab tracks multisite, independently of the home path.
	 *
	 * @return void
	 */
	public function test_multisite_tab_tracks_multisite_not_the_home_path(): void {
		add_filter( 'home_url', array( $this, 'filter_home_url' ) );

		$screen = $this->render_help();

		if ( is_multisite() ) {
			$this->assertNotNull( $screen->get_help_tab( 'multisite' ) );
			return;
		}

		$this->assertNull(
			$screen->get_help_tab( 'multisite' ),
			'A single site below the root is not multisite, however its paths are stored.'
		);
	}

	/**
	 * Run the help registration against the redirects list screen.
	 *
	 * @return \WP_Screen The screen, with help tabs registered.
	 */
	private function render_help(): \WP_Screen {
		set_current_screen( 'edit-' . PostType::POST_TYPE );
		$screen = get_current_screen();

		$screen->remove_help_tabs();
		$this->setup->add_contextual_help( $screen );

		return $screen;
	}
}
