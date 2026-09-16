<?php
/**
 * SourceUrl value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use InvalidArgumentException;

/**
 * Immutable value object representing a redirect source URL.
 *
 * Encapsulates the "from" URL for a redirect, handling normalisation,
 * validation, and hash generation. URLs are normalised to path + query
 * only (scheme and host are stripped).
 */
final class SourceUrl {

	/**
	 * The normalised URL path (and optional query string).
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * The MD5 hash of the normalised path.
	 *
	 * @var string
	 */
	private string $hash;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param string $path The normalised URL path.
	 */
	private function __construct( string $path ) {
		$this->path = $path;
		$this->hash = md5( $path );
	}

	/**
	 * Create a SourceUrl from a URL string.
	 *
	 * Accepts full URLs (with scheme/host) or paths. The URL is normalised
	 * to just the path and query string components.
	 *
	 * @param string $url       The URL or path to create a SourceUrl from.
	 * @param string $home_path The current site's home path without a trailing
	 *                          slash (e.g. '/subsite1' or '/blog'), used to
	 *                          strip that prefix from full URLs wherever home
	 *                          is not the domain root. '' means home is at the
	 *                          domain root and nothing is stripped. Callers
	 *                          with WordPress available should pass
	 *                          HomePath::current().
	 * @return self
	 *
	 * @throws InvalidArgumentException If the URL is empty, invalid, or cannot be parsed.
	 */
	public static function from_string( string $url, string $home_path = '' ): self {
		$normalised = self::normalise( $url, $home_path );
		return new self( $normalised );
	}

	/**
	 * Normalise a URL to just path and query string.
	 *
	 * Removes scheme and host from the URL, as redirects should be
	 * independent of these. Validates the URL structure.
	 *
	 * Where home is not the domain root, the site's home path is also removed,
	 * so that a source given as a full URL lands on the same home-relative
	 * path an incoming request is looked up by. See strip_home_path().
	 *
	 * @param string $url       URL to normalise.
	 * @param string $home_path The site's home path ('' when at the domain root).
	 * @return string Normalised URL (path + query).
	 *
	 * @throws InvalidArgumentException If the URL is invalid or cannot be parsed.
	 */
	private static function normalise( string $url, string $home_path ): string {
		// Ensure path starts with / before sanitisation, so a bare 'path' is
		// treated as a path rather than a schemeless domain.
		// REQUEST_URI always starts with /, so source paths must too.
		// Full URLs (http/https) are allowed - the path will be extracted below.
		$url = ltrim( $url );
		if ( '' !== $url && ! str_starts_with( $url, '/' ) && ! str_starts_with( $url, 'http' ) ) {
			$url = '/' . $url;
		}

		// Sanitise the URL.
		$url = self::sanitise_url( $url );
		if ( empty( $url ) ) {
			throw new InvalidArgumentException( 'The URL does not validate.' );
		}

		// Parse URL into components.
		$components = self::mb_parse_url( $url );

		if ( ! isset( $components['path'] ) && ! isset( $components['query'] ) ) {
			throw new InvalidArgumentException( 'The URL contains neither a path nor query string.' );
		}

		// Build normalised URL from path and optional query.
		$normalised = $components['path'] ?? '';

		// A path that arrived with a host was written from the domain root, so
		// it still carries the home path wherever home is not the root. A bare
		// request URI never does, so the guard keeps the hot path untouched.
		if ( isset( $components['host'] ) ) {
			$normalised = self::strip_home_path( $normalised, $home_path );
		}

		if ( ! empty( $components['query'] ) ) {
			$normalised .= '?' . $components['query'];
		}

		return $normalised;
	}

	/**
	 * Sanitise a URL for storage, in pure PHP.
	 *
	 * A recreation of WordPress's esc_url_raw() - esc_url( $url, null, 'db' )
	 * - specialised to the inputs this value object produces: a non-empty
	 * input here always starts with '/' or 'http' (see normalise()).
	 *
	 * Output must stay byte-identical to esc_url_raw() for stored sources:
	 * the md5 of the normalised path is the persisted lookup key
	 * (post_name), so any drift here orphans existing redirects.
	 *
	 * Deliberate differences from core, none of which change the output for
	 * an input core would accept:
	 * - the `clean_url` filter is not applied;
	 * - non-http(s) schemes are rejected outright rather than laundered
	 *   through wp_kses_bad_protocol();
	 * - a colonless non-path input is rejected instead of gaining an
	 *   'http://' prefix (normalise() has already prefixed '/' onto any
	 *   input that could take that branch);
	 * - the mailto: exemption from CRLF stripping is dropped (a source can
	 *   never be a mailto: link).
	 *
	 * @param string $url The URL to sanitise (already ltrimmed).
	 * @return string The sanitised URL, or '' if nothing usable remains.
	 */
	private static function sanitise_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$url = str_replace( ' ', '%20', $url );
		$url = (string) preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );

		if ( '' === $url ) {
			return '';
		}

		// Strip percent-encoded line breaks until none remain, as core's
		// _deep_replace() does, so '%0%0dd' cannot reassemble into one.
		$count = 1;
		while ( $count > 0 ) {
			$url = str_replace( array( '%0d', '%0a', '%0D', '%0A' ), '', $url, $count );
		}

		$url = str_replace( ';//', '://', $url );

		// Square brackets are only valid in the authority (IPv6 hosts), so
		// core percent-encodes any appearing after it.
		if ( str_contains( $url, '[' ) || str_contains( $url, ']' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure PHP keeps the domain layer WordPress-free.
			$parts = parse_url( $url );
			$parts = false === $parts ? array() : $parts;

			$front = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : ( str_starts_with( $url, '/' ) ? '//' : '' );
			if ( isset( $parts['user'] ) ) {
				$front .= $parts['user'];
			}
			if ( isset( $parts['pass'] ) ) {
				$front .= ':' . $parts['pass'];
			}
			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				$front .= '@';
			}
			$front .= ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

			$end_dirty = str_replace( $front, '', $url );
			$end_clean = str_replace( array( '[', ']' ), array( '%5B', '%5D' ), $end_dirty );
			$url       = str_replace( $end_dirty, $end_clean, $url );
		}

		if ( str_starts_with( $url, '/' ) ) {
			return $url;
		}

		// Core's wp_kses_bad_protocol() also lowercases the scheme.
		if ( 1 !== preg_match( '#^(https?)://#i', $url, $matches ) ) {
			return '';
		}

		return strtolower( $matches[1] ) . substr( $url, strlen( $matches[1] ) );
	}

	/**
	 * Remove the site's home path from the front of a path.
	 *
	 * Sources are stored relative to the current site's home URL, not to the
	 * domain root: on a subsite at /subsite1, the stored '/old-page' means
	 * example.com/subsite1/old-page, and RedirectResolver::extract_path()
	 * strips '/subsite1' from every incoming request to match. A full URL
	 * pasted into the admin form, handed to `wp wpcom-legacy-redirector
	 * create`, or read from a CSV import carries that prefix, so it has to
	 * come off here or the redirect is saved under a key no request produces.
	 *
	 * The host is deliberately not checked against the site's own. Sources are
	 * matched by path alone, so a stored path that keeps the prefix cannot
	 * match anything whatever host it came from.
	 *
	 * No-op wherever home is the domain root and the home path is '', so there
	 * is nothing to remove. That is most single sites and every subdomain
	 * multisite, but not a single site installed at example.com/blog, which
	 * needs stripping exactly as a subsite does.
	 *
	 * @param string $path      The path component of a full URL.
	 * @param string $home_path The site's home path, injected by the caller
	 *                          because the domain layer cannot ask WordPress.
	 * @return string The path relative to this site's home URL.
	 */
	private static function strip_home_path( string $path, string $home_path ): string {
		$home_path = rtrim( $home_path, '/' );

		if ( '' === $home_path ) {
			return $path;
		}

		if ( $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
			return $path;
		}

		$stripped = substr( $path, strlen( $home_path ) );

		return '' === $stripped ? '/' : $stripped;
	}

	/**
	 * UTF-8 aware parse_url().
	 *
	 * Percent-encodes multibyte characters (except reserved URL characters)
	 * before parsing, then decodes the resulting components, so URLs such as
	 * /فوتوغرافيا/?test=فوتوغرافيا parse correctly.
	 *
	 * Deliberately uses PHP's parse_url() rather than wp_parse_url() so the
	 * domain layer stays free of WordPress dependencies; on PHP >= 5.4.7 the
	 * two are equivalent for the path and full-URL inputs this receives.
	 *
	 * @param string $url The URL to parse.
	 * @return array<string, string|int> The URL components.
	 *
	 * @throws InvalidArgumentException If the URL is malformed.
	 */
	private static function mb_parse_url( string $url ): array {
		$encoded_url = preg_replace_callback(
			'|[^!*\'();:@&=+$,\/?%#\[\]]+|usD',
			static function ( array $matches ): string {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Required for proper percent-encoding of UTF-8 chars.
				return urlencode( $matches[0] );
			},
			$url
		);

		// The /u modifier makes preg_replace_callback() return null for a
		// subject that is not valid UTF-8 (or that blows the backtrack limit).
		if ( null === $encoded_url ) {
			throw new InvalidArgumentException( 'The URL is not valid UTF-8.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure PHP keeps the domain layer WordPress-free.
		$parts = parse_url( $encoded_url );

		if ( false === $parts ) {
			throw new InvalidArgumentException( 'The URL could not be parsed.' );
		}

		foreach ( $parts as $name => $value ) {
			$parts[ $name ] = urldecode( (string) $value );
		}

		return $parts;
	}

	/**
	 * Get the normalised path (including query string if present).
	 *
	 * @return string The normalised URL path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Get the MD5 hash of the normalised path.
	 *
	 * This hash is used as the post_name for redirect posts,
	 * enabling fast indexed lookups.
	 *
	 * @return string The MD5 hash.
	 */
	public function hash(): string {
		return $this->hash;
	}

	/**
	 * Check equality with another SourceUrl.
	 *
	 * @param self $other The other SourceUrl to compare.
	 * @return bool True if the paths are identical.
	 */
	public function equals( self $other ): bool {
		return $this->path === $other->path;
	}

	/**
	 * String representation of the SourceUrl.
	 *
	 * @return string The normalised path.
	 */
	public function __toString(): string {
		return $this->path;
	}
}
