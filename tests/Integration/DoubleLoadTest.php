<?php
/**
 * Double-load tests
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\DI\Container;

use function Automattic\LegacyRedirector\container;

use const Automattic\LegacyRedirector\PLUGIN_FILE;
use const Automattic\LegacyRedirector\VERSION;

/**
 * A second copy of the plugin, loaded in code from another folder, must do nothing.
 *
 * Sites that load plugins in code (wpcom_vip_load_plugin() or a require in an
 * mu-plugin) can end up including both a Composer copy and a ZIP copy. The
 * first load wins; the second must neither fatal nor register its hooks again.
 *
 * @coversNothing
 */
final class DoubleLoadTest extends TestCase {

	/**
	 * Test that including a second copy of the main file is a no-op.
	 */
	public function test_second_copy_of_main_file_does_nothing(): void {
		$hooks  = array( 'init', 'admin_init', 'template_redirect' );
		$before = array_map( array( $this, 'callback_count' ), $hooks );

		$plugin_file = dirname( __DIR__, 2 ) . '/wpcom-legacy-redirector.php';
		$copy        = wp_tempnam( 'wpcom-legacy-redirector.php' );
		copy( $plugin_file, $copy );

		try {
			// Warnings and notices are converted to exceptions, so any would fail here.
			require $copy;
		} finally {
			wp_delete_file( $copy );
		}

		$this->assertSame( realpath( $plugin_file ), realpath( PLUGIN_FILE ) );
		$this->assertSame( PLUGIN_FILE, WPCOM_LEGACY_REDIRECTOR_FILE );
		$this->assertSame( VERSION, WPCOM_LEGACY_REDIRECTOR_VERSION );
		$this->assertSame( Container::instance(), container() );
		$this->assertSame( $before, array_map( array( $this, 'callback_count' ), $hooks ) );
	}

	/**
	 * Number of callbacks attached to a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function callback_count( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		return array_sum( array_map( 'count', $wp_filter[ $hook ]->callbacks ) );
	}
}
