<?php
/**
 * Redirect request handler.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectResolver;
use Automattic\LegacyRedirector\Domain\RedirectHttpStatus;

/**
 * Performs HTTP redirects for the current front-end request.
 *
 * This is the imperative shell around RedirectResolver: it inspects the
 * current request, asks the resolver for a destination, and carries out
 * the HTTP redirect (headers, allowed hosts, exit).
 */
final class RedirectRequestHandler {

	/**
	 * The redirect resolver.
	 *
	 * @var RedirectResolver
	 */
	private RedirectResolver $resolver;

	/**
	 * Plugin name for the X-Redirect-By header.
	 *
	 * @var string
	 */
	private string $plugin_name;

	/**
	 * Constructor.
	 *
	 * @param RedirectResolver $resolver    The redirect resolver.
	 * @param string           $plugin_name Plugin name for the X-Redirect-By header.
	 */
	public function __construct( RedirectResolver $resolver, string $plugin_name = 'WPCOM Legacy Redirector' ) {
		$this->resolver    = $resolver;
		$this->plugin_name = $plugin_name;
	}

	/**
	 * Try to find and execute a redirect for the current request.
	 *
	 * This method is designed to be called from the template_redirect hook.
	 * It only processes 404 pages to avoid overhead on normal requests.
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		// Only process 404 pages - avoids overhead on every pageload.
		if ( ! is_404() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised in SourceUrl::from_string().
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( empty( $request_uri ) ) {
			return;
		}

		$redirect_data = $this->resolver->get_redirect_data( $request_uri );
		if ( null === $redirect_data ) {
			return;
		}

		$this->perform_redirect(
			$redirect_data['url'],
			$redirect_data['status_code']
		);
	}

	/**
	 * Perform the actual HTTP redirect.
	 *
	 * @param string $url         The destination URL.
	 * @param int    $status_code The HTTP status code.
	 * @return never
	 */
	private function perform_redirect( string $url, int $status_code ): void {
		// Allow redirects to external hosts by adding destination host to allowed list.
		$this->allow_redirect_host( $url );

		$this->send_cache_control_header( $url, $status_code );

		wp_safe_redirect( $url, $status_code, $this->plugin_name );
		exit;
	}

	/**
	 * Send a Cache-Control header for the redirect response.
	 *
	 * Without explicit freshness information, browsers apply heuristic
	 * caching to 301 responses, which can make a wrong redirect very
	 * sticky. An explicit max-age bounds how long a redirect is cached.
	 *
	 * @param string $url         The destination URL.
	 * @param int    $status_code The HTTP status code.
	 * @return void
	 */
	private function send_cache_control_header( string $url, int $status_code ): void {
		/**
		 * Filter the Cache-Control max-age sent with redirect responses.
		 *
		 * Defaults to one day for permanent redirects (301, 308) and one
		 * minute otherwise.
		 * Return zero or a negative number to suppress the header, e.g.
		 * where an edge cache manages redirect caching instead.
		 *
		 * @since 2.0.0
		 *
		 * @param int    $max_age     Max-age in seconds.
		 * @param string $url         The destination URL.
		 * @param int    $status_code The HTTP status code.
		 */
		$max_age = (int) apply_filters(
			'wpcom_legacy_redirector_redirect_max_age',
			RedirectHttpStatus::from( $status_code )->is_permanent() ? DAY_IN_SECONDS : MINUTE_IN_SECONDS,
			$url,
			$status_code
		);

		if ( $max_age <= 0 || headers_sent() ) {
			return;
		}

		header( 'Cache-Control: max-age=' . $max_age, true );
	}

	/**
	 * Add the destination URL's host to the allowed redirect hosts.
	 *
	 * @param string $url The destination URL.
	 * @return void
	 */
	private function allow_redirect_host( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( empty( $host ) ) {
			return;
		}

		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = $host;
				return $hosts;
			}
		);
	}
}
