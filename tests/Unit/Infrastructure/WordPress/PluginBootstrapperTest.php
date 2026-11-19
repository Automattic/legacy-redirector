<?php
/**
 * Unit tests for PluginBootstrapper.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * PluginBootstrapperTest class.
 *
 * @coversNothing These assert declared constants, not executed code.
 */
final class PluginBootstrapperTest extends TestCase {

	/**
	 * Test the two WP-CLI namespaces are distinct.
	 *
	 * Guards a regression that shipped once: a global search and replace over
	 * the plugin slug rewrote the deprecated constant to match the current one.
	 * Both namespaces then registered under the same name, which removed the
	 * `wp wpcom-legacy-redirector` alias entirely and made every command warn
	 * that it was deprecated in favor of itself.
	 *
	 * The Behat scenario covering the alias did not catch it, because the same
	 * search and replace rewrote both sides of its assertion.
	 *
	 * @coversNothing
	 *
	 * @return void
	 */
	public function test_cli_namespaces_are_distinct(): void {
		$this->assertNotSame(
			PluginBootstrapper::CLI_NAMESPACE,
			PluginBootstrapper::CLI_NAMESPACE_DEPRECATED,
			'The deprecated WP-CLI namespace must differ from the current one, or the alias does not exist.'
		);
	}

	/**
	 * Test the deprecated WP-CLI namespace is the one 1.x shipped.
	 *
	 * Hard-coded rather than derived, so that renaming the plugin again cannot
	 * quietly move the alias and break every 1.x runbook.
	 *
	 * @coversNothing
	 *
	 * @return void
	 */
	public function test_deprecated_cli_namespace_matches_the_1_x_command(): void {
		$this->assertSame(
			'wpcom-legacy-redirector',
			PluginBootstrapper::CLI_NAMESPACE_DEPRECATED
		);
	}
}
