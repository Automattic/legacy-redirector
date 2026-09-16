<?php
/**
 * Url parsing unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\Url;
use Yoast\WPTestUtils\BrainMonkey\YoastTestCase;

/**
 * UrlTest class.
 *
 * Url is pure PHP, so these tests need no WordPress function stubs.
 *
 * @covers \Automattic\LegacyRedirector\Domain\Url
 */
final class UrlTest extends YoastTestCase {

	/**
	 * Run a callback with LC_CTYPE forced to a UTF-8 locale.
	 *
	 * The bug this class exists for only manifests where iscntrl() reports
	 * true for 0x80-0x9F, which is the UTF-8 locale case. CI runs under the C
	 * locale, where a bare parse_url() behaves itself and every assertion
	 * below would pass with or without the fix. Forcing the locale is what
	 * makes these tests mean anything on Linux.
	 *
	 * @param callable $assertions The assertions to run.
	 * @return void
	 */
	private function with_utf8_ctype( callable $assertions ): void {
		$previous = setlocale( LC_CTYPE, '0' );
		$applied  = setlocale( LC_CTYPE, 'en_GB.UTF-8', 'en_US.UTF-8', 'C.UTF-8', 'UTF-8' );

		if ( false === $applied ) {
			$this->markTestSkipped( 'No UTF-8 locale available to force LC_CTYPE.' );
		}

		try {
			$assertions();
		} finally {
			setlocale( LC_CTYPE, (string) $previous );
		}
	}

	/**
	 * Test raw multibyte bytes survive parsing under a UTF-8 LC_CTYPE.
	 *
	 * The regression test for the whole class. A bare parse_url() here returns
	 * '/日_日_' on an affected host, because 0xE6 0x97 0xA5 has its 0x97 byte
	 * rewritten to '_'. Asserting on the hex makes the failure legible rather
	 * than a mojibake diff.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 */
	public function test_parse_survives_raw_multibyte_under_a_utf8_locale(): void {
		$this->with_utf8_ctype(
			function (): void {
				$parts = Url::parse( 'https://example.com/日本' );

				$this->assertSame( bin2hex( '/日本' ), bin2hex( $parts['path'] ) );
			}
		);
	}

	/**
	 * Test the same for the encoding-preserving variant.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse_encoded
	 */
	public function test_parse_encoded_survives_raw_multibyte_under_a_utf8_locale(): void {
		$this->with_utf8_ctype(
			function (): void {
				$parts = Url::parse_encoded( 'https://example.com/日本' );

				$this->assertSame( '/%E6%97%A5%E6%9C%AC', $parts['path'] );
			}
		);
	}

	/**
	 * Test every affected byte value round-trips.
	 *
	 * 0x80-0x9F is the range libc flags as C1 controls. Sweeping it catches a
	 * fix that happened to work for one character.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 */
	public function test_parse_survives_the_whole_c1_byte_range(): void {
		$this->with_utf8_ctype(
			function (): void {
				for ( $byte = 0x80; $byte <= 0x9F; $byte++ ) {
					// U+0080-U+009F encode as 0xC2 followed by the byte itself.
					$character = "\xC2" . chr( $byte );
					$parts     = Url::parse( 'https://example.com/x' . $character );

					$this->assertSame(
						bin2hex( '/x' . $character ),
						bin2hex( $parts['path'] ),
						sprintf( 'Byte 0x%02X did not survive parsing.', $byte )
					);
				}
			}
		);
	}

	/**
	 * Test parse() returns components decoded.
	 *
	 * @dataProvider data_parse
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 *
	 * @param string $url      The URL to parse.
	 * @param string $expected The expected path.
	 */
	public function test_parse_decodes_components( string $url, string $expected ): void {
		$this->assertSame( $expected, Url::parse( $url )['path'] );
	}

	/**
	 * Data provider of URLs and their decoded paths.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_parse(): array {
		return array(
			'ascii'                => array( '/old-page', '/old-page' ),
			'raw unicode'          => array( '/日本', '/日本' ),
			'encoded unicode'      => array( '/%E6%97%A5%E6%9C%AC', '/日本' ),
			'raw emoji'            => array( '/🎉', '/🎉' ),
			'encoded emoji'        => array( '/%F0%9F%8E%89', '/🎉' ),
			'encoded percent'      => array( '/100%25-cotton', '/100%-cotton' ),
			'full url'             => array( 'https://example.com/日本', '/日本' ),
			'path with query kept' => array( '/page?q=1', '/page' ),
		);
	}

	/**
	 * Test parse_encoded() leaves components percent-encoded.
	 *
	 * The lookup path depends on this: SourceUrl is the single owner of
	 * decoding, and a second decode would stop a source containing a literal
	 * '%25' ever matching.
	 *
	 * @dataProvider data_parse_encoded
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse_encoded
	 *
	 * @param string $url      The URL to parse.
	 * @param string $expected The expected path.
	 */
	public function test_parse_encoded_preserves_encoding( string $url, string $expected ): void {
		$this->assertSame( $expected, Url::parse_encoded( $url )['path'] );
	}

	/**
	 * Data provider of URLs and their still-encoded paths.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_parse_encoded(): array {
		return array(
			'ascii untouched'     => array( '/old-page', '/old-page' ),
			'encoded percent'     => array( '/100%25-cotton', '/100%25-cotton' ),
			'encoded hash'        => array( '/page%23section', '/page%23section' ),
			'encoded question'    => array( '/page%3Fnot-a-query', '/page%3Fnot-a-query' ),
			'encoded unicode'     => array( '/%E6%97%A5%E6%9C%AC', '/%E6%97%A5%E6%9C%AC' ),
			'raw unicode encoded' => array( '/日本', '/%E6%97%A5%E6%9C%AC' ),
		);
	}

	/**
	 * Test encoding is idempotent, so already-encoded input is not doubled.
	 *
	 * '%' is excluded from the set that gets encoded for exactly this reason.
	 * Were it not, a request URI arriving percent-encoded - which is the
	 * normal case - would come back as '%25E6%2597...' and match nothing.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse_encoded
	 */
	public function test_encoding_is_idempotent(): void {
		$once  = Url::parse_encoded( '/%E6%97%A5%E6%9C%AC' )['path'];
		$twice = Url::parse_encoded( $once )['path'];

		$this->assertSame( '/%E6%97%A5%E6%9C%AC', $once );
		$this->assertSame( $once, $twice );
	}

	/**
	 * Test the two methods agree once the difference in encoding is undone.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse_encoded
	 */
	public function test_parse_is_parse_encoded_decoded(): void {
		$url = 'https://example.com/日本/ページ?q=тест';

		$this->assertSame(
			Url::parse( $url )['path'],
			urldecode( Url::parse_encoded( $url )['path'] )
		);
	}

	/**
	 * Test all components come back, not just the path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 */
	public function test_parse_returns_every_component_as_a_string(): void {
		$parts = Url::parse( 'https://user:pass@example.com:8080/日本?q=1#frag' );

		$this->assertSame( 'https', $parts['scheme'] );
		$this->assertSame( 'example.com', $parts['host'] );
		$this->assertSame( '8080', $parts['port'], 'The port is normalised to a string.' );
		$this->assertSame( '/日本', $parts['path'] );
		$this->assertSame( 'q=1', $parts['query'] );
		$this->assertSame( 'frag', $parts['fragment'] );
	}

	/**
	 * Test input that is not valid UTF-8 returns null rather than mojibake.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse_encoded
	 */
	public function test_invalid_utf8_returns_null(): void {
		// Latin-1 bytes, not valid UTF-8.
		$this->assertNull( Url::parse( "/caf\xE9" ) );
		$this->assertNull( Url::parse_encoded( "/caf\xE9" ) );
	}

	/**
	 * Test a URL parse_url() rejects outright returns null.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::parse
	 */
	public function test_unparseable_url_returns_null(): void {
		$this->assertNull( Url::parse( 'http:///example.com' ) );
	}

	/**
	 * Test is_valid_utf8 separates the two reasons parsing can fail.
	 *
	 * SourceUrl uses this to report which of them happened, since Url itself
	 * returns null for both rather than raising exceptions its Application and
	 * Infrastructure callers would only have to catch.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Url::is_valid_utf8
	 */
	public function test_is_valid_utf8(): void {
		$this->assertTrue( Url::is_valid_utf8( '/日本' ) );
		$this->assertTrue( Url::is_valid_utf8( '/plain-ascii' ) );
		$this->assertTrue( Url::is_valid_utf8( '' ) );
		$this->assertFalse( Url::is_valid_utf8( "/caf\xE9" ) );
	}
}
