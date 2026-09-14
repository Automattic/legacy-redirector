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
		$this->assertTrue( $destination->is_home() );
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
	 * Test is_home returns true for root path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_home
	 */
	public function test_is_home_for_root_path(): void {
		$destination = DestinationUrl::from_string( '/' );

		$this->assertTrue( $destination->is_home() );
	}

	/**
	 * Test is_home returns false for other paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::is_home
	 */
	public function test_is_home_for_other_path(): void {
		$destination = DestinationUrl::from_string( '/page' );

		$this->assertFalse( $destination->is_home() );
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
	 * Test with_query_params appends parameters.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_appends(): void {
		$destination = DestinationUrl::from_string( '/page' );
		$result      = $destination->with_query_params( array( 'foo' => 'bar' ) );

		$this->assertSame( '/page?foo=bar', $result->value() );
	}

	/**
	 * Test with_query_params appends to existing query.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_appends_to_existing(): void {
		$destination = DestinationUrl::from_string( '/page?existing=value' );
		$result      = $destination->with_query_params( array( 'foo' => 'bar' ) );

		$this->assertSame( '/page?existing=value&foo=bar', $result->value() );
	}

	/**
	 * Test with_query_params returns same instance for empty params.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_empty_returns_same(): void {
		$destination = DestinationUrl::from_string( '/page' );
		$result      = $destination->with_query_params( array() );

		$this->assertSame( $destination, $result );
	}

	/**
	 * Test with_query_params is immutable.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_immutable(): void {
		$destination = DestinationUrl::from_string( '/page' );
		$destination->with_query_params( array( 'foo' => 'bar' ) );

		$this->assertSame( '/page', $destination->value() );
	}

	/**
	 * Test equals returns true for same URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::equals
	 */
	public function test_equals_same_url(): void {
		$destination1 = DestinationUrl::from_string( '/page' );
		$destination2 = DestinationUrl::from_string( '/page' );

		$this->assertTrue( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test equals returns false for different URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::equals
	 */
	public function test_equals_different_url(): void {
		$destination1 = DestinationUrl::from_string( '/page-one' );
		$destination2 = DestinationUrl::from_string( '/page-two' );

		$this->assertFalse( $destination1->equals( $destination2 ) );
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
	 * Browsers normalise `Location: https:/evil.com` to `https://evil.com`, so
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

	// =========================================================================
	// Edge Cases: Query Parameter Handling
	// =========================================================================

	/**
	 * Test with_query_params handles multiple params.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_multiple(): void {
		$destination = DestinationUrl::from_string( '/page' );
		$result      = $destination->with_query_params(
			array(
				'utm_source'   => 'test',
				'utm_medium'   => 'email',
				'utm_campaign' => 'launch',
			)
		);

		$this->assertStringContainsString( 'utm_source=test', $result->value() );
		$this->assertStringContainsString( 'utm_medium=email', $result->value() );
		$this->assertStringContainsString( 'utm_campaign=launch', $result->value() );
	}

	/**
	 * Test with_query_params handles special characters in values.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationUrl::with_query_params
	 */
	public function test_with_query_params_special_chars(): void {
		$destination = DestinationUrl::from_string( '/page' );
		$result      = $destination->with_query_params( array( 'redirect' => 'https://example.com/path' ) );

		// Value should be URL-encoded.
		$this->assertStringContainsString( 'redirect=', $result->value() );
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
}
