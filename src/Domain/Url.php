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
	 * Punctuation that stands for itself in a path, and so is decoded there.
	 *
	 * RFC 3986's sub-delimiters, ':' and '@', none of which delimit anything
	 * inside a path segment - a literal '+' included, which is only a space in
	 * a form-encoded query - plus '|', which SourceUrl's sanitizing keeps.
	 * ';' is the exception: SourceUrl's sanitizing, like esc_url_raw(),
	 * rewrites ';//' to '://', so a decoded '%3B//' would change on the next
	 * pass. Anything else stays encoded; see decode().
	 */
	public const string PATH_TEXT = "-._~!$&'()*+,=:@|";

	/**
	 * Punctuation that stands for itself in a query, and so is decoded there.
	 *
	 * As PATH_TEXT, less the '&', '=' and '+' that separate parameters,
	 * pair keys with values and stand for spaces in a form-encoded query,
	 * plus the '/' and '?' that have no special meaning once inside one.
	 */
	public const string QUERY_TEXT = "-._~!$'()*,:@/?|";

	/**
	 * Parse a URL, returning its components percent-decoded.
	 *
	 * For callers that want the components as characters - a value object
	 * normalizing a path, or a comparison against something already decoded.
	 *
	 * Only the escapes that stand for plain text are decoded; see decode().
	 * A '+' is a literal plus in the path, as RFC 3986 has it, and a space
	 * only in the query, where form encoding puts it.
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
			$parts[ $name ] = match ( $name ) {
				'path'     => self::decode( $value, self::PATH_TEXT ),
				'query'    => self::decode( str_replace( '+', '%20', $value ), self::QUERY_TEXT ),
				'fragment' => self::decode( $value, self::PATH_TEXT . '/?' ),
				default    => rawurldecode( $value ),
			};
		}

		return $parts;
	}

	/**
	 * Decode the percent-escapes that stand for plain text, and keep the rest.
	 *
	 * Letters, digits, spaces, valid UTF-8 beyond ASCII and the punctuation
	 * in $text are decoded. Every other escape is kept, in upper case so one
	 * value still has one spelling: those are the ones whose decoded form
	 * would mean something else ('%2F' splits a path segment, '%3F' and '%23'
	 * start a query or fragment, '%25' is decoded again by the next pass), or
	 * that could not be stored, such as control characters and bytes that
	 * are not valid UTF-8. A '%' that starts no escape is encoded as '%25',
	 * so that it and its encoded form are one spelling too.
	 *
	 * The result is a fixed point: parsing and decoding it again changes
	 * nothing. Stored values are re-canonicalized on every migration walk and
	 * every edit, so a decode that drifted would move them a step further
	 * each time - '/a%2541' once became '/a%41', then '/aA'.
	 *
	 * @param string $encoded A percent-encoded URL component.
	 * @param string $text    Punctuation that stands for itself in this component.
	 * @return string The component with its plain-text escapes decoded.
	 */
	public static function decode( string $encoded, string $text ): string {
		return (string) preg_replace_callback(
			'/(?:%[0-9A-Fa-f]{2})+/',
			static function ( array $escapes ) use ( $text ): string {
				// One token per valid UTF-8 character, or per stray byte.
				preg_match_all(
					'/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|./s',
					rawurldecode( $escapes[0] ),
					$characters
				);

				$decoded = '';
				foreach ( $characters[0] as $character ) {
					$plain    = strlen( $character ) > 1 || 1 === preg_match( '/^[A-Za-z0-9 ]$/', $character ) || str_contains( $text, $character );
					$decoded .= $plain ? $character : sprintf( '%%%02X', ord( $character ) );
				}

				return $decoded;
			},
			(string) preg_replace( '/%(?![0-9A-Fa-f]{2})/', '%25', $encoded )
		);
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
				// rawurlencode(), not urlencode(): a raw space must come out as
				// '%20', or it could not be told apart from a literal '+'.
				return rawurlencode( $matches[0] );
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
