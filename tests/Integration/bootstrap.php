<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types=1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( dirname( __DIR__ ) ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = WPIntegration\get_path_to_wp_test_dir();

if ( empty( $_tests_dir ) ) {
	echo 'ERROR: Could not find WordPress test library directory.' . PHP_EOL;
	echo 'Make sure wp-env is running: npm run wp-env start' . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
\tests_add_filter(
	'muplugins_loaded',
	function (): void {
		// Updated from default (__FILE__), since this bootstrap is an extra level down in tests/Integration/.
		require dirname( dirname( __DIR__ ) ) . '/wpcom-legacy-redirector.php';
	}
);

/*
 * Bootstrap WordPress. This will also load the Composer autoload file, the PHPUnit Polyfills
 * and the custom autoloader for the TestCase and the mock object classes.
 */
WPIntegration\bootstrap_it();

// The plugin grants manage_redirects on init, which has just run and committed
// the grant outside any test transaction. Remove it, so every test starts from
// a site that has not granted it and TestCase::tear_down() restores that state.
foreach ( array( 'administrator', 'editor' ) as $_role_name ) {
	get_role( $_role_name )?->remove_cap( \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::MANAGE_REDIRECTS_CAPABILITY );
}
delete_option( \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::MANAGE_REDIRECTS_CAPABILITY . '_capability_version' );

// Load WP_CLI stubs for CLI integration tests.
// This must be loaded after WordPress bootstrap but before tests run.
require_once dirname( __DIR__ ) . '/Stubs/WpCliUtilsStubs.php';
require_once dirname( __DIR__ ) . '/Stubs/WordPressStubs.php';
require_once dirname( __DIR__ ) . '/Stubs/CliSleepStub.php';

// Add custom test case.
require __DIR__ . '/TestCase.php';
