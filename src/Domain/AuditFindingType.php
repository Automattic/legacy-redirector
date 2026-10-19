<?php
/**
 * AuditFindingType enum.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Types of findings the auditor can report about a redirect.
 *
 * A finding is either a problem (the redirect is broken and will not do its
 * job) or a warning (the redirect works but deserves a human look); see
 * is_warning(). Independently, a finding is conclusive unless the check could
 * not tell (a failed request, a server error); see is_conclusive().
 *
 * Adding a case? docs/redirect-health.md is the full list of checks for
 * users, and says what else to update with it.
 */
enum AuditFindingType: string {

	/**
	 * The destination post has been trashed.
	 */
	case POST_TRASHED = 'post_trashed';

	/**
	 * The destination post has been permanently deleted.
	 */
	case POST_DELETED = 'post_deleted';

	/**
	 * The destination post exists but is not published (draft, pending, private, etc).
	 */
	case POST_UNPUBLISHED = 'post_unpublished';

	/**
	 * The destination host is not in allowed_redirect_hosts, so wp_safe_redirect() refuses it.
	 */
	case EXTERNAL_HOST_NOT_ALLOWED = 'external_host_not_allowed';

	/**
	 * The destination URL returns a 404 response.
	 */
	case URL_NOT_FOUND = 'url_not_found';

	/**
	 * The destination URL returns a server error (5xx).
	 */
	case URL_SERVER_ERROR = 'url_server_error';

	/**
	 * The destination URL request failed (network error, timeout, etc).
	 */
	case URL_REQUEST_FAILED = 'url_request_failed';

	/**
	 * The stored row cannot be read as a valid redirect (corrupt data).
	 */
	case CORRUPT_DATA = 'corrupt_data';

	/**
	 * The source is a path WordPress itself serves (wp-admin, wp-login.php, ...).
	 */
	case RESERVED_SOURCE = 'reserved_source';

	/**
	 * Following the destination through stored redirects leads back to this one.
	 */
	case POSSIBLE_LOOP = 'possible_loop';

	/**
	 * Requesting the source got a redirect somewhere other than the stored destination.
	 *
	 * Found only by a live test, never by the stored-details checks.
	 */
	case SOURCE_REDIRECTS_ELSEWHERE = 'source_redirects_elsewhere';

	/**
	 * Requesting the source got a 404: the redirect did not fire.
	 *
	 * Found only by a live test.
	 */
	case SOURCE_NOT_FIRING = 'source_not_firing';

	/**
	 * Requesting the source got the site's own content, so the redirect lies dormant.
	 *
	 * Found only by a live test.
	 */
	case SOURCE_SERVES_CONTENT = 'source_serves_content';

	/**
	 * Requesting the source failed, or the site answered with an error.
	 *
	 * Found only by a live test.
	 */
	case SOURCE_REQUEST_FAILED = 'source_request_failed';

	/**
	 * Whether this finding is a warning rather than a problem.
	 *
	 * A problem means the redirect is broken: its destination is gone or the
	 * redirect will not run. A warning means it works but deserves a human
	 * look, so automated fixes (like `validate --fix`) must leave it alone.
	 * A loop is a warning because it is only live while every source in it
	 * returns a 404; any member serving real content keeps it dormant.
	 *
	 * @return bool
	 */
	public function is_warning(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return in_array( $this, array( self::RESERVED_SOURCE, self::POSSIBLE_LOOP, self::SOURCE_SERVES_CONTENT ), true );
	}

	/**
	 * Whether this finding says something definite about the redirect.
	 *
	 * An inconclusive finding means the check could not tell: the request
	 * failed (a timeout, a refused connection, a host the VIP request helper
	 * has stopped calling for a while), or the destination answered with a
	 * server error that is as likely to be passing as permanent. It is still
	 * reported, but automated fixes (like `validate --fix`) must leave the
	 * redirect alone, or one slow host could switch off every redirect to it.
	 *
	 * @return bool
	 */
	public function is_conclusive(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return ! in_array( $this, array( self::URL_REQUEST_FAILED, self::URL_SERVER_ERROR, self::SOURCE_REQUEST_FAILED ), true );
	}

	/**
	 * Get a human-readable label for this finding type.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return match ( $this ) {
			self::POST_TRASHED     => 'Post trashed',
			self::POST_DELETED     => 'Post deleted',
			self::POST_UNPUBLISHED => 'Post not published',
			self::EXTERNAL_HOST_NOT_ALLOWED => 'Destination host not allowed',
			self::URL_NOT_FOUND    => 'Destination returns 404',
			self::URL_SERVER_ERROR => 'Destination returns server error',
			self::URL_REQUEST_FAILED => 'Request failed',
			self::CORRUPT_DATA     => 'Corrupt stored data',
			self::RESERVED_SOURCE  => 'Reserved WordPress path',
			self::POSSIBLE_LOOP    => 'Possible redirect loop',
			self::SOURCE_REDIRECTS_ELSEWHERE => 'Source redirects elsewhere',
			self::SOURCE_NOT_FIRING => 'Redirect did not fire',
			self::SOURCE_SERVES_CONTENT => 'Source serves content',
			self::SOURCE_REQUEST_FAILED => 'Source could not be checked',
		};
	}

	/**
	 * Get a detailed description of this finding type.
	 *
	 * @param string|null $extra_info Optional extra information (e.g., status code, post status).
	 * @return string
	 */
	public function description( ?string $extra_info = null ): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		$base = match ( $this ) {
			self::POST_TRASHED     => 'The destination post has been moved to trash',
			self::POST_DELETED     => 'The destination post no longer exists',
			self::POST_UNPUBLISHED => 'The destination post is not published',
			self::EXTERNAL_HOST_NOT_ALLOWED => 'The destination host is not allowed, so the redirect will not run',
			self::URL_NOT_FOUND    => 'The destination URL returns a 404 Not Found response',
			self::URL_SERVER_ERROR => 'The destination URL returns a server error',
			self::URL_REQUEST_FAILED => 'Failed to connect to the destination URL',
			self::CORRUPT_DATA     => 'The stored row cannot be read as a valid redirect',
			self::RESERVED_SOURCE  => 'The source is a path WordPress itself serves. The redirect lies dormant while that path works, but answers it if the path ever returns a 404; for /wp-admin or /wp-login.php that locks you out of the dashboard',
			self::POSSIBLE_LOOP    => 'Following the destination through the stored redirects leads back to this one. The loop only runs while every source in it returns a 404',
			self::SOURCE_REDIRECTS_ELSEWHERE => 'Visiting the source redirects somewhere other than this redirect\'s destination, so something else is answering it first: another plugin, a server rule, or WordPress\'s own canonical redirect',
			self::SOURCE_NOT_FIRING => 'Visiting the source returns a 404 without redirecting, so the redirect is not matching the URL visitors request',
			self::SOURCE_SERVES_CONTENT => 'Visiting the source shows the site\'s own content. Redirects only answer 404s, so this one lies dormant while that content is there',
			self::SOURCE_REQUEST_FAILED => 'The request to the source failed or the site answered with an error, so whether the redirect fires is unknown',
		};

		if ( null !== $extra_info && '' !== $extra_info ) {
			return $base . ' (' . $extra_info . ')';
		}

		return $base;
	}

	/**
	 * What a person can do about this finding.
	 *
	 * Almost nothing here can be fixed automatically: restoring a post,
	 * repointing a redirect or allowing a host are decisions for a person.
	 * So every finding says what the choices are.
	 *
	 * @return string
	 */
	public function remedy(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return match ( $this ) {
			self::POST_TRASHED     => 'Restore the post from the trash, or point this redirect elsewhere',
			self::POST_DELETED     => 'Point this redirect at another destination, or delete it',
			self::POST_UNPUBLISHED => 'Publish the post, or point this redirect elsewhere',
			self::EXTERNAL_HOST_NOT_ALLOWED => 'Add the host to the allowed_redirect_hosts filter in code, or point this redirect elsewhere',
			self::URL_NOT_FOUND    => 'Point this redirect at a page that exists, or delete it',
			self::URL_SERVER_ERROR => 'Test again later; if the error persists, point this redirect elsewhere',
			self::URL_REQUEST_FAILED => 'Test again later: the request may have timed out or been throttled',
			self::CORRUPT_DATA     => 'Delete it, or edit it with a full new source and destination',
			self::RESERVED_SOURCE  => 'Keep it only if the path is a genuine legacy URL; otherwise delete it',
			self::POSSIBLE_LOOP    => 'Point one redirect in the loop elsewhere, or disable it',
			self::SOURCE_REDIRECTS_ELSEWHERE => 'Find what else redirects this URL (another plugin or a server rule) and remove that, or delete this redirect',
			self::SOURCE_NOT_FIRING => 'Check the source matches the URL visitors request, including its encoding and the site\'s path, then test again',
			self::SOURCE_SERVES_CONTENT => 'Unpublish the content at the source if the redirect should fire; otherwise the redirect is a fallback for when it goes',
			self::SOURCE_REQUEST_FAILED => 'Test again later: the site may have been busy or throttling requests to itself',
		};
	}
}
