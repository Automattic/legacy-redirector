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
 * Encapsulates the "from" URL for a redirect, handling normalization,
 * validation, and hash generation. URLs are normalized to path + query
 * only (scheme and host are stripped), with any trailing slash removed from
 * the path so that /old-page and /old-page/ are the same redirect.
 */
final class SourceUrl {

	/**
	 * The normalized URL path (and optional query string).
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * The MD5 hash of the normalized path.
	 *
	 * @var string
	 */
	private string $hash;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param string $path The normalized URL path.
	 */
	private function __construct( string $path ) {
		$this->path = $path;
		$this->hash = md5( $path );
	}

	/**
	 * Create a SourceUrl from a URL string.
	 *
	 * Accepts full URLs (with scheme/host) or paths. The URL is normalized
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
		$normalized = self::normalize( $url, $home_path );
		return new self( $normalized );
	}

	/**
	 * Normalize a URL to just path and query string.
	 *
	 * Removes scheme and host from the URL, as redirects should be
	 * independent of these. Validates the URL structure.
	 *
	 * Where home is not the domain root, the site's home path is also removed,
	 * so that a source given as a full URL lands on the same home-relative
	 * path an incoming request is looked up by. See strip_home_path().
	 *
	 * Any trailing slash comes off the path as well, so that /old-page and
	 * /old-page/ are one redirect rather than two. See strip_trailing_slash().
	 *
	 * @param string $url       URL to normalize.
	 * @param string $home_path The site's home path ('' when at the domain root).
	 * @return string Normalized URL (path + query).
	 *
	 * @throws InvalidArgumentException If the URL is invalid or cannot be parsed.
	 */
	private static function normalize( string $url, string $home_path ): string {
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

		// Build normalized URL from path and optional query. A full URL with no
		// path, 'http://example.com?p=1', is requested as '/?p=1'.
		$normalized = $components['path'] ?? ( isset( $components['host'] ) ? '/' : '' );

		// A path that arrived with a host was written from the domain root, so
		// it still carries the home path wherever home is not the root. A bare
		// request URI never does, so the guard keeps the hot path untouched.
		if ( isset( $components['host'] ) ) {
			$normalized = self::strip_home_path( $normalized, $home_path );
		}

		// A path cannot start with '//' without being read as a host the next
		// time round ('http://example.com//x' has the path '//x'), and no
		// request produces one either: the parser takes it as a host there too.
		$normalized = (string) preg_replace( '#^//+#', '/', $normalized );

		$normalized = self::strip_trailing_slash( $normalized );

		if ( ! empty( $components['query'] ) ) {
			$normalized .= '?' . $components['query'];
		}

		// Sanitizing encodes square brackets, except where core's quirk of
		// matching on '//' leaves them raw; a second pass would encode them,
		// so they are encoded here to keep one source one spelling.
		$normalized = str_replace( array( '[', ']' ), array( '%5B', '%5D' ), $normalized );

		// Likewise sanitizing's ';//' rule, which decoding can set up (a raw ';'
		// before an encoded '//' in the query).
		$normalized = str_replace( ';//', '://', $normalized );

		return $normalized;
	}

	/**
	 * Sanitise a URL for storage, in pure PHP.
	 *
	 * A recreation of WordPress's esc_url_raw() - esc_url( $url, null, 'db' )
	 * - specialized to the inputs this value object produces: a non-empty
	 * input here always starts with '/' or 'http' (see normalize()).
	 *
	 * Output must stay byte-identical to esc_url_raw() for stored sources:
	 * the md5 of the normalized path is the persisted lookup key
	 * (post_name), so any drift here orphans existing redirects.
	 *
	 * Deliberate differences from core, none of which change the output for
	 * an input core would accept:
	 * - the `clean_url` filter is not applied;
	 * - non-http(s) schemes are rejected outright rather than laundered
	 *   through wp_kses_bad_protocol();
	 * - a colonless non-path input is rejected instead of gaining an
	 *   'http://' prefix (normalize() has already prefixed '/' onto any
	 *   input that could take that branch);
	 * - the mailto: exemption from CRLF stripping is dropped (a source can
	 *   never be a mailto: link).
	 *
	 * Public because the resolver runs a request through it before parsing,
	 * as 1.x did: characters this strips when they arrive raw would otherwise
	 * be percent-encoded by the parser first, and survive. 1.x stripped them
	 * on both sides of its lookup, so '/page?filter={all}' stored as
	 * '/page?filter=all' and matched the request a browser sends for it.
	 *
	 * @param string $url The URL to sanitise (already ltrimmed).
	 * @return string The sanitised URL, or '' if nothing usable remains.
	 */
	public static function sanitise_url( string $url ): string {
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
	 * pasted into the admin form, handed to `wp legacy-redirector
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
	 * Remove a trailing slash from the front-end path.
	 *
	 * A trailing slash is not significant in a source: /old-page and
	 * /old-page/ are one redirect, not two. Whichever form a visitor's old
	 * link happens to carry, they want the same destination, and WordPress
	 * itself picks one form and redirects the other - which form depending on
	 * whether the site's permalink structure ends in a slash.
	 *
	 * Canonicalizing here rather than trying both forms at lookup time is what
	 * makes the md5 of this path a single key per source: one stored row, one
	 * query, and no way to create /old-page and /old-page/ as rival redirects
	 * with different destinations.
	 *
	 * Deliberately independent of the site's permalink structure. The source
	 * is a URL from a site that no longer exists, so this site's convention
	 * says nothing about it - and keying on a mutable option would orphan
	 * every stored redirect the moment somebody edited it.
	 *
	 * Destinations are untouched by this: a trailing slash there is part of
	 * where the visitor actually lands.
	 *
	 * Public because the upgrade routine has to re-derive the same canonical
	 * form for already-stored sources, and the rule must have exactly one
	 * definition or migrated rows drift from newly saved ones.
	 *
	 * @param string $path The path component, after home-path stripping.
	 * @return string The path without its trailing slash, or '/' for the site root.
	 */
	public static function strip_trailing_slash( string $path ): string {
		// No path at all is left alone rather than given one; normalize() has
		// already given a full URL the '/' a browser requests it with.
		if ( '' === $path ) {
			return $path;
		}

		$trimmed = rtrim( $path, '/' );

		// The root is all slash, so trimming empties it. '' is not a path.
		return '' === $trimmed ? '/' : $trimmed;
	}

	/**
	 * Parse a URL into decoded components, or explain why it cannot be.
	 *
	 * Url::parse() is UTF-8 safe where a bare parse_url() is not, so URLs such
	 * as /فوتوغرافيا/?test=فوتوغرافيا survive. It reports failure by returning
	 * null; this value object's contract is to throw, so the two reasons it
	 * can fail are told apart here.
	 *
	 * @param string $url The URL to parse.
	 * @return array<string, string> The URL components.
	 *
	 * @throws InvalidArgumentException If the URL is malformed.
	 */
	private static function mb_parse_url( string $url ): array {
		$parts = Url::parse( $url );

		if ( null === $parts ) {
			throw new InvalidArgumentException(
				Url::is_valid_utf8( $url )
					? 'The URL could not be parsed.'
					: 'The URL is not valid UTF-8.'
			);
		}

		return $parts;
	}

	/**
	 * Get the normalized path (including query string if present).
	 *
	 * @return string The normalized URL path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Get the MD5 hash of the normalized path.
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
	 * Whether this source is a path WordPress itself serves.
	 *
	 * Covers the admin, login and other root-level wp-*.php files,
	 * xmlrpc.php, and everything beneath wp-admin, wp-json, wp-content and
	 * wp-includes. The resolver only runs on a 404, so a redirect here lies
	 * dormant while the path works - and answers it the moment the path
	 * breaks, which for wp-admin or wp-login.php locks the admin out.
	 *
	 * Also covers paths core answers before the 404 decision is ever made:
	 * robots.txt and favicon.ico (served virtually when no file exists), the
	 * wp-sitemap files, and the site feeds. A redirect from those is not
	 * merely dormant - it can never fire at all.
	 *
	 * Such a source is still allowed: a site migrated from another platform
	 * can have real legacy URLs here. Callers warn rather than refuse.
	 *
	 * @return bool True if the path is reserved by WordPress.
	 */
	public function is_reserved(): bool {
		$path = substr( $this->path, 0, strcspn( $this->path, '?' ) );

		return 1 === preg_match( '#^/(?:xmlrpc\.php|robots\.txt|favicon\.ico|wp-[^/]*\.php|wp-sitemap[^/]*\.(?:xml|xsl)|feed(?:/.*)?|wp-(?:admin|json|content|includes)(?:/.*)?)$#', $path );
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
	 * @return string The normalized path.
	 */
	public function __toString(): string {
		return $this->path;
	}
}
