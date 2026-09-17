<?php
/**
 * Home path provider.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Url;

/**
 * Supplies the current site's home path for SourceUrl normalization.
 *
 * SourceUrl lives in the Domain layer, which cannot ask WordPress where
 * home is, so the home path is passed into SourceUrl::from_string() by its
 * callers instead. This is the lowest layer allowed to know (the
 * Application layer is deliberately WordPress-coupled; see AGENTS.md).
 */
final class HomePath {

	/**
	 * The current site's home path, without a trailing slash.
	 *
	 * '' wherever home is at the domain root and there is nothing to strip,
	 * which covers most single sites and every subdomain multisite. Non-empty
	 * wherever it is not: '/subsite1' on a subdirectory multisite subsite,
	 * and equally '/blog' on a plain single site installed at
	 * example.com/blog. Multisite is not the deciding factor.
	 *
	 * Returned decoded, so that a home path containing non-ASCII characters is
	 * in the same form as the paths make_relative() compares it against.
	 *
	 * @return string The home path.
	 */
	public static function current(): string {
		// Url::parse() rather than wp_parse_url(), because a home path of
		// '/日本' comes back as '/日_日_' from a bare parse_url() on hosts
		// whose LC_CTYPE flags the C1 range as control characters. A home URL
		// that will not parse at all leaves no path to strip.
		$parts = Url::parse( home_url() );

		return rtrim( $parts['path'] ?? '', '/' );
	}

	/**
	 * Rebase a path onto the site's home path.
	 *
	 * Sources are stored relative to home, so every path that arrives carrying
	 * the home prefix has to have it taken off before it can match: a request
	 * for '/blog/old-page' on a site at example.com/blog looks up '/old-page'.
	 *
	 * The comparison runs segment by segment, and each segment is compared
	 * decoded. Both matter:
	 *
	 * - Comparing whole segments is what holds the boundary, so '/blog' cannot
	 *   swallow the start of '/blogging-tips' and leave '/ging-tips'.
	 * - Comparing them decoded is what lets the two encodings of one path
	 *   meet. home_url() returns the home path decoded, while a browser
	 *   percent-encodes anything non-ASCII in the path it requests. For an
	 *   ASCII home path the two forms are identical and a byte comparison
	 *   would do; for '/日本' they are not, and a byte comparison silently
	 *   fails to strip.
	 *
	 * What is returned keeps the encoding it arrived in: SourceUrl is the
	 * single owner of decoding, and decoding here as well would decode twice.
	 *
	 * @param string $path      The path to rebase, in whatever encoding it arrived in.
	 * @param string $home_path The site's home path, as current() returns it.
	 * @return string|null The home-relative path, or null when $path is not under home.
	 */
	public static function make_relative( string $path, string $home_path ): ?string {
		$home_path = rtrim( $home_path, '/' );

		// Home is the domain root, so every path is already relative to it.
		if ( '' === $home_path ) {
			return $path;
		}

		// Both start with '/', so both lists start with an empty first segment.
		$home_segments = explode( '/', $home_path );
		$path_segments = explode( '/', $path );

		foreach ( $home_segments as $index => $home_segment ) {
			if ( ! isset( $path_segments[ $index ] )
				|| rawurldecode( $path_segments[ $index ] ) !== rawurldecode( $home_segment )
			) {
				return null;
			}
		}

		$remainder = implode( '/', array_slice( $path_segments, count( $home_segments ) ) );

		// The home page itself, which is stored as the root path.
		return '' === $remainder ? '/' : '/' . $remainder;
	}
}
