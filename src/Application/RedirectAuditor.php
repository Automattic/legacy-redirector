<?php
/**
 * Redirect auditor service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * The single place that works out what is wrong with a redirect.
 *
 * Describes, never decides: every finding is reported whether the redirect is
 * already stored or merely proposed, and nothing is changed or refused here.
 * RedirectValidator layers write-gate policy over these findings (plus the
 * duplicate check, which only makes sense for writes), and every reporting
 * surface - the `validate` CLI command, the validate ability, the list
 * table's To column, and the per-row Validate action - reads from here, so
 * they cannot disagree about the same redirect.
 */
class RedirectAuditor {

	/**
	 * Audit a redirect, stored or proposed.
	 *
	 * Returns every finding: destination problems and source warnings alike.
	 * A corrupt row carries placeholder values, so checking them would report
	 * nonsense; the corruption itself is the only finding.
	 *
	 * @param Redirect $redirect   The redirect to audit.
	 * @param bool     $check_urls Whether to make HTTP requests to check URL destinations.
	 * @return AuditFinding[] The findings, empty if none.
	 */
	public function audit( Redirect $redirect, bool $check_urls = false ): array {
		if ( $redirect->is_corrupt() ) {
			return array( new AuditFinding( $redirect, AuditFindingType::CORRUPT_DATA, $redirect->corruption() ) );
		}

		$findings = array();

		$destination_finding = $this->audit_destination( $redirect, $check_urls );
		if ( null !== $destination_finding ) {
			$findings[] = $destination_finding;
		}

		foreach ( $this->source_warnings( $redirect->source() ) as $type ) {
			$findings[] = new AuditFinding( $redirect, $type );
		}

		return $findings;
	}

	/**
	 * The warnings a source path deserves, before any redirect exists for it.
	 *
	 * Takes a bare SourceUrl so the surfaces that check a source as it is
	 * typed (the form's blur check, create, import) share the same rules as
	 * the audit of a stored row.
	 *
	 * @param SourceUrl $source The source to check.
	 * @return AuditFindingType[] The warning types, empty if none.
	 */
	public function source_warnings( SourceUrl $source ): array {
		return $source->is_reserved() ? array( AuditFindingType::RESERVED_SOURCE ) : array();
	}

	/**
	 * Audit a redirect's destination.
	 *
	 * Checks if the redirect's destination is (still) valid:
	 * - For post IDs: checks if post exists and is published
	 * - For relative paths: checks if path resolves to a published post
	 * - For absolute URLs: checks the host is allowed, and optionally whether
	 *   the URL responds
	 *
	 * @param Redirect $redirect   The redirect to audit.
	 * @param bool     $check_urls Whether to make HTTP requests to check URL destinations.
	 * @return AuditFinding|null The finding if broken, null if valid.
	 */
	public function audit_destination( Redirect $redirect, bool $check_urls = false ): ?AuditFinding {
		// A corrupt row carries placeholder values; checking them would report
		// nonsense. Report the corruption itself.
		if ( $redirect->is_corrupt() ) {
			return new AuditFinding( $redirect, AuditFindingType::CORRUPT_DATA, $redirect->corruption() );
		}

		$destination = $redirect->destination();

		// Check post ID destinations.
		if ( $destination->is_post_id() ) {
			return $this->check_post_destination( $redirect, $destination->as_post_id()->value() );
		}

		// Check URL destinations. DestinationUrl rejects empty strings, and a
		// redirect row with no stored destination is read back as '/', so
		// there is no empty case to check for here.
		$url = $destination->as_url()->value();

		// Relative paths.
		if ( $this->is_relative_path( $url ) ) {
			return $this->check_relative_path_destination( $redirect, $url, $check_urls );
		}

		return $this->check_absolute_url_destination( $redirect, $url, $check_urls );
	}

	/**
	 * Check if a post ID destination is valid.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param int      $post_id  The destination post ID.
	 * @return AuditFinding|null The finding if broken, null if valid.
	 */
	private function check_post_destination( Redirect $redirect, int $post_id ): ?AuditFinding {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return new AuditFinding( $redirect, AuditFindingType::POST_DELETED );
		}

		return $this->check_post_status( $redirect, $post );
	}

	/**
	 * Check a resolved destination post's status.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param \WP_Post $post     The destination post.
	 * @return AuditFinding|null The finding if not published, null if published.
	 */
	private function check_post_status( Redirect $redirect, \WP_Post $post ): ?AuditFinding {
		// The raw property, so a trashed post is reported as trashed rather
		// than resolved through get_post_status()'s attachment rules, which
		// report an attachment of a trashed parent by its pre-trash status.
		if ( 'trash' === $post->post_status ) {
			return new AuditFinding( $redirect, AuditFindingType::POST_TRASHED );
		}

		// Attachments store 'inherit', never 'publish'; get_post_status()
		// resolves that against the parent, so a redirect to a media item is
		// not reported as unpublished.
		$status = get_post_status( $post );

		if ( 'publish' !== $status ) {
			return new AuditFinding(
				$redirect,
				AuditFindingType::POST_UNPUBLISHED,
				'status: ' . $status
			);
		}

		return null;
	}

	/**
	 * Check if a relative path destination is valid.
	 *
	 * When the path resolves to a post, its status decides. A path that
	 * resolves to no post at all is indeterminate rather than broken:
	 * archives, rewrite endpoints, and URLs served outside WordPress are all
	 * real destinations with no post to find, so only the optional HTTP check
	 * can say anything about those.
	 *
	 * @param Redirect $redirect   The redirect.
	 * @param string   $path       The relative path.
	 * @param bool     $check_urls Whether to check via HTTP if path lookup fails.
	 * @return AuditFinding|null The finding if broken, null if valid.
	 */
	private function check_relative_path_destination( Redirect $redirect, string $path, bool $check_urls ): ?AuditFinding {
		// A query string or fragment can never be part of a slug match.
		$slug = trim( substr( $path, 0, strcspn( $path, '?#' ) ), '/' );

		// The home page has no slug to look up. get_page_by_path( '' ) matches
		// any post with an empty post_name - every draft and pending post has
		// one - so looking it up would report an arbitrary, unrelated post's
		// status as this redirect's problem. Only an HTTP check can say
		// anything about '/'.
		if ( '' === $slug ) {
			return $check_urls ? $this->check_url_destination( $redirect, home_url( $path ) ) : null;
		}

		$post = $this->resolve_path_to_post( $slug );

		// Trashing renames post_name with a __trashed suffix, so a direct
		// lookup misses trashed destinations - check for the renamed slug.
		if ( null === $post ) {
			$trashed = get_page_by_path( $slug . '__trashed', OBJECT, get_post_types() );
			if ( null !== $trashed && 'trash' === $trashed->post_status ) {
				return new AuditFinding( $redirect, AuditFindingType::POST_TRASHED );
			}
		}

		if ( null !== $post ) {
			return $this->check_post_status( $redirect, $post );
		}

		// If URL checking is enabled, verify via HTTP.
		if ( $check_urls ) {
			return $this->check_url_destination( $redirect, home_url( $path ) );
		}

		// Can't determine without HTTP check - assume OK.
		return null;
	}

	/**
	 * Resolve a relative path to the post it serves, if any.
	 *
	 * @param string $slug The path with surrounding slashes, query, and fragment removed.
	 * @return \WP_Post|null The post, or null if the path resolves to none.
	 */
	private function resolve_path_to_post( string $slug ): ?\WP_Post {
		$post = get_page_by_path( $slug, OBJECT, get_post_types() );

		// get_page_by_path() only walks hierarchical slugs; url_to_postid()
		// resolves anything matching the site's permalink structure, such as
		// dated permalinks.
		if ( null === $post ) {
			$post_id = url_to_postid( home_url( '/' . $slug ) );
			$post    = 0 !== $post_id ? get_post( $post_id ) : null;
		}

		return $post;
	}

	/**
	 * Check an absolute URL destination.
	 *
	 * The host must be one WordPress will actually redirect to: at request
	 * time the redirect runs through wp_safe_redirect(), which quietly sends
	 * the visitor elsewhere when the host is not allowed. That is a breakage
	 * whether the row is stored or proposed, so it is reported here without
	 * needing an HTTP request.
	 *
	 * @param Redirect $redirect   The redirect.
	 * @param string   $url        The destination URL.
	 * @param bool     $check_urls Whether to make an HTTP request to check the URL responds.
	 * @return AuditFinding|null The finding if broken, null if valid.
	 */
	private function check_absolute_url_destination( Redirect $redirect, string $url, bool $check_urls ): ?AuditFinding {
		if ( '' === wp_validate_redirect( $url, '' ) ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			return new AuditFinding(
				$redirect,
				AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED,
				is_string( $host ) ? 'host: ' . $host : null
			);
		}

		return $check_urls ? $this->check_url_destination( $redirect, $url ) : null;
	}

	/**
	 * Check if a URL destination is reachable.
	 *
	 * The one HTTP check: a GET that follows redirects, so a destination that
	 * forwards somewhere real passes and one that ends in a 404 fails, however
	 * many hops it takes to get there.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param string   $url      The destination URL.
	 * @return AuditFinding|null The finding if broken, null if valid.
	 */
	private function check_url_destination( Redirect $redirect, string $url ): ?AuditFinding {
		$response = $this->remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return new AuditFinding(
				$redirect,
				AuditFindingType::URL_REQUEST_FAILED,
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			return new AuditFinding( $redirect, AuditFindingType::URL_NOT_FOUND );
		}

		if ( $status_code >= 500 ) {
			return new AuditFinding(
				$redirect,
				AuditFindingType::URL_SERVER_ERROR,
				'status: ' . $status_code
			);
		}

		return null;
	}

	/**
	 * Request a URL.
	 *
	 * Protected so tests can stub the network.
	 *
	 * @param string $url The URL to request.
	 * @return array|\WP_Error The response or error.
	 */
	protected function remote_get( string $url ) {
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, 1, 20, array( 'reject_unsafe_urls' => true ) );
		}

		return wp_safe_remote_get( $url, array( 'timeout' => 5 ) );
	}

	/**
	 * Check if a URL is a relative path (not an absolute URL).
	 *
	 * @param string $url The URL to check.
	 * @return bool True if relative path.
	 */
	private function is_relative_path( string $url ): bool {
		return ! preg_match( '#^https?://#i', $url );
	}

	/**
	 * Audit a batch of redirects.
	 *
	 * @param Redirect[] $redirects         The redirects to audit.
	 * @param bool       $check_urls        Whether to check URL destinations.
	 * @param callable   $progress_callback Optional callback called after each redirect (receives count).
	 * @return AuditFinding[] The findings across the batch.
	 */
	public function audit_batch( array $redirects, bool $check_urls = false, ?callable $progress_callback = null ): array {
		$findings = array();
		$checked  = 0;

		foreach ( $redirects as $redirect ) {
			++$checked;

			$findings = array_merge( $findings, $this->audit( $redirect, $check_urls ) );

			if ( null !== $progress_callback ) {
				$progress_callback( $checked );
			}

			// Memory cleanup every 100 items.
			if ( 0 === $checked % 100 ) {
				$this->cleanup_memory();
			}
		}

		return $findings;
	}

	/**
	 * Clean up memory during batch operations.
	 *
	 * @return void
	 */
	private function cleanup_memory(): void {
		if ( function_exists( 'stop_the_insanity' ) ) {
			stop_the_insanity();
		}
	}
}
