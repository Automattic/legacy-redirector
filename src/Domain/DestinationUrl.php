<?php
/**
 * DestinationUrl value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use InvalidArgumentException;

/**
 * Immutable value object representing a redirect destination URL.
 *
 * Encapsulates external URLs (http://...) or relative paths (/some-path)
 * that redirects point to. Handles validation and URL resolution.
 */
final class DestinationUrl {

	/**
	 * The destination URL or path.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Whether this is a relative path (starts with /).
	 *
	 * @var bool
	 */
	private bool $is_relative;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param string $url         The destination URL.
	 * @param bool   $is_relative Whether it's a relative path.
	 */
	private function __construct( string $url, bool $is_relative ) {
		$this->url         = $url;
		$this->is_relative = $is_relative;
	}

	/**
	 * Create a DestinationUrl from a URL string.
	 *
	 * @param string $url The destination URL or path.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the URL is empty or invalid.
	 */
	public static function from_string( string $url ): self {
		$url = trim( $url );

		if ( empty( $url ) ) {
			throw new InvalidArgumentException( 'Destination URL cannot be empty.' );
		}

		// Check if it's a relative path (starts with /).
		$is_relative = str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' );

		// Validate absolute URLs have a valid scheme and a host.
		if ( ! $is_relative ) {
			// Url::parse_encoded() rather than a bare parse_url(), which
			// corrupts raw multibyte bytes on some hosts. Only the scheme and
			// host are read, so leaving the rest encoded costs nothing, and an
			// unparseable URL falls through to the scheme error below.
			$parts  = Url::parse_encoded( $url ) ?? array();
			$scheme = $parts['scheme'] ?? '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				throw new InvalidArgumentException( 'Absolute destination URLs must use http or https scheme.' );
			}

			// Reject malformed forms such as `https:/evil.com`, which browsers normalise to `https://evil.com`.
			if ( empty( $parts['host'] ) ) {
				throw new InvalidArgumentException( 'Absolute destination URLs must include a host.' );
			}
		}

		return new self( $url, $is_relative );
	}

	/**
	 * Create a DestinationUrl for the home page.
	 *
	 * @return self
	 */
	public static function home(): self {
		return new self( '/', true );
	}

	/**
	 * Get the raw URL value.
	 *
	 * @return string The URL or path.
	 */
	public function value(): string {
		return $this->url;
	}

	/**
	 * Check if this is a relative path.
	 *
	 * @return bool True if relative (starts with /).
	 */
	public function is_relative(): bool {
		return $this->is_relative;
	}

	/**
	 * Check if this is an absolute URL.
	 *
	 * @return bool True if absolute (has scheme).
	 */
	public function is_absolute(): bool {
		return ! $this->is_relative;
	}

	/**
	 * Check if this points to the home page.
	 *
	 * @return bool True if the destination is '/'.
	 */
	public function is_home(): bool {
		return '/' === $this->url;
	}

	/**
	 * Resolve the URL to an absolute URL.
	 *
	 * For relative paths, prepends the home URL.
	 * For absolute URLs, returns as-is.
	 *
	 * @param string $home_url The site's home URL (e.g., https://example.com).
	 * @return string The resolved absolute URL.
	 */
	public function resolve( string $home_url ): string {
		if ( $this->is_absolute() ) {
			return $this->url;
		}

		return rtrim( $home_url, '/' ) . $this->url;
	}

	/**
	 * Append query parameters to the destination URL.
	 *
	 * @param array<string, string> $params Query parameters to append.
	 * @return self New instance with appended parameters.
	 */
	public function with_query_params( array $params ): self {
		if ( empty( $params ) ) {
			return $this;
		}

		$separator = str_contains( $this->url, '?' ) ? '&' : '?';
		$new_url   = $this->url . $separator . http_build_query( $params );

		return new self( $new_url, $this->is_relative );
	}

	/**
	 * Check equality with another DestinationUrl.
	 *
	 * @param self $other The other DestinationUrl to compare.
	 * @return bool True if the URLs are identical.
	 */
	public function equals( self $other ): bool {
		return $this->url === $other->url;
	}

	/**
	 * String representation of the DestinationUrl.
	 *
	 * @return string The URL.
	 */
	public function __toString(): string {
		return $this->url;
	}
}
