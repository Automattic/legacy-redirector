<?php
/**
 * Autoloader fallback unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * Guards the hand-rolled autoloader in the main plugin file.
 *
 * The distributed ZIP excludes `vendor/`, so a released install has no Composer
 * autoloader and falls back to the `spl_autoload_register()` closure in
 * wpcom-legacy-redirector.php. That closure maps a PSR-4 prefix onto a
 * directory, exactly as composer.json does, but nothing keeps the two in step:
 * if they drift, or if a class under src/ sits at a path its name does not
 * imply, development keeps working (Composer's classmap finds it) while a fresh
 * install fatals on a missing class.
 *
 * @coversNothing
 */
final class AutoloaderFallbackTest extends TestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @return string
	 */
	private function plugin_dir(): string {
		return dirname( dirname( __DIR__ ) );
	}

	/**
	 * The namespace prefix and source directory the fallback closure uses.
	 *
	 * Read out of the plugin file rather than hardcoded, so editing the closure
	 * moves this test's expectations with it.
	 *
	 * @return array{0: string, 1: string} Prefix (trailing separator) and directory (trailing slash).
	 */
	private function fallback_mapping(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file in a test; wp_remote_get() is for URLs.
		$source = file_get_contents( $this->plugin_dir() . '/wpcom-legacy-redirector.php' );

		$this->assertIsString( $source, 'Could not read the main plugin file.' );

		$this->assertSame(
			1,
			preg_match( '/\$prefix\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\';/', $source, $prefix_match ),
			'Could not find the $prefix assignment in the autoloader fallback.'
		);

		$this->assertSame(
			1,
			preg_match( '#\$file\s*=\s*__DIR__\s*\.\s*\'/([^\']+)/\'#', $source, $dir_match ),
			'Could not find the source directory in the autoloader fallback.'
		);

		// The PHP source escapes each separator, so '\\\\' in the file is one
		// backslash in the runtime value.
		return array( stripcslashes( $prefix_match[1] ), $dir_match[1] . '/' );
	}

	/**
	 * The fallback and composer.json must describe the same PSR-4 mapping.
	 */
	public function test_fallback_matches_composer_psr4_mapping(): void {
		list( $prefix, $directory ) = $this->fallback_mapping();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file in a test; wp_remote_get() is for URLs.
		$composer = json_decode( file_get_contents( $this->plugin_dir() . '/composer.json' ), true );

		$this->assertSame(
			array( $prefix => $directory ),
			$composer['autoload']['psr-4'],
			'The autoloader fallback and composer.json PSR-4 map have diverged; '
				. 'a released ZIP (which ships no vendor/) would fail to load these classes.'
		);
	}

	/**
	 * Every file under src/ must declare the class the fallback would look for.
	 *
	 * @dataProvider source_file_provider
	 *
	 * @param string $relative_path Path below src/, e.g. "Domain/SourceUrl.php".
	 */
	public function test_source_file_declares_the_expected_symbol( string $relative_path ): void {
		list( $prefix, $directory ) = $this->fallback_mapping();

		// Invert the closure's mapping: strip src/, swap separators, drop .php.
		$expected = $prefix . str_replace( '/', '\\', substr( $relative_path, 0, -4 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file in a test; wp_remote_get() is for URLs.
		$source = file_get_contents( $this->plugin_dir() . '/' . $directory . $relative_path );

		preg_match( '/^namespace\s+([^;]+);/m', $source, $namespace_match );
		preg_match( '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $symbol_match );

		$this->assertNotEmpty( $symbol_match, "No class, interface, trait or enum declared in {$relative_path}." );

		$declared = ( $namespace_match ? trim( $namespace_match[1] ) . '\\' : '' ) . $symbol_match[1];

		$this->assertSame(
			$expected,
			$declared,
			"{$relative_path} declares {$declared}, but the autoloader fallback would only "
				. "look for {$expected} at that path."
		);
	}

	/**
	 * Every PHP file below src/.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function source_file_provider(): array {
		$src = dirname( dirname( __DIR__ ) ) . '/src';

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS )
		);

		$cases = array();

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $src ) + 1 );

			// View templates are include()d, not autoloaded, so they declare no class.
			if ( str_contains( $relative, '/views/' ) ) {
				continue;
			}

			$cases[ $relative ] = array( $relative );
		}

		ksort( $cases );

		// An empty provider would make this test vacuously pass.
		self::assertNotEmpty( $cases, 'Found no PHP files under src/.' );

		return $cases;
	}
}
