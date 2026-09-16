<?php
/**
 * HomePath unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * HomePathTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class HomePathTest extends MonkeyStubs {

	/**
	 * Test the home path is read from home_url() and stripped of its slash.
	 *
	 * @dataProvider data_home_urls
	 *
	 * @covers \Automattic\LegacyRedirector\Application\HomePath::current
	 *
	 * @param string $home_url The site's home URL.
	 * @param string $expected The home path it should yield.
	 */
	public function test_current( string $home_url, string $expected ): void {
		Functions\when( 'home_url' )->justReturn( $home_url );

		$this->assertSame( $expected, HomePath::current() );
	}

	/**
	 * Data provider of home URLs and the home path each yields.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_home_urls(): array {
		return array(
			'domain root'             => array( 'https://example.com', '' ),
			'domain root with slash'  => array( 'https://example.com/', '' ),
			'subdirectory'            => array( 'https://example.com/blog', '/blog' ),
			'subdirectory with slash' => array( 'https://example.com/blog/', '/blog' ),
			'nested subdirectory'     => array( 'https://example.com/a/b', '/a/b' ),
			'port'                    => array( 'https://example.com:8080/blog', '/blog' ),
			'unicode'                 => array( 'https://example.com/日本', '/日本' ),
			'unicode with slash'      => array( 'https://example.com/日本/', '/日本' ),
			'percent-encoded'         => array( 'https://example.com/%E6%97%A5%E6%9C%AC', '/日本' ),
			'emoji'                   => array( 'https://example.com/🎉', '/🎉' ),
		);
	}

	/**
	 * Test a raw unicode home path survives, whatever the runner's locale.
	 *
	 * PHP's parse_url() replaces every byte iscntrl() calls a control with
	 * '_'. Under a UTF-8 LC_CTYPE that includes 0x80-0x9F, the C1 range - and
	 * ordinary UTF-8 continuation-byte territory. A bare wp_parse_url() here
	 * would return '/日_日_' on a macOS dev box and '/日本' on Linux CI, so
	 * the home path would strip on one and not the other.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\HomePath::current
	 */
	public function test_current_is_not_corrupted_by_a_utf8_locale(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/日本' );

		$previous = setlocale( LC_CTYPE, '0' );
		setlocale( LC_CTYPE, 'en_US.UTF-8', 'en_GB.UTF-8', 'C.UTF-8' );

		try {
			$this->assertSame( '/日本', HomePath::current() );
			$this->assertSame( bin2hex( '/日本' ), bin2hex( HomePath::current() ) );
		} finally {
			setlocale( LC_CTYPE, (string) $previous );
		}
	}

	/**
	 * Test paths are rebased onto the home path.
	 *
	 * @dataProvider data_make_relative
	 *
	 * @covers \Automattic\LegacyRedirector\Application\HomePath::make_relative
	 *
	 * @param string      $path      The path to rebase.
	 * @param string      $home_path The site's home path.
	 * @param string|null $expected  The home-relative path, or null when not under home.
	 */
	public function test_make_relative( string $path, string $home_path, ?string $expected ): void {
		$this->assertSame( $expected, HomePath::make_relative( $path, $home_path ) );
	}

	/**
	 * Data provider for test_make_relative.
	 *
	 * @return array<string, array{string, string, string|null}>
	 */
	public static function data_make_relative(): array {
		return array(
			// Home at the domain root: nothing to strip, everything passes through.
			'root home passes through'       => array( '/old-page', '', '/old-page' ),
			'root home keeps encoding'       => array( '/%E6%97%A5%E6%9C%AC', '', '/%E6%97%A5%E6%9C%AC' ),
			'slash-only home passes through' => array( '/old-page', '/', '/old-page' ),

			// ASCII home path.
			'prefix stripped'                => array( '/blog/old-page', '/blog', '/old-page' ),
			'home itself becomes root'       => array( '/blog', '/blog', '/' ),
			'trailing slash becomes root'    => array( '/blog/', '/blog', '/' ),
			'trailing slash preserved'       => array( '/blog/a/', '/blog', '/a/' ),
			'nested home path stripped'      => array( '/a/b/old-page', '/a/b', '/old-page' ),
			'home path with trailing slash'  => array( '/blog/old-page', '/blog/', '/old-page' ),
			'deeper path stripped once'      => array( '/blog/blog/old-page', '/blog', '/blog/old-page' ),

			// Not under home.
			'partial segment not stripped'   => array( '/blogging-tips', '/blog', null ),
			'partial segment with child'     => array( '/blogging-tips/x', '/blog', null ),
			'sibling directory'              => array( '/other/old-page', '/blog', null ),
			'root path under subdirectory'   => array( '/', '/blog', null ),
			'shorter than home path'         => array( '/a', '/a/b', null ),
			'scheme-relative left alone'     => array( '//blog/x', '/blog', null ),

			// Unicode home path, both encodings of the request.
			'decoded request, decoded home'  => array( '/日本/ページ', '/日本', '/ページ' ),
			'encoded request, decoded home'  => array( '/%E6%97%A5%E6%9C%AC/%E3%83%9A%E3%83%BC%E3%82%B8', '/日本', '/%E3%83%9A%E3%83%BC%E3%82%B8' ),
			'decoded request, encoded home'  => array( '/日本/ページ', '/%E6%97%A5%E6%9C%AC', '/ページ' ),
			'encoded request, encoded home'  => array( '/%E6%97%A5%E6%9C%AC/x', '/%E6%97%A5%E6%9C%AC', '/x' ),
			'encoded home itself'            => array( '/%E6%97%A5%E6%9C%AC', '/日本', '/' ),
			'emoji home path'                => array( '/%F0%9F%8E%89/x', '/🎉', '/x' ),
			'unicode partial segment'        => array( '/%E6%97%A5%E6%9C%AC%E8%AA%9E/x', '/日本', null ),
			'mixed ascii and unicode home'   => array( '/blog/%E6%97%A5%E6%9C%AC/x', '/blog', '/%E6%97%A5%E6%9C%AC/x' ),

			// Encoding differences within an ASCII path.
			'encoded ascii segment matches'  => array( '/blo%67/old-page', '/blog', '/old-page' ),
			'encoded space in home path'     => array( '/my%20blog/x', '/my blog', '/x' ),
		);
	}

	/**
	 * Test the remainder keeps the encoding it arrived in.
	 *
	 * SourceUrl is the single owner of decoding. Decoding here as well would
	 * decode twice, so a source containing a literal '%25' could never match.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\HomePath::make_relative
	 */
	public function test_make_relative_does_not_decode_the_remainder(): void {
		$this->assertSame( '/100%25-cotton', HomePath::make_relative( '/blog/100%25-cotton', '/blog' ) );
	}

	/**
	 * Test a query string is carried through untouched.
	 *
	 * The resolver appends the query after stripping, but a caller that passes
	 * one in must not have it mangled.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\HomePath::make_relative
	 */
	public function test_make_relative_leaves_a_query_string_alone(): void {
		$this->assertSame( '/old-page?a=1&b=2', HomePath::make_relative( '/blog/old-page?a=1&b=2', '/blog' ) );
	}
}
