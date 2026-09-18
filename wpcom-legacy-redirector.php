<?php
/**
 * Plugin Name: Legacy Redirector
 * Plugin URI: https://github.com/Automattic/legacy-redirector
 * Description: Simple plugin for handling legacy redirects in a scalable manner.
 * Version: 2.0.0-alpha
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Author: Automattic / WordPress VIP
 * Author URI: https://wpvip.com
 *
 * Redirects are stored as a custom post type and use the following fields:
 *
 * - post_name for the md5 hash of the "from" path or URL.
 *  - we use this column, since it's indexed and queries are super fast.
 *  - we also use an md5 just to simplify the storage.
 * - post_title to store the non-md5 version of the "from" path.
 * - one of either:
 *  - post_parent if we're redirect to a post; or
 *  - post_excerpt if we're redirecting to an alternate URL.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector;

use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper;

// Namespaced constants.
const PLUGIN_FILE = __FILE__;
const VERSION     = '2.0.0-alpha';

// Global constants for backwards compatibility.
\define( 'WPCOM_LEGACY_REDIRECTOR_FILE', __FILE__ );
\define( 'WPCOM_LEGACY_REDIRECTOR_VERSION', VERSION );

// Load Composer autoloader for PSR-4 classes (src/), or register a simple fallback.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		function ( string $class_name ): void {
			$prefix = 'Automattic\\LegacyRedirector\\';
			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Initialize the plugin.
( new PluginBootstrapper( Container::instance() ) )->init();

// Deactivation must clear the scheduled audit, or the cron event keeps
// firing into a void.
register_deactivation_hook(
	__FILE__,
	array( \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditScheduler::class, 'unschedule' )
);

/**
 * Get the plugin's DI container instance.
 *
 * This function is provided for third-party developers who need to access
 * plugin services. Internal plugin code should use Container::instance() directly.
 *
 * Usage:
 *   use function Automattic\LegacyRedirector\container;
 *   $manager = container()->manager();
 *
 * Or with full namespace:
 *   $manager = \Automattic\LegacyRedirector\container()->manager();
 *
 * @return Container The container.
 */
function container(): Container {
	return Container::instance();
}
