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

/**
 * Rewrites absolute destination URLs that point at this site to relative paths.
 *
 * The same internal destination can be entered as '/foo' or as
 * 'https://example.com/foo'. Storing both forms makes the list table
 * inconsistent, defeats duplicate detection, and forces every consumer that
 * distinguishes internal from external destinations to guess from the stored
 * string. Normalising to the relative form on save means anything stored
 * absolute is external by construction.
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
	 * @return Destination The destination with internal absolute URLs made relative.
	 */
	public function normalise( Destination $destination ): Destination {
		if ( ! $destination->is_url() || $destination->as_url()->is_relative() ) {
			return $destination;
		}

		$path = $this->to_internal_path( $destination->as_url()->value() );

		return null === $path
			? $destination
			: Destination::from_url( DestinationUrl::from_string( $path ) );
	}

	/**
	 * Rewrite an absolute URL to a site-relative path when it points at this site.
	 *
	 * @param string $url An absolute URL.
	 * @return string|null The site-relative path, or null when the URL is not internal.
	 */
	public function to_internal_path( string $url ): ?string {
		$target   = wp_parse_url( $url );
		$home_url = home_url();

		if ( null === $this->parsed_home || $this->parsed_home[0] !== $home_url ) {
			$this->parsed_home = array( $home_url, wp_parse_url( $home_url ) );
		}
		$home = $this->parsed_home[1];

		if ( ! is_array( $target ) || ! is_array( $home ) ) {
			return null;
		}

		// Exact host match: a substring or suffix match would swallow
		// hosts like example.com.attacker.net.
		if ( empty( $target['host'] ) || empty( $home['host'] )
			|| strtolower( $target['host'] ) !== strtolower( $home['host'] )
			|| ( $target['port'] ?? null ) !== ( $home['port'] ?? null )
		) {
			return null;
		}

		// Rebuilding the URL would silently drop credentials; leave it as entered.
		if ( isset( $target['user'] ) ) {
			return null;
		}

		$home_path   = rtrim( $home['path'] ?? '', '/' );
		$target_path = $target['path'] ?? '/';

		// On a subdirectory multisite the destination must sit inside this
		// subsite; the '/' boundary stops '/sub1' matching '/sub10/foo'.
		if ( '' !== $home_path ) {
			if ( $target_path !== $home_path && ! str_starts_with( $target_path, $home_path . '/' ) ) {
				return null;
			}
			$target_path = substr( $target_path, strlen( $home_path ) );
		}

		$path = ( '' === $target_path ? '/' : $target_path )
			. ( isset( $target['query'] ) ? '?' . $target['query'] : '' )
			. ( isset( $target['fragment'] ) ? '#' . $target['fragment'] : '' );

		// A '//'-prefixed result is scheme-relative, not site-relative:
		// DestinationUrl would reject it and collapsing the slashes would
		// change the URL. Leave the destination as entered.
		if ( str_starts_with( $path, '//' ) ) {
			return null;
		}

		return $path;
	}
}
