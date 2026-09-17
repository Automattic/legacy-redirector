<?php
/**
 * UTF-8 safe URL parsing.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Parses URLs without letting PHP's parse_url() corrupt multibyte characters.
 *
 * PHP's parse_url() runs php_replace_controlchars_ex(), which rewrites every
 * byte iscntrl() reports true for to '_'. Under glibc in the C locale it is
 * false for everything above 0x7F and nothing happens, but under a UTF-8
 * LC_CTYPE (macOS libc, among others) it is true for 0x80-0x9F - which is
 * ordinary continuation-byte territory in UTF-8. A path of '/日本' comes back
 * as '/日_日_', silently, and only on some hosts:
 *
 *     parse_url( '/日本', PHP_URL_PATH )
 *     // C locale:      2fe697a5e69cac
 *     // UTF-8 locale:  2fe65fa5e65fac
 *
 * Percent-encoding the multibyte characters before parsing puts them out of
 * that function's reach, which is what both methods here do. Anything reading
 * a path or query out of a URL should come through here; a bare parse_url()
 * for those is a latent platform-dependent bug. Reading only the host or
 * scheme is safe either way, since neither can hold the affected bytes, so
 * those call sites are deliberately left on wp_parse_url().
 *
 * Deliberately pure PHP rather than wp_parse_url(), so the Domain layer stays
 * free of WordPress. For the inputs this receives the two are equivalent.
 */
final class Url {

	/**
	 * Characters that must not be percent-encoded before parsing.
	 *
	 * The URL delimiters parse_url() needs to see, plus '%' so that input
	 * which is already percent-encoded passes through rather than being
	 * double-encoded. That is what makes encoding idempotent, and so safe to
	 * apply to a request URI that may arrive in either form.
	 */
	private const string RESERVED = '|[^!*\'();:@&=+$,\/?%#\[\]]+|usD';

	/**
	 * Parse a URL, returning its components percent-decoded.
	 *
	 * For callers that want the components as characters - a value object
	 * normalizing a path, or a comparison against something already decoded.
	 *
	 * @param string $url The URL to parse.
	 * @return array<string, string>|null The components, or null when the URL
	 *                                    is not valid UTF-8 or cannot be parsed.
	 */
	public static function parse( string $url ): ?array {
		$parts = self::parse_encoded( $url );

		if ( null === $parts ) {
			return null;
		}

		foreach ( $parts as $name => $value ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Pairs with the urlencode() in parse_encoded(); rawurldecode() would not restore '+'.
			$parts[ $name ] = urldecode( $value );
		}

		return $parts;
	}

	/**
	 * Parse a URL, leaving its components percent-encoded.
	 *
	 * For callers that must not decode: the redirect lookup path hands its
	 * result to SourceUrl, which is the single owner of decoding, so decoding
	 * here as well would decode twice and a source containing a literal '%25'
	 * could never match.
	 *
	 * Note the components come back encoded even where the input was not, so
	 * a raw '/日本' is returned as '/%E6%97%A5%E6%9C%AC'. Both forms decode to
	 * the same characters, which is all the callers downstream require.
	 *
	 * @param string $url The URL to parse.
	 * @return array<string, string>|null The components, or null when the URL
	 *                                    is not valid UTF-8 or cannot be parsed.
	 */
	public static function parse_encoded( string $url ): ?array {
		$encoded = preg_replace_callback(
			self::RESERVED,
			static function ( array $matches ): string {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Required to percent-encode UTF-8 characters; the matching urldecode() is in parse().
				return urlencode( $matches[0] );
			},
			$url
		);

		// The /u modifier makes preg_replace_callback() return null for a
		// subject that is not valid UTF-8, or that blows the backtrack limit.
		if ( null === $encoded ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure PHP keeps the domain layer WordPress-free.
		$parts = parse_url( $encoded );

		if ( false === $parts ) {
			return null;
		}

		return array_map( 'strval', $parts );
	}

	/**
	 * Whether a string is valid UTF-8.
	 *
	 * Lets a caller tell the two reasons parse() returns null apart, without
	 * this class having to raise exceptions its non-Domain callers would only
	 * have to catch.
	 *
	 * @param string $value The string to check.
	 * @return bool True when the string is valid UTF-8.
	 */
	public static function is_valid_utf8( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}
}
