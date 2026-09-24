<?php
/**
 * SourceUrl value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use InvalidArgumentException;
use Yoast\WPTestUtils\BrainMonkey\YoastTestCase;

/**
 * SourceUrlTest class.
 *
 * SourceUrl is pure PHP, so these tests need no WordPress function stubs:
 * the subsite home path is passed in rather than read from home_url().
 *
 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class SourceUrlTest extends YoastTestCase {

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
	 * Test from_string normalizes full URL to path only.
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
	 * Test a full URL on a subsite loses the subsite prefix.
	 *
	 * Stored sources are relative to the site's home URL, and the resolver
	 * strips the subsite prefix from every request before looking one up. A
	 * full URL that kept the prefix would be saved under a key no request can
	 * produce, so the redirect would silently never fire.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_subsite_home_path(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite1/old-page', '/subsite1' );

		$this->assertSame( '/old-page', $source->path() );
	}

	/**
	 * Test the subsite home path is stripped once, not everywhere it appears.
	 *
	 * On a subsite at /subsite1, the real URL example.com/subsite1/subsite1/x
	 * must be stored as /subsite1/x. Only the leading prefix comes off.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_only_the_leading_subsite_home_path(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite1/subsite1/old-page', '/subsite1' );

		$this->assertSame( '/subsite1/old-page', $source->path() );
	}

	/**
	 * Test a full URL for the subsite's own home page normalizes to '/'.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_subsite_home_url_becomes_root(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite1/', '/subsite1' );

		$this->assertSame( '/', $source->path() );
	}

	/**
	 * Test a path that merely resembles the subsite prefix is left alone.
	 *
	 * '/subsite10' is not inside '/subsite1', so a naive prefix match would
	 * corrupt it into '0'.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_does_not_strip_partial_segment_match(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite10/old-page', '/subsite1' );

		$this->assertSame( '/subsite10/old-page', $source->path() );
	}

	/**
	 * Test a bare request path is never stripped, whatever the home path.
	 *
	 * The resolver has already removed the prefix by the time a request path
	 * reaches here, so stripping again would mangle the hot path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_leaves_hostless_path_untouched_on_subsite(): void {
		$source = SourceUrl::from_string( '/subsite1/old-page', '/subsite1' );

		$this->assertSame( '/subsite1/old-page', $source->path() );
	}

	/**
	 * Test the query string survives subsite prefix stripping.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_subsite_home_path_and_keeps_query(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite1/old-page?foo=bar', '/subsite1' );

		$this->assertSame( '/old-page?foo=bar', $source->path() );
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
		$source = SourceUrl::from_string( '/فوتوغرافيا' );

		$this->assertSame( '/فوتوغرافيا', $source->path() );
	}

	/**
	 * Test from_string handles unicode with query string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_handles_unicode_with_query(): void {
		$source = SourceUrl::from_string( '/فوتوغرافيا?test=فوتوغرافيا' );

		$this->assertSame( '/فوتوغرافيا?test=فوتوغرافيا', $source->path() );
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
	}

	/**
	 * Test path with only query string (edge case).
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_path_with_root_and_query(): void {
		$source = SourceUrl::from_string( '/?foo=bar' );

		$this->assertSame( '/?foo=bar', $source->path() );
	}

	/**
	 * Test invalid UTF-8 input throws instead of silently parsing to an empty path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_rejects_invalid_utf8(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The URL is not valid UTF-8.' );

		// Latin-1 bytes, not valid UTF-8.
		SourceUrl::from_string( "/caf\xE9" );
	}

	/**
	 * Test characters outside the URL-safe set are stripped.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_disallowed_characters(): void {
		$source = SourceUrl::from_string( '/pa"ge<b>' );

		$this->assertSame( '/pageb', $source->path() );
	}

	/**
	 * Test percent-encoded line breaks are removed, even when nested.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_encoded_line_breaks(): void {
		$source = SourceUrl::from_string( '/page%0D%0A?a=b' );

		$this->assertSame( '/page?a=b', $source->path() );

		// Stripping one layer must not reassemble another (%0%0dd -> %0d).
		$nested = SourceUrl::from_string( '/page%0%0dd' );

		$this->assertSame( '/page', $nested->path() );
	}

	/**
	 * Test a non-http(s) scheme is rejected.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_rejects_non_http_scheme(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The URL does not validate.' );

		SourceUrl::from_string( 'httpfoo://example.com/old-page' );
	}

	/**
	 * Test a percent-encoded path normalizes identically to its decoded form.
	 *
	 * Sources arrive both ways (a browser-copied URL is encoded, a hand-typed
	 * one is not) and must land on the same stored hash to match at all.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_normalizes_encoded_and_decoded_forms_identically(): void {
		$encoded = SourceUrl::from_string( '/my%20page' );
		$decoded = SourceUrl::from_string( '/my page' );

		$this->assertSame( $decoded->path(), $encoded->path() );
		$this->assertSame( $decoded->hash(), $encoded->hash() );
	}
	/**
	 * Test non-ASCII paths survive normalization across scripts and planes.
	 *
	 * The sanitiser's character class keeps the \x80-\xff byte range, which is
	 * the only reason any of this works; a narrowing of that class would strip
	 * every source below to its ASCII skeleton and orphan the redirect.
	 *
	 * @dataProvider data_unicode_paths
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 *
	 * @param string $path The unicode path.
	 */
	public function test_from_string_preserves_unicode_path( string $path ): void {
		$source = SourceUrl::from_string( $path );

		$this->assertSame( $path, $source->path() );
	}

	/**
	 * Data provider of unicode paths that must round-trip unchanged.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_unicode_paths(): array {
		return array(
			'Arabic (RTL)'          => array( '/فوتوغرافيا' ),
			'Arabic with query'     => array( '/فوتوغرافيا?test=فوتوغرافيا' ),
			'Cyrillic'              => array( '/привет-мир' ),
			'Cyrillic with query'   => array( '/страница?тест=значение' ),
			'Japanese'              => array( '/JP納豆' ),
			'Greek'                 => array( '/καλημέρα' ),
			'Hebrew (RTL)'          => array( '/שלום-עולם' ),
			'Latin with diacritics' => array( '/café-münchen' ),
			'emoji (astral plane)'  => array( '/party-🎉' ),
			'emoji only'            => array( '/🎉' ),
			'emoji in query'        => array( '/page?mood=🎉' ),
			'mixed scripts'         => array( '/привет-納豆-🎉' ),
		);
	}

	/**
	 * Test a percent-encoded unicode path hashes the same as its decoded form.
	 *
	 * A browser sends /%D0%BF..., an admin pastes /при... . Both must land on
	 * the same md5 or the redirect created in the admin can never be matched
	 * by a real request. Astral-plane characters are included because they
	 * encode to four bytes, not two or three.
	 *
	 * @dataProvider data_encoded_and_decoded_unicode
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::hash
	 *
	 * @param string $encoded The percent-encoded form, as a browser sends it.
	 * @param string $decoded The decoded form, as an admin would type it.
	 */
	public function test_unicode_encoded_and_decoded_forms_hash_identically( string $encoded, string $decoded ): void {
		$from_request = SourceUrl::from_string( $encoded );
		$from_admin   = SourceUrl::from_string( $decoded );

		$this->assertSame( $from_admin->path(), $from_request->path() );
		$this->assertSame( $from_admin->hash(), $from_request->hash() );
	}

	/**
	 * Data provider of percent-encoded unicode paths and their decoded forms.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_encoded_and_decoded_unicode(): array {
		return array(
			'Arabic'                          => array( '/%D9%81%D9%88%D8%AA%D9%88/', '/فوتو/' ),
			'Cyrillic'                        => array( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/привет' ),
			'Japanese'                        => array( '/%E7%B4%8D%E8%B1%86', '/納豆' ),
			'emoji (4 bytes)'                 => array( '/%F0%9F%8E%89', '/🎉' ),
			'Cyrillic in query'               => array( '/page?q=%D1%82%D0%B5%D1%81%D1%82', '/page?q=тест' ),
			'emoji in query'                  => array( '/page?q=%F0%9F%8E%89', '/page?q=🎉' ),
			// Both canonicalizations at once, in opposite directions. The rows
			// above differ only in encoding, so slash stripping could be
			// skipped for percent-encoded input and they would still pass.
			'encoded slashed vs decoded bare' => array( '/%D9%81%D9%88%D8%AA%D9%88/', '/فوتو' ),
			'encoded bare vs decoded slashed' => array( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/привет/' ),
			'astral slashed vs bare'          => array( '/%F0%9F%8E%89/', '/🎉' ),
		);
	}

	/**
	 * Test the two Unicode normalization forms of the same glyph do not match.
	 *
	 * The hash is an md5 of the raw bytes, so precomposed 'é' (U+00E9) and
	 * decomposed 'e' + U+0301 render identically but store and look up under
	 * different keys. macOS filesystems hand out NFD while nearly everything
	 * else uses NFC, so a source pasted from a Mac Finder path can silently
	 * fail to match the same-looking URL a browser requests.
	 *
	 * This pins the current behavior rather than endorsing it: fixing it
	 * would mean normalizing to NFC before hashing, which rewrites every
	 * stored hash and so belongs to a migration, not to this value object.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::hash
	 */
	public function test_nfc_and_nfd_forms_are_not_treated_as_equal(): void {
		$nfc = SourceUrl::from_string( "/caf\xC3\xA9" );
		$nfd = SourceUrl::from_string( "/cafe\xCC\x81" );

		$this->assertNotSame( $nfc->path(), $nfd->path() );
		$this->assertNotSame( $nfc->hash(), $nfd->hash() );
		$this->assertFalse( $nfc->equals( $nfd ) );
	}

	/**
	 * Test a unicode path under an ASCII subsite prefix is stripped correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_ascii_home_path_from_unicode_path(): void {
		$source = SourceUrl::from_string( 'https://example.com/subsite1/日本語', '/subsite1' );

		$this->assertSame( '/日本語', $source->path() );
	}

	/**
	 * Test a unicode home path is stripped from a unicode path.
	 *
	 * Stripping compares and slices bytes, not characters, so a
	 * multibyte prefix only works because both sides have already been decoded
	 * to the same UTF-8 bytes by mb_parse_url(). A substr() on a character
	 * count here would cut a multibyte sequence in half.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_unicode_home_path(): void {
		$source = SourceUrl::from_string( 'https://example.com/日本/ページ', '/日本' );

		$this->assertSame( '/ページ', $source->path() );
	}

	/**
	 * Test a percent-encoded full URL still matches a decoded unicode home path.
	 *
	 * The home path arrives decoded (home_url() is not percent-encoded) while
	 * a copied browser URL arrives encoded. Stripping happens after
	 * mb_parse_url() has decoded the path, so the two forms meet.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_unicode_home_path_from_encoded_url(): void {
		$source = SourceUrl::from_string( 'https://example.com/%E6%97%A5%E6%9C%AC/%E3%83%9A%E3%83%BC%E3%82%B8', '/日本' );

		$this->assertSame( '/ページ', $source->path() );
	}

	/**
	 * Test a unicode subsite's own home URL normalizes to '/'.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_unicode_subsite_home_url_becomes_root(): void {
		$source = SourceUrl::from_string( 'https://example.com/日本/', '/日本' );

		$this->assertSame( '/', $source->path() );
	}

	/**
	 * Test a unicode path that merely shares a prefix is left alone.
	 *
	 * '/日本語' is not inside '/日本', so a byte-prefix match without the '/'
	 * boundary would corrupt it into '語'.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_does_not_strip_partial_unicode_segment_match(): void {
		$source = SourceUrl::from_string( 'https://example.com/日本語/ページ', '/日本' );

		$this->assertSame( '/日本語/ページ', $source->path() );
	}

	/**
	 * Test a unicode query string survives unicode home path stripping.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_unicode_home_path_and_keeps_unicode_query(): void {
		$source = SourceUrl::from_string( 'https://example.com/日本/ページ?тест=да', '/日本' );

		$this->assertSame( '/ページ?тест=да', $source->path() );
	}

	/**
	 * Test the unicode home path is stripped once, not everywhere it appears.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_strips_only_the_leading_unicode_home_path(): void {
		$source = SourceUrl::from_string( 'https://example.com/日本/日本/ページ', '/日本' );

		$this->assertSame( '/日本/ページ', $source->path() );
	}

	/**
	 * Test a bare unicode request path is never stripped, whatever the home path.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_leaves_hostless_unicode_path_untouched(): void {
		$source = SourceUrl::from_string( '/日本/ページ', '/日本' );

		$this->assertSame( '/日本/ページ', $source->path() );
	}
	/**
	 * Test a trailing slash is removed from the source path.
	 *
	 * @dataProvider data_trailing_slash_paths
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 *
	 * @param string $input    The source as entered or requested.
	 * @param string $expected The canonical stored path.
	 */
	public function test_from_string_strips_trailing_slash( string $input, string $expected ): void {
		$source = SourceUrl::from_string( $input );

		$this->assertSame( $expected, $source->path() );
	}

	/**
	 * Data provider of sources and their trailing-slash-canonical form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_trailing_slash_paths(): array {
		return array(
			'trailing slash removed'      => array( '/old-page/', '/old-page' ),
			'already canonical'           => array( '/old-page', '/old-page' ),
			'nested path'                 => array( '/2019/04/old-page/', '/2019/04/old-page' ),
			'repeated trailing slashes'   => array( '/old-page///', '/old-page' ),
			'root is preserved'           => array( '/', '/' ),
			'query is kept, slash is not' => array( '/old-page/?utm_source=x', '/old-page?utm_source=x' ),
			'query-only source'           => array( '/?p=123', '/?p=123' ),
			'full URL'                    => array( 'https://example.com/old-page/', '/old-page' ),
			'unicode path'                => array( '/café/', '/café' ),
			// rtrim() works on bytes, so this pins that it cannot eat into a
			// multibyte character: every UTF-8 continuation byte is >= 0x80
			// and so can never be the 0x2F it is looking for.
			'astral path'                 => array( '/party-🎉/', '/party-🎉' ),
		);
	}

	/**
	 * Test every spelling of one source converges on a single hash.
	 *
	 * The hash is the stored post_name, so this is what makes /old-page and
	 * /old-page/ one redirect rather than two rival rows.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::hash
	 */
	public function test_trailing_slash_spellings_hash_identically(): void {
		$canonical = SourceUrl::from_string( '/old-page' );

		$spellings = array(
			'/old-page/',
			'https://example.com/old-page',
			'https://example.com/old-page/',
		);

		foreach ( $spellings as $spelling ) {
			$this->assertSame(
				$canonical->hash(),
				SourceUrl::from_string( $spelling )->hash(),
				$spelling . ' should hash as /old-page'
			);
		}
	}

	/**
	 * Test a subsite's own home URL with a trailing slash still becomes root.
	 *
	 * Home-path stripping leaves '/', which must survive slash removal rather
	 * than being emptied.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_from_string_subsite_home_url_with_slash_becomes_root(): void {
		$source = SourceUrl::from_string( 'https://example.com/blog/', '/blog' );

		$this->assertSame( '/', $source->path() );
	}

	/**
	 * Test paths WordPress itself serves are reported as reserved.
	 *
	 * @dataProvider data_reserved_sources
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::is_reserved
	 *
	 * @param string $source   The source as entered.
	 * @param bool   $reserved Whether it is reserved.
	 */
	public function test_is_reserved( string $source, bool $reserved ): void {
		$this->assertSame( $reserved, SourceUrl::from_string( $source )->is_reserved() );
	}

	/**
	 * Data provider for test_is_reserved.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function data_reserved_sources(): array {
		return array(
			'admin'                      => array( '/wp-admin/', true ),
			'admin screen'               => array( '/wp-admin/edit.php?post_type=page', true ),
			'login'                      => array( '/wp-login.php', true ),
			'login with query'           => array( '/wp-login.php?action=lostpassword', true ),
			'cron'                       => array( '/wp-cron.php', true ),
			'xmlrpc'                     => array( '/xmlrpc.php', true ),
			'rest route'                 => array( '/wp-json/wp/v2/posts', true ),
			'content'                    => array( '/wp-content/uploads/a.jpg', true ),
			'includes'                   => array( '/wp-includes/js/x.js', true ),
			'full url'                   => array( 'https://example.com/wp-admin', true ),
			'robots'                     => array( '/robots.txt', true ),
			'favicon'                    => array( '/favicon.ico', true ),
			'sitemap index'              => array( '/wp-sitemap.xml', true ),
			'sitemap page'               => array( '/wp-sitemap-posts-post-1.xml', true ),
			'sitemap stylesheet'         => array( '/wp-sitemap.xsl', true ),
			'feed'                       => array( '/feed/', true ),
			'atom feed'                  => array( '/feed/atom/', true ),
			'ordinary page'              => array( '/old-page', false ),
			'lookalike prefix'           => array( '/wp-administration', false ),
			'nested wp file'             => array( '/blog/wp-login.php', false ),
			'nested feed'                => array( '/category/news/feed/', false ),
			'feed lookalike'             => array( '/feedback', false ),
			'reserved text in the query' => array( '/search?q=/wp-admin', false ),
			'root'                       => array( '/', false ),
		);
	}
	/**
	 * Test a '+' is a literal plus in the path and a space in the query.
	 *
	 * WordPress serves '/tag/one+two/' (posts with both tags) but not
	 * '/tag/one two/', so reading the path's '+' as a space made a different
	 * source out of it. Only a form-encoded query gives '+' that meaning.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 */
	public function test_plus_is_literal_in_the_path_and_a_space_in_the_query(): void {
		$this->assertSame( '/tag/one+two', SourceUrl::from_string( '/tag/one+two/' )->path() );
		$this->assertFalse( SourceUrl::from_string( '/a+b' )->equals( SourceUrl::from_string( '/a%20b' ) ) );
		$this->assertTrue( SourceUrl::from_string( '/a+b' )->equals( SourceUrl::from_string( '/a%2Bb' ) ) );
		$this->assertSame( '/p?q=a b', SourceUrl::from_string( '/p?q=a+b' )->path() );
	}

	/**
	 * Test a normalized source normalizes to itself.
	 *
	 * The stored path is fed back through here whenever a redirect is edited
	 * and on every migration walk, and its md5 is the lookup key. A path that
	 * moved on a second pass would lose its key: '/a%2541' used to become
	 * '/a%41', then '/aA'.
	 *
	 * @dataProvider data_sources_to_renormalize
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\SourceUrl::from_string
	 *
	 * @param string $url The source as typed or requested.
	 */
	public function test_normalized_source_is_a_fixed_point( string $url ): void {
		$once = SourceUrl::from_string( $url )->path();

		$this->assertSame( $once, SourceUrl::from_string( $once )->path() );
	}

	/**
	 * Data provider for test_normalized_source_is_a_fixed_point.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_sources_to_renormalize(): array {
		return array(
			'encoded unicode'        => array( '/caf%C3%A9' ),
			'double-encoded escape'  => array( '/a%2541' ),
			'stray percent'          => array( '/100%' ),
			'encoded slash'          => array( '/a%2Fb' ),
			'encoded question mark'  => array( '/a%3Fb' ),
			'encoded hash'           => array( '/a%23b' ),
			'encoded brace'          => array( '/a%7Bb' ),
			'encoded square bracket' => array( '/a%5Bb%5D' ),
			'encoded quote'          => array( '/a%22b' ),
			'encoded control'        => array( '/a%01b' ),
			'invalid UTF-8'          => array( '/%FF' ),
			'plus in the path'       => array( '/a+b' ),
			'space in the path'      => array( '/a%20b' ),
			'plus in the query'      => array( '/p?q=a+b' ),
			'encoded plus in query'  => array( '/p?q=c%2B%2B' ),
			'encoded ampersand'      => array( '/p?q=a%26b' ),
			'encoded equals'         => array( '/p?q=a%3Db' ),
			'encoded hash in query'  => array( '/p?q=a%23b' ),
			'encoded slash in query' => array( '/p?u=%2Fx%2F' ),
			'unicode in query'       => array( '/p?q=caf%C3%A9' ),
			'lower-case escapes'     => array( '/a%2fb%c3%a9' ),
			'full url'               => array( 'https://example.com/caf%C3%A9/?q=a+b' ),
		);
	}
}
