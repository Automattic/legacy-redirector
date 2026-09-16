<?php
/**
 * Internal destination normaliser service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Url;

/**
 * Rewrites internal destinations to one canonical stored form.
 *
 * The same internal destination can be entered as '/foo' or as
 * 'https://example.com/foo'. Storing both forms makes the list table
 * inconsistent, defeats duplicate detection, and forces every consumer that
 * distinguishes internal from external destinations to guess from the stored
 * string. Normalising to the relative form on save means anything stored
 * absolute is external by construction.
 *
 * Encoding is canonicalised as well, because '/café' and '/caf%C3%A9' are two
 * spellings of one target and used to be stored as whichever was typed. The
 * canonical form decodes the path and fragment - the parts a human reads in
 * the list table - and keeps the query percent-encoded: query values have
 * sub-structure, so decoding a literal '%26' into '&' would turn one value
 * into two parameters. WordPress re-encodes non-ASCII on the way out through
 * wp_sanitize_redirect(), so a decoded stored path still emits a valid
 * Location header.
 *
 * The scheme is deliberately ignored ('http://example.com/foo' also becomes
 * '/foo'): the redirect then follows whatever scheme the site canonically
 * serves. Host aliases such as a 'www.' variant are deliberately NOT smoothed
 * over - if they resolve to this site, that is edge configuration this plugin
 * cannot verify, and rewriting would silently change the destination when
 * they do not.
 */
final class InternalDestinationNormaliser {

	/**
	 * The last home URL parsed, and its parsed form.
	 *
	 * Keyed by the URL string so a switch_to_blog() between calls reparses,
	 * while a loop over many rows on one site (the migration dry run) does not.
	 *
	 * @var array{0: string, 1: array<string, mixed>|false}|null
	 */
	private ?array $parsed_home = null;

	/**
	 * Normalise a destination to its canonical stored form.
	 *
	 * @param Destination $destination The destination as entered.
	 * @return Destination The destination with internal URLs in canonical form.
	 */
	public function normalise( Destination $destination ): Destination {
		if ( ! $destination->is_url() ) {
			return $destination;
		}

		$value = $destination->as_url()->value();

		$path = $destination->as_url()->is_relative()
			? $this->canonicalise( $value )
			: $this->to_internal_path( $value );

		return null === $path || $path === $value
			? $destination
			: Destination::from_url( DestinationUrl::from_string( $path ) );
	}

	/**
	 * The canonical stored form of a site-relative destination.
	 *
	 * Path and fragment decoded, query kept percent-encoded, so
	 *
	 *     /caf%C3%A9?tag=caf%C3%A9#caf%C3%A9  ->  /café?tag=caf%C3%A9#café
	 *
	 * and its already-decoded twin '/café?tag=caf%C3%A9#café' come out
	 * identical: one target, one stored string, whichever spelling was typed.
	 *
	 * @param string $relative The relative destination as entered or as stored.
	 * @return string|null The canonical form, or null when it cannot be parsed.
	 */
	public function canonicalise( string $relative ): ?string {
		$parts = Url::parse_encoded( $relative );

		if ( null === $parts || ! isset( $parts['path'] ) ) {
			return null;
		}

		return $this->assemble( $parts['path'], $parts['query'] ?? null, $parts['fragment'] ?? null );
	}

	/**
	 * Rewrite an absolute URL to a site-relative path when it points at this site.
	 *
	 * @param string $url An absolute URL.
	 * @return string|null The site-relative path, or null when the URL is not internal.
	 */
	public function to_internal_path( string $url ): ?string {
		// Url::parse_encoded() rather than wp_parse_url(), which corrupts raw
		// multibyte bytes on some hosts. The components come back
		// percent-encoded; assemble() decides what gets decoded.
		$target   = Url::parse_encoded( $url );
		$home_url = home_url();

		if ( null === $this->parsed_home || $this->parsed_home[0] !== $home_url ) {
			$this->parsed_home = array( $home_url, wp_parse_url( $home_url ) );
		}
		$home = $this->parsed_home[1];

		if ( null === $target || ! is_array( $home ) ) {
			return null;
		}

		// Exact host match: a substring or suffix match would swallow
		// hosts like example.com.attacker.net. The port is compared as a
		// string because Url::parse_encoded() and wp_parse_url() disagree on
		// its type.
		if ( empty( $target['host'] ) || empty( $home['host'] )
			|| 0 !== strcasecmp( $target['host'], (string) $home['host'] )
			|| (string) ( $target['port'] ?? '' ) !== (string) ( $home['port'] ?? '' )
		) {
			return null;
		}

		// Rebuilding the URL would silently drop credentials; leave it as entered.
		if ( isset( $target['user'] ) ) {
			return null;
		}

		// Where home is not the domain root the destination must sit inside
		// this site, whether that is a subsite or a single site installed at
		// example.com/blog, so a path that is not under home is not internal.
		// The home path comes from HomePath rather than from $home['path']
		// because HomePath::current() is multibyte-safe where a bare
		// wp_parse_url() is not; the cached parse above is still what answers
		// for host and port.
		$target_path = HomePath::make_relative( $target['path'] ?? '/', HomePath::current() );

		if ( null === $target_path ) {
			return null;
		}

		return $this->assemble( $target_path, $target['query'] ?? null, $target['fragment'] ?? null );
	}

	/**
	 * Assemble the canonical relative form from percent-encoded components.
	 *
	 * The path and fragment are decoded; the query is kept as it arrived.
	 *
	 * @param string      $encoded_path The path, percent-encoded.
	 * @param string|null $query        The query string, percent-encoded, or null when absent.
	 * @param string|null $fragment     The fragment, percent-encoded, or null when absent.
	 * @return string|null The canonical relative destination, or null when the
	 *                     result would not be site-relative.
	 */
	private function assemble( string $encoded_path, ?string $query, ?string $fragment ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urldecode -- Pairs with Url::parse_encoded(); the path is stored decoded.
		$path = urldecode( '' === $encoded_path ? '/' : $encoded_path )
			. ( null !== $query ? '?' . $query : '' )
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urldecode -- Fragments have no sub-structure to protect.
			. ( null !== $fragment ? '#' . urldecode( $fragment ) : '' );

		// A '//'-prefixed result is scheme-relative, not site-relative:
		// DestinationUrl would reject it and collapsing the slashes would
		// change the URL. Leave the destination as entered. Decoding can
		// manufacture this ('/%2F%2Fx'), so the guard runs on the decoded form.
		if ( str_starts_with( $path, '//' ) ) {
			return null;
		}

		return $path;
	}
}
