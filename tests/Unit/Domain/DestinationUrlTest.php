<?php
/**
 * DestinationUrl value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use InvalidArgumentException;

/**
 * DestinationUrlTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class DestinationUrlTest extends MonkeyStubs {

	/**
	 * Test from_string creates valid DestinationUrl from absolute URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_with_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://example.com/page' );

		$this->assertSame( 'https://example.com/page', $destination->value() );
	}

	/**
	 * Test from_string creates valid DestinationUrl from relative path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_with_relative_path(): void {
		$destination = DestinationUrl::from_string( '/some-page' );

		$this->assertSame( '/some-page', $destination->value() );
	}

	/**
	 * Test from_string trims whitespace.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_trims_whitespace(): void {
		$destination = DestinationUrl::from_string( '  /page  ' );

		$this->assertSame( '/page', $destination->value() );
	}

	/**
	 * Test from_string throws for empty URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_throws_for_empty_url(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Destination URL cannot be empty.' );

		DestinationUrl::from_string( '' );
	}

	/**
	 * Test from_string throws for invalid scheme.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_throws_for_invalid_scheme(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Absolute destination URLs must use http or https scheme.' );

		DestinationUrl::from_string( 'ftp://example.com/file' );
	}

	/**
	 * Test home factory creates root path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::home
	 */
	public function test_home_creates_root_path(): void {
		$destination = DestinationUrl::home();

		$this->assertSame( '/', $destination->value() );
	}

	/**
	 * Test is_relative returns true for relative paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_relative
	 */
	public function test_is_relative_for_relative_path(): void {
		$destination = DestinationUrl::from_string( '/page' );

		$this->assertTrue( $destination->is_relative() );
	}

	/**
	 * Test is_relative returns false for absolute URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_relative
	 */
	public function test_is_relative_for_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://example.com/page' );

		$this->assertFalse( $destination->is_relative() );
	}

	/**
	 * Test is_absolute returns true for absolute URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_absolute
	 */
	public function test_is_absolute_for_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://example.com' );

		$this->assertTrue( $destination->is_absolute() );
	}

	/**
	 * Test is_absolute returns false for relative paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_absolute
	 */
	public function test_is_absolute_for_relative_path(): void {
		$destination = DestinationUrl::from_string( '/page' );

		$this->assertFalse( $destination->is_absolute() );
	}

	/**
	 * Test resolve returns absolute URL as-is.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://external.com/page' );

		$this->assertSame( 'https://external.com/page', $destination->resolve( 'https://example.com' ) );
	}

	/**
	 * Test resolve prepends home URL for relative paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_relative_path(): void {
		$destination = DestinationUrl::from_string( '/page' );

		$this->assertSame( 'https://example.com/page', $destination->resolve( 'https://example.com' ) );
	}

	/**
	 * Test resolve handles home URL with trailing slash.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_handles_trailing_slash(): void {
		$destination = DestinationUrl::from_string( '/page' );

		$this->assertSame( 'https://example.com/page', $destination->resolve( 'https://example.com/' ) );
	}

	/**
	 * Test __toString returns URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::__toString
	 */
	public function test_to_string(): void {
		$destination = DestinationUrl::from_string( 'https://example.com/page' );

		$this->assertSame( 'https://example.com/page', (string) $destination );
	}

	/**
	 * Test HTTP scheme is accepted.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_http_scheme_accepted(): void {
		$destination = DestinationUrl::from_string( 'http://example.com/page' );

		$this->assertSame( 'http://example.com/page', $destination->value() );
	}

	/**
	 * Test protocol-relative URLs are rejected.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_protocol_relative_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		DestinationUrl::from_string( '//example.com/page' );
	}

	/**
	 * Test single-slash scheme URLs are rejected.
	 *
	 * Browsers normalize `Location: https:/evil.com` to `https://evil.com`, so
	 * these must not pass validation with a null host.
	 *
	 * @dataProvider data_single_slash_scheme_urls
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 *
	 * @param string $url URL to reject.
	 */
	public function test_single_slash_scheme_rejected( string $url ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Absolute destination URLs must include a host.' );

		DestinationUrl::from_string( $url );
	}

	/**
	 * Data provider for single-slash scheme URLs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_single_slash_scheme_urls(): array {
		return array(
			'https single slash' => array( 'https:/evil.com' ),
			'http single slash'  => array( 'http:/evil.com/path' ),
			'scheme only'        => array( 'https:' ),
			'no slashes'         => array( 'https:evil.com' ),
		);
	}

	/**
	 * Test absolute URL with query params preserved through resolve.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_preserves_query_on_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://external.com/page?existing=value' );

		$this->assertSame( 'https://external.com/page?existing=value', $destination->resolve( 'https://example.com' ) );
	}

	/**
	 * Test relative path with query params resolved correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_preserves_query_on_relative_path(): void {
		$destination = DestinationUrl::from_string( '/page?foo=bar' );

		$this->assertSame( 'https://example.com/page?foo=bar', $destination->resolve( 'https://example.com' ) );
	}
	/**
	 * Test unicode destinations round-trip unchanged.
	 *
	 * Unlike SourceUrl, DestinationUrl does not sanitise: the value is stored
	 * and later emitted in a Location header verbatim, so anything lost here
	 * is lost from the redirect itself.
	 *
	 * @dataProvider data_unicode_destinations
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::value
	 *
	 * @param string $url The unicode destination.
	 */
	public function test_from_string_preserves_unicode( string $url ): void {
		$destination = DestinationUrl::from_string( $url );

		$this->assertSame( $url, $destination->value() );
		$this->assertSame( $url, (string) $destination );
	}

	/**
	 * Data provider of unicode destinations.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_unicode_destinations(): array {
		return array(
			'relative Arabic'     => array( '/فوتوغرافيا/' ),
			'relative Cyrillic'   => array( '/привет-мир/' ),
			'relative Japanese'   => array( '/納豆' ),
			'relative emoji'      => array( '/party-🎉' ),
			'relative with query' => array( '/страница?тест=значение' ),
			'absolute Cyrillic'   => array( 'https://example.com/привет' ),
			'absolute emoji'      => array( 'https://example.com/🎉' ),
			'percent-encoded'     => array( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82' ),
		);
	}

	/**
	 * Test a unicode relative path is still recognized as relative.
	 *
	 * The leading-slash test is a byte comparison, so a multibyte first
	 * character must not confuse it into treating the path as absolute (which
	 * would skip the home URL and emit a hostless Location header).
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_relative
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_absolute
	 */
	public function test_unicode_relative_path_is_relative(): void {
		$destination = DestinationUrl::from_string( '/日本語' );

		$this->assertTrue( $destination->is_relative() );
		$this->assertFalse( $destination->is_absolute() );
	}

	/**
	 * Test a unicode relative path resolves against the home URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_unicode_relative_path(): void {
		$destination = DestinationUrl::from_string( '/привет-мир/' );

		$this->assertSame( 'https://example.com/привет-мир/', $destination->resolve( 'https://example.com/' ) );
	}

	/**
	 * Test a unicode home URL is joined without losing or doubling the slash.
	 *
	 * The join rtrim()s on bytes; a multibyte final character must survive it.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_against_unicode_home_url(): void {
		$destination = DestinationUrl::from_string( '/ページ' );

		$this->assertSame( 'https://example.com/日本/ページ', $destination->resolve( 'https://example.com/日本/' ) );
	}

	/**
	 * Test a unicode absolute URL resolves to itself.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::resolve
	 */
	public function test_resolve_unicode_absolute_url(): void {
		$destination = DestinationUrl::from_string( 'https://example.com/🎉' );

		$this->assertSame( 'https://example.com/🎉', $destination->resolve( 'https://other.test' ) );
	}

	/**
	 * Test a unicode string without a scheme is still rejected.
	 *
	 * Non-ASCII must not become a way past the scheme check, or a destination
	 * could be stored that no browser will follow.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::from_string
	 */
	public function test_from_string_rejects_unicode_without_scheme(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Absolute destination URLs must use http or https scheme.' );

		DestinationUrl::from_string( 'привет-мир' );
	}
}
