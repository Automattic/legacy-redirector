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
	 * @param string $url The URL or path to create a SourceUrl from.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the URL is empty, invalid, or cannot be parsed.
	 */
	public static function from_string( string $url ): self {
		$normalised = self::normalise( $url );
		return new self( $normalised );
	}

	/**
	 * Normalise a URL to just path and query string.
	 *
	 * Removes scheme and host from the URL, as redirects should be
	 * independent of these. Validates the URL structure.
	 *
	 * On a subdirectory multisite the site's home path is also removed, so
	 * that a source given as a full URL lands on the same subsite-relative
	 * path an incoming request is looked up by. See strip_home_path().
	 *
	 * @param string $url URL to normalise.
	 * @return string Normalised URL (path + query).
	 *
	 * @throws InvalidArgumentException If the URL is invalid or cannot be parsed.
	 */
	private static function normalise( string $url ): string {
		// Ensure path starts with / before sanitisation.
		// Without this, esc_url_raw('path') becomes 'http://path' (treated as domain).
		// REQUEST_URI always starts with /, so source paths must too.
		// Full URLs (http/https) are allowed - the path will be extracted below.
		$url = ltrim( $url );
		if ( '' !== $url && ! str_starts_with( $url, '/' ) && ! str_starts_with( $url, 'http' ) ) {
			$url = '/' . $url;
		}

		// Sanitise the URL.
		$url = esc_url_raw( $url );
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

		// A path that arrived with a host was written network-absolute, so on a
		// subsite it still carries the subsite prefix. A bare request URI never
		// does, so the guard keeps the request hot path untouched.
		if ( isset( $components['host'] ) ) {
			$normalised = self::strip_home_path( $normalised );
		}

		if ( ! empty( $components['query'] ) ) {
			$normalised .= '?' . $components['query'];
		}

		return $normalised;
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
	 * No-op on single sites and subdomain multisites, where the home path is
	 * '/' and there is nothing to remove.
	 *
	 * @param string $path The path component of a full URL.
	 * @return string The path relative to this site's home URL.
	 *
	 * @throws InvalidArgumentException If the site's home URL cannot be parsed.
	 */
	private static function strip_home_path( string $path ): string {
		$home_path = rtrim( (string) ( self::mb_parse_url( home_url() )['path'] ?? '' ), '/' );

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
	 * Get the path without query string parameters.
	 *
	 * @return string The path component only.
	 */
	public function path_without_query(): string {
		$pos = strpos( $this->path, '?' );
		if ( false === $pos ) {
			return $this->path;
		}
		return substr( $this->path, 0, $pos );
	}

	/**
	 * Get the query string (without leading ?).
	 *
	 * @return string The query string, or empty string if none.
	 */
	public function query_string(): string {
		$pos = strpos( $this->path, '?' );
		if ( false === $pos ) {
			return '';
		}
		return substr( $this->path, $pos + 1 );
	}

	/**
	 * Create a new SourceUrl with specific query parameters removed.
	 *
	 * Used for stripping preservable query parameters before hash lookup.
	 *
	 * @param array<string> $keys Query parameter keys to remove.
	 * @return self New SourceUrl instance without the specified parameters.
	 */
	public function without_query_params( array $keys ): self {
		if ( empty( $keys ) ) {
			return $this;
		}

		$query = $this->query_string();
		if ( empty( $query ) ) {
			return $this;
		}

		// Parse query string into array.
		parse_str( $query, $params );

		// Remove specified keys.
		foreach ( $keys as $key ) {
			unset( $params[ $key ] );
		}

		// Rebuild URL.
		$base_path = $this->path_without_query();
		if ( empty( $params ) ) {
			return new self( $base_path );
		}

		return new self( $base_path . '?' . http_build_query( $params ) );
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
