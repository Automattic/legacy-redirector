<?php
/**
 * Redirect resolver service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\RedirectStatus;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Service responsible for resolving request URLs to redirect destinations.
 *
 * This is the query side of redirect handling: URL in, resolved destination
 * and status code out. It coordinates with the repository to find redirects
 * and resolves destinations to full URLs. Actually performing the HTTP
 * redirect is the responsibility of the infrastructure layer (see
 * RedirectRequestHandler).
 */
final class RedirectResolver {

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 */
	public function __construct( RedirectRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Get redirect data for a URL.
	 *
	 * @param string $url The URL to find a redirect for.
	 * @return array{url: string, status_code: int}|null Redirect data or null if not found.
	 */
	public function get_redirect_data( string $url ): ?array {
		/**
		 * Filter the request path before redirect lookup.
		 *
		 * The path is still percent-encoded; SourceUrl decodes it.
		 *
		 * @since 1.0.0
		 *
		 * @param string $path The request path, percent-encoded.
		 */
		$path = apply_filters( 'wpcom_legacy_redirector_request_path', $this->extract_path( $url ) );

		if ( empty( $path ) ) {
			return null;
		}

		// Extract preservable query params before lookup.
		$preservable_params = $this->get_preservable_params( $path );
		$lookup_path        = $this->strip_preservable_params( $path, $preservable_params );

		// Find the redirect. No home path is passed: $lookup_path is a bare
		// request path with no host, so home-path stripping can never fire,
		// and this hot path skips the home_url() lookup entirely.
		try {
			$source   = SourceUrl::from_string( $lookup_path );
			$redirect = $this->repository->find_by_source( $source );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		if ( null === $redirect ) {
			return null;
		}

		// Resolve the destination URL.
		$destination_url = $this->resolve_destination( $redirect, $preservable_params );

		/**
		 * Filter the resolved destination URL before the redirect is performed.
		 *
		 * The counterpart to `wpcom_legacy_redirector_request_path`: where that
		 * filter alters the path going into the lookup, this one alters the URL
		 * coming out of it. Returning an empty string cancels the redirect.
		 *
		 * @since 2.0.0
		 *
		 * @param string $destination_url The resolved destination URL.
		 * @param string $path            The request path used for the lookup, after filtering.
		 * @param string $url             The original, unfiltered request URL.
		 */
		$destination_url = (string) apply_filters( 'wpcom_legacy_redirector_destination_url', $destination_url, $path, $url );

		if ( empty( $destination_url ) ) {
			return null;
		}

		/**
		 * Filter the redirect status code.
		 *
		 * Values that are not a valid HTTP redirect status code (301, 302,
		 * 303, 307, 308) are replaced with the default.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $status_code The HTTP status code (default 301).
		 * @param string $url         The original request URL.
		 */
		$status_code = apply_filters( 'wpcom_legacy_redirector_redirect_status', RedirectStatus::get_default()->value, $url );
		$status      = RedirectStatus::tryFrom( (int) $status_code ) ?? RedirectStatus::get_default();

		return array(
			'url'         => $destination_url,
			'status_code' => $status->value,
		);
	}

	/**
	 * Find a redirect by source URL.
	 *
	 * @param string $url The source URL.
	 * @return Redirect|null The redirect if found.
	 */
	public function find_redirect( string $url ): ?Redirect {
		try {
			$source = SourceUrl::from_string( $url, HomePath::current() );
			return $this->repository->find_by_source( $source );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Extract the path and query string from a URL.
	 *
	 * In subdirectory multisite, strips the subsite path prefix to get
	 * the site-relative path that matches stored redirects.
	 *
	 * The URL is deliberately left percent-encoded here: SourceUrl is the
	 * single owner of decoding and normalisation, so creation and lookup
	 * stay symmetric. Decoding first would decode twice at lookup (and so
	 * miss sources containing %25), and would turn an encoded %23 or %3F
	 * into a real fragment or query delimiter before parsing.
	 *
	 * @param string $url The URL.
	 * @return string The path with optional query string.
	 */
	private function extract_path( string $url ): string {
		$url_info = wp_parse_url( $url );

		if ( ! is_array( $url_info ) || ! isset( $url_info['path'] ) ) {
			return '';
		}

		$path = $url_info['path'];

		// Strip the home path, so lookups are home-relative the same way
		// storage is. e.g. /site3/to-slug becomes /to-slug for site3, and
		// /blog/to-slug becomes /to-slug on a single site at example.com/blog.
		// The test is whether home is the domain root, not whether this is
		// multisite; a subdirectory single site needs the same treatment.
		// The '/' boundary stops '/blog' matching '/blogging-tips'.
		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
			$path = substr( $path, strlen( $home_path ) );
			// Ensure path starts with / after stripping.
			if ( '' === $path ) {
				$path = '/';
			}
		}

		if ( isset( $url_info['query'] ) ) {
			$path .= '?' . $url_info['query'];
		}

		return $path;
	}

	/**
	 * Get the preservable query parameters from a URL.
	 *
	 * @param string $url The URL with query string.
	 * @return array<string, string> Preserved parameter key-value pairs.
	 */
	private function get_preservable_params( string $url ): array {
		/**
		 * Filter the list of preservable querystring parameter keys.
		 *
		 * These parameters are stripped before lookup and re-appended to the destination.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $keys Indexed array of querystring keys to preserve.
		 * @param string   $url  The source URL.
		 */
		$keys = apply_filters( 'wpcom_legacy_redirector_preserve_query_params', array(), $url );

		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return array();
		}

		// Extract query string.
		$query_string = wp_parse_url( $url, PHP_URL_QUERY );
		if ( empty( $query_string ) ) {
			return array();
		}

		// Parse to array.
		$params = array();
		parse_str( $query_string, $params );

		// Return only the preservable keys.
		return array_intersect_key( $params, array_flip( $keys ) );
	}

	/**
	 * Strip preservable parameters from a URL for lookup.
	 *
	 * @param string                $url    The URL.
	 * @param array<string, string> $params Parameters to strip.
	 * @return string URL without the preservable parameters.
	 */
	private function strip_preservable_params( string $url, array $params ): string {
		if ( empty( $params ) ) {
			return $url;
		}

		return remove_query_arg( array_keys( $params ), $url );
	}

	/**
	 * Resolve a redirect's destination to a full URL.
	 *
	 * @param Redirect              $redirect          The redirect.
	 * @param array<string, string> $preservable_params Query params to append.
	 * @return string The resolved destination URL.
	 */
	private function resolve_destination( Redirect $redirect, array $preservable_params ): string {
		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			$url = get_permalink( $destination->as_post_id()->value() );
			if ( false === $url ) {
				return '';
			}
		} else {
			$url = $destination->as_url()->resolve( home_url() );
		}

		// Append preserved query params.
		if ( ! empty( $preservable_params ) ) {
			$url = add_query_arg( $preservable_params, $url );
		}

		return $url;
	}
}
