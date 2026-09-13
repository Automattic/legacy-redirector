<?php
/**
 * SourceUrl value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use InvalidArgumentException;

/**
 * SourceUrlTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class SourceUrlTest extends MonkeyStubs {

	/**
	 * Test from_string creates valid SourceUrl from path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_with_simple_path(): void {
		$source = SourceUrl::from_string( '/test-page' );

		$this->assertSame( '/test-page', $source->path() );
	}

	/**
	 * Test from_string normalises full URL to path only.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_scheme_and_host(): void {
		$source = SourceUrl::from_string( 'https://example.com/test-page' );

		$this->assertSame( '/test-page', $source->path() );
	}

	/**
	 * Test from_string preserves query string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_preserves_query_string(): void {
		$source = SourceUrl::from_string( '/test-page?foo=bar&baz=qux' );

		$this->assertSame( '/test-page?foo=bar&baz=qux', $source->path() );
	}

	/**
	 * Test from_string with full URL preserves query string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_full_url_preserves_query(): void {
		$source = SourceUrl::from_string( 'https://example.com/page?utm_source=test' );

		$this->assertSame( '/page?utm_source=test', $source->path() );
	}

	/**
	 * Test hash returns consistent MD5.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::hash
	 */
	public function test_hash_returns_md5_of_path(): void {
		$source = SourceUrl::from_string( '/test-page' );

		$this->assertSame( md5( '/test-page' ), $source->hash() );
	}

	/**
	 * Test hash is consistent for same path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::hash
	 */
	public function test_hash_is_consistent(): void {
		$source1 = SourceUrl::from_string( '/test-page' );
		$source2 = SourceUrl::from_string( '/test-page' );

		$this->assertSame( $source1->hash(), $source2->hash() );
	}

	/**
	 * Test path_without_query returns path only.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::path_without_query
	 */
	public function test_path_without_query(): void {
		$source = SourceUrl::from_string( '/test-page?foo=bar' );

		$this->assertSame( '/test-page', $source->path_without_query() );
	}

	/**
	 * Test path_without_query with no query returns full path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::path_without_query
	 */
	public function test_path_without_query_when_no_query(): void {
		$source = SourceUrl::from_string( '/test-page' );

		$this->assertSame( '/test-page', $source->path_without_query() );
	}

	/**
	 * Test query_string returns query without question mark.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::query_string
	 */
	public function test_query_string(): void {
		$source = SourceUrl::from_string( '/test-page?foo=bar&baz=qux' );

		$this->assertSame( 'foo=bar&baz=qux', $source->query_string() );
	}

	/**
	 * Test query_string returns empty string when no query.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::query_string
	 */
	public function test_query_string_when_no_query(): void {
		$source = SourceUrl::from_string( '/test-page' );

		$this->assertSame( '', $source->query_string() );
	}

	/**
	 * Test without_query_params removes specified params.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_removes_params(): void {
		$source = SourceUrl::from_string( '/page?foo=bar&utm_source=test&baz=qux' );
		$result = $source->without_query_params( array( 'utm_source' ) );

		$this->assertSame( '/page?foo=bar&baz=qux', $result->path() );
	}

	/**
	 * Test without_query_params returns same instance when no keys.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_empty_keys_returns_same(): void {
		$source = SourceUrl::from_string( '/page?foo=bar' );
		$result = $source->without_query_params( array() );

		$this->assertSame( $source, $result );
	}

	/**
	 * Test without_query_params does not modify original.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_immutable(): void {
		$source = SourceUrl::from_string( '/page?foo=bar&baz=qux' );
		$source->without_query_params( array( 'foo' ) );

		$this->assertSame( '/page?foo=bar&baz=qux', $source->path() );
	}

	/**
	 * Test equals returns true for same path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::equals
	 */
	public function test_equals_same_path(): void {
		$source1 = SourceUrl::from_string( '/test-page' );
		$source2 = SourceUrl::from_string( '/test-page' );

		$this->assertTrue( $source1->equals( $source2 ) );
	}

	/**
	 * Test equals returns false for different paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::equals
	 */
	public function test_equals_different_path(): void {
		$source1 = SourceUrl::from_string( '/page-one' );
		$source2 = SourceUrl::from_string( '/page-two' );

		$this->assertFalse( $source1->equals( $source2 ) );
	}

	/**
	 * Test __toString returns path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::__toString
	 */
	public function test_to_string(): void {
		$source = SourceUrl::from_string( '/test-page?foo=bar' );

		$this->assertSame( '/test-page?foo=bar', (string) $source );
	}

	/**
	 * Test from_string throws exception for empty URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_throws_for_empty_url(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The URL does not validate.' );

		SourceUrl::from_string( '' );
	}

	/**
	 * Test from_string handles unicode paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_handles_unicode(): void {
		$source = SourceUrl::from_string( '/فوتوغرافيا/' );

		$this->assertSame( '/فوتوغرافيا/', $source->path() );
	}

	/**
	 * Test from_string handles unicode with query string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_handles_unicode_with_query(): void {
		$source = SourceUrl::from_string( '/فوتوغرافيا/?test=فوتوغرافيا' );

		$this->assertSame( '/فوتوغرافيا/?test=فوتوغرافيا', $source->path() );
	}

	// =========================================================================
	// Edge Cases: Query Parameter Handling
	// =========================================================================

	/**
	 * Test without_query_params handles multiple params to remove.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_removes_multiple_params(): void {
		$source = SourceUrl::from_string( '/page?utm_source=test&foo=bar&utm_medium=email&baz=qux' );
		$result = $source->without_query_params( array( 'utm_source', 'utm_medium' ) );

		$this->assertSame( '/page?foo=bar&baz=qux', $result->path() );
	}

	/**
	 * Test without_query_params handles params not in URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_ignores_missing_params(): void {
		$source = SourceUrl::from_string( '/page?foo=bar' );
		$result = $source->without_query_params( array( 'nonexistent' ) );

		$this->assertSame( '/page?foo=bar', $result->path() );
	}

	/**
	 * Test without_query_params handles removing all params.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_removes_all_params(): void {
		$source = SourceUrl::from_string( '/page?foo=bar&baz=qux' );
		$result = $source->without_query_params( array( 'foo', 'baz' ) );

		$this->assertSame( '/page', $result->path() );
	}

	/**
	 * Test without_query_params handles URL with no query string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::without_query_params
	 */
	public function test_without_query_params_handles_no_query(): void {
		$source = SourceUrl::from_string( '/page' );
		$result = $source->without_query_params( array( 'foo' ) );

		$this->assertSame( '/page', $result->path() );
	}

	/**
	 * Test query params with special characters are URL-decoded.
	 *
	 * SourceUrl normalizes by decoding URL-encoded characters.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_query_params_with_special_characters(): void {
		$source = SourceUrl::from_string( '/page?redirect=https%3A%2F%2Fexample.com&name=John+Doe' );

		// URL-encoded values are decoded by SourceUrl.
		$this->assertSame( '/page?redirect=https://example.com&name=John Doe', $source->path() );
		$this->assertSame( 'redirect=https://example.com&name=John Doe', $source->query_string() );
	}

	/**
	 * Test query params with empty value.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_query_params_with_empty_value(): void {
		$source = SourceUrl::from_string( '/page?flag=&other=value' );

		$this->assertSame( '/page?flag=&other=value', $source->path() );
	}

	/**
	 * Test query param key without value (flag-style).
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_query_param_flag_style(): void {
		$source = SourceUrl::from_string( '/page?debug&verbose' );

		$this->assertSame( '/page?debug&verbose', $source->path() );
		$this->assertSame( 'debug&verbose', $source->query_string() );
	}

	/**
	 * Test path with only query string (edge case).
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_path_with_root_and_query(): void {
		$source = SourceUrl::from_string( '/?foo=bar' );

		$this->assertSame( '/?foo=bar', $source->path() );
		$this->assertSame( '/', $source->path_without_query() );
		$this->assertSame( 'foo=bar', $source->query_string() );
	}
}
