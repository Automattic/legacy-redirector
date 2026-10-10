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
use Automattic\LegacyRedirector\Domain\Destination;
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
	 * The loop detector, the one audit rule needing the repository.
	 *
	 * Optional so the write gate, which never asks about loops, can build an
	 * auditor without wiring a repository through; without it, audits simply
	 * carry no loop findings.
	 *
	 * @var LoopDetector|null
	 */
	private ?LoopDetector $loop_detector;

	/**
	 * Constructor.
	 *
	 * @param LoopDetector|null $loop_detector The loop detector (optional).
	 */
	public function __construct( ?LoopDetector $loop_detector = null ) {
		$this->loop_detector = $loop_detector;
	}

	/**
	 * Audit a redirect, stored or proposed.
	 *
	 * Returns every finding: destination problems and warnings alike. A
	 * corrupt row carries placeholder values, so checking them would report
	 * nonsense; the corruption itself is the only finding.
	 *
	 * @param Redirect $redirect     The redirect to audit.
	 * @param bool     $check_urls   Whether to make HTTP requests to check URL destinations.
	 * @param bool     $check_source Whether to request the source to check the redirect fires.
	 * @return AuditFinding[] The findings, empty if none.
	 */
	public function audit( Redirect $redirect, bool $check_urls = false, bool $check_source = false ): array {
		if ( $redirect->is_corrupt() ) {
			return array( new AuditFinding( $redirect, AuditFindingType::CORRUPT_DATA, $redirect->corruption() ) );
		}

		$findings = array();

		$destination_finding = $this->audit_destination( $redirect, $check_urls );
		if ( null !== $destination_finding ) {
			$findings[] = $destination_finding;
		}

		if ( $check_source ) {
			$source_finding = $this->audit_source( $redirect );
			if ( null !== $source_finding ) {
				$findings[] = $source_finding;
			}
		}

		return array_merge( $findings, $this->warnings( $redirect ) );
	}

	/**
	 * The warnings a redirect deserves, stored or proposed.
	 *
	 * Everything that flags a working redirect for a human look: reserved
	 * sources and possible loops. Create and import report these after a
	 * successful write.
	 *
	 * @param Redirect $redirect The redirect to check.
	 * @return AuditFinding[] The warnings, empty if none.
	 */
	public function warnings( Redirect $redirect ): array {
		$warnings = array();

		foreach ( $this->source_warnings( $redirect->source() ) as $type ) {
			$warnings[] = new AuditFinding( $redirect, $type );
		}

		$cycle = null !== $this->loop_detector ? $this->loop_detector->find_cycle( $redirect ) : array();
		if ( array() !== $cycle ) {
			$warnings[] = new AuditFinding( $redirect, AuditFindingType::POSSIBLE_LOOP, implode( ' -> ', $cycle ) );
		}

		return $warnings;
	}

	/**
	 * The warnings a source path deserves, before any redirect exists for it.
	 *
	 * Takes a bare SourceUrl so the form's blur check, which sees only the
	 * source as it is typed, shares the same rules as the audit of a stored
	 * row. Rules needing the destination too (loops) live in warnings().
	 *
	 * @param SourceUrl $source The source to check.
	 * @return AuditFindingType[] The warning types, empty if none.
	 */
	public function source_warnings( SourceUrl $source ): array {
		return $source->is_reserved() ? array( AuditFindingType::RESERVED_SOURCE ) : array();
	}

	/**
	 * The checks a destination value deserves as it is typed, before any
	 * redirect exists for it.
	 *
	 * The destination-side mirror of source_warnings(): the form's blur check
	 * has only the field value, so only rules needing no stored context belong
	 * here. Today that is the allowed-host rule for absolute URLs, which the
	 * write gate will refuse - surfacing it at blur time puts the fix in front
	 * of the admin before they hit Save.
	 *
	 * @param Destination $destination The destination to check.
	 * @return AuditFindingType[] The finding types, empty if none.
	 */
	public function destination_checks( Destination $destination ): array {
		if ( $destination->is_post_id() || $destination->as_url()->is_relative() ) {
			return array();
		}

		if ( '' === wp_validate_redirect( $destination->as_url()->value(), '' ) ) {
			return array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED );
		}

		return array();
	}

	/**
	 * Check that a redirect's source actually redirects to its destination.
	 *
	 * Every other check works from stored data. This one requests the source
	 * and reads what came back, which is the only way to catch the two things
	 * stored data cannot show: a source that answers with its own response
	 * (a real page, post or archive winning over the redirect), and a
	 * redirect that fires but lands somewhere other than its destination.
	 *
	 * The requesting and the comparing live in probe_source(), which the
	 * admin Test action also uses; this turns its outcome into a finding so
	 * the batch surfaces agree with it.
	 *
	 * A disabled redirect is skipped: it does not fire by design, so reporting
	 * that as a fault would flag every disabled redirect on the site.
	 *
	 * @param Redirect $redirect The redirect to check.
	 * @return AuditFinding|null The finding if the redirect does not fire as expected, null if it does.
	 */
	public function audit_source( Redirect $redirect ): ?AuditFinding {
		// A disabled redirect does not fire, so it cannot be found wanting for
		// not firing. A corrupt row has no source worth requesting.
		if ( $redirect->is_corrupt() || ! $redirect->is_active() ) {
			return null;
		}

		// A reserved source is already reported, and it is legitimate for a
		// migrated site to hold legacy URLs there, so requesting it would add
		// nothing to the reserved-source warning.
		if ( $redirect->source()->is_reserved() ) {
			return null;
		}

		// Without a resolvable destination there is nothing to compare the
		// source's response against, so the probe could only misreport.
		if ( null === $this->expected_destination_url( $redirect ) ) {
			return null;
		}

		$probe = $this->probe_source( $redirect );

		return match ( $probe['status'] ) {
			// The source redirects to the stored destination.
			'confirmed'   => null,
			// The source serves content or a hop back to itself, so the 404
			// our redirect answers never happens.
			'dormant'     => new AuditFinding( $redirect, AuditFindingType::SOURCE_DID_NOT_REDIRECT ),
			// The source 404s without the redirect firing.
			'not-firing'  => new AuditFinding( $redirect, AuditFindingType::SOURCE_DID_NOT_REDIRECT, 'status: 404' ),
			'diverted'    => new AuditFinding(
				$redirect,
				AuditFindingType::REDIRECT_MISMATCH,
				empty( $probe['location'] ) ? null : 'to: ' . $probe['location']
			),
			// Unknown rather than broken, so it is a warning and --fix skips it.
			'unreachable' => new AuditFinding( $redirect, AuditFindingType::SOURCE_REQUEST_FAILED ),
			// A status this method does not know cannot be judged, so it is
			// left alone rather than guessed at as a breakage.
			default       => null,
		};
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
	 * Whether only an HTTP request can judge this redirect's destination.
	 *
	 * True for absolute URLs (an allowed host says nothing about whether the
	 * page responds), the home page, and relative paths that resolve to no
	 * post - all cases audit_destination() treats as indeterminate without
	 * check_urls. Lets a display distinguish "checked and fine" from "nothing
	 * conclusive without a request", so a clean result does not overclaim.
	 *
	 * @param Redirect $redirect The redirect to check.
	 * @return bool True when the destination is only judgeable over HTTP.
	 */
	public function destination_needs_http( Redirect $redirect ): bool {
		if ( $redirect->is_corrupt() || $redirect->destination()->is_post_id() ) {
			return false;
		}

		$url = $redirect->destination()->as_url()->value();

		if ( ! $this->is_relative_path( $url ) ) {
			return true;
		}

		$slug = $this->lookup_slug( $url );

		// WordPress always serves something for the home path - the front
		// controller never 404s '/' - so landing there is conclusive.
		if ( '' === $slug ) {
			return false;
		}

		if ( null !== $this->resolve_path_to_post( $slug ) ) {
			return false;
		}

		// No post behind the path, but it may be another redirect's source:
		// a chain that lands on published content is just as conclusive as
		// pointing at that content directly.
		return ! $this->chain_lands_on_content( $redirect );
	}

	/**
	 * Whether following the redirect chain from this redirect lands on
	 * published content.
	 *
	 * @param Redirect $redirect The redirect whose chain to follow.
	 * @return bool True when the chain's final destination is published content.
	 */
	private function chain_lands_on_content( Redirect $redirect ): bool {
		if ( null === $this->loop_detector ) {
			return false;
		}

		$end = $this->loop_detector->follow( $redirect );

		// No hop was followed (the destination is no redirect's source), or
		// the walk met a cycle: nothing conclusive either way.
		if ( null === $end || $end === $redirect ) {
			return false;
		}

		$destination = $end->destination();

		if ( $destination->is_post_id() ) {
			$post = get_post( $destination->as_post_id()->value() );

			return null !== $post && $this->is_published( $post );
		}

		$end_url = $destination->as_url()->value();

		// An external end still needs HTTP to judge.
		if ( ! $this->is_relative_path( $end_url ) ) {
			return false;
		}

		$end_slug = $this->lookup_slug( $end_url );

		if ( '' === $end_slug ) {
			return true;
		}

		$post = $this->resolve_path_to_post( $end_slug );

		return null !== $post && $this->is_published( $post );
	}

	/**
	 * Whether a destination post counts as published.
	 *
	 * The same rules check_post_status() reports findings from: the raw
	 * property for trash (so attachments of trashed parents are not resolved
	 * to their pre-trash status), get_post_status() otherwise (so
	 * attachments' 'inherit' resolves against the parent).
	 *
	 * @param \WP_Post $post The destination post.
	 * @return bool True when published.
	 */
	private function is_published( \WP_Post $post ): bool {
		return 'trash' !== $post->post_status && 'publish' === get_post_status( $post );
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
		$slug = $this->lookup_slug( $path );

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
	 * The slug form of a relative path, ready for a post lookup.
	 *
	 * A query string or fragment can never be part of a slug match.
	 *
	 * @param string $path The relative path.
	 * @return string The path with surrounding slashes, query, and fragment removed.
	 */
	private function lookup_slug( string $path ): string {
		return trim( substr( $path, 0, strcspn( $path, '?#' ) ), '/' );
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
		if ( array() !== $this->destination_checks( $redirect->destination() ) ) {
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
	 * Probe the source end to end: request it and see what actually happens.
	 *
	 * The audit answers "would this redirect send visitors somewhere real?";
	 * this answers "does visiting the source actually redirect?". A source
	 * can serve content (so the redirect lies dormant), 404 without the
	 * redirect firing, or redirect somewhere other than the stored
	 * destination - none of which any static check can see.
	 *
	 * @param Redirect $redirect The redirect whose source to request.
	 * @return array{status: string, location?: string} The outcome:
	 *         'confirmed'   the source redirects to the stored destination;
	 *         'diverted'    the source redirects somewhere else ('location');
	 *         'dormant'     the source serves content, so the redirect never fires;
	 *         'not-firing'  the source 404s without redirecting;
	 *         'unreachable' the site could not request itself.
	 */
	public function probe_source( Redirect $redirect ): array {
		$source_url = home_url( $redirect->source()->path() );
		$response   = $this->remote_get_without_redirects( $source_url );

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unreachable' );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( $code >= 300 && $code < 400 ) {
			// A hop back to the source itself (a trailing-slash canonical, a
			// directory redirect) means the path is served, not diverted: the
			// 404 our redirect would answer never happens.
			if ( $this->urls_equivalent( $location, $source_url ) ) {
				return array( 'status' => 'dormant' );
			}

			$expected = $this->expected_destination_url( $redirect );

			if ( null !== $expected && $this->urls_equivalent( $location, $expected ) ) {
				return array(
					'status'   => 'confirmed',
					'location' => $location,
				);
			}

			return array(
				'status'   => 'diverted',
				'location' => $location,
			);
		}

		if ( 404 === $code ) {
			return array( 'status' => 'not-firing' );
		}

		return array( 'status' => 'dormant' );
	}

	/**
	 * The absolute URL the stored destination should send a visitor to.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return string|null The URL, or null when it cannot be resolved.
	 */
	private function expected_destination_url( Redirect $redirect ): ?string {
		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			$permalink = get_permalink( $destination->as_post_id()->value() );

			return is_string( $permalink ) ? $permalink : null;
		}

		$url = $destination->as_url()->value();

		return $this->is_relative_path( $url ) ? home_url( $url ) : $url;
	}

	/**
	 * Whether two URLs are the same destination for probing purposes.
	 *
	 * A trailing slash difference is not a divergence.
	 *
	 * @param string $a One URL.
	 * @param string $b The other URL.
	 * @return bool True when equivalent.
	 */
	private function urls_equivalent( string $a, string $b ): bool {
		return untrailingslashit( $a ) === untrailingslashit( $b );
	}

	/**
	 * Request a URL without following redirects.
	 *
	 * Protected so tests can stub the network.
	 *
	 * @param string $url The URL to request.
	 * @return array|\WP_Error The response or error.
	 */
	protected function remote_get_without_redirects( string $url ) {
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get(
				$url,
				'',
				3,
				1,
				20,
				array(
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
				)
			);
		}

		return wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);
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
	 * @param bool       $check_source      Whether to request each source to check the redirect fires.
	 * @return AuditFinding[] The findings across the batch.
	 */
	public function audit_batch( array $redirects, bool $check_urls = false, ?callable $progress_callback = null, bool $check_source = false ): array {
		$findings = array();
		$checked  = 0;

		foreach ( $redirects as $redirect ) {
			++$checked;

			$findings = array_merge( $findings, $this->audit( $redirect, $check_urls, $check_source ) );

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
