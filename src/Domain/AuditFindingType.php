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
 * is_warning().
 *
 * Adding a case? The user-facing explanations live outside this enum and
 * must be updated with it: the Validate page's contextual help
 * (ValidatePage::add_contextual_help()), the Add/Edit form's contextual help
 * (FormScreenSetup), the validate ability description, the ValidateCommand
 * docblock, and docs/cli.md.
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
	 * The source answers with its own response rather than a redirect.
	 */
	case SOURCE_DID_NOT_REDIRECT = 'source_did_not_redirect';

	/**
	 * The source redirects, but somewhere other than this redirect's destination.
	 */
	case REDIRECT_MISMATCH = 'redirect_mismatch';

	/**
	 * The source request failed, so whether the redirect fires is unknown.
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
	 * A failed source request is a warning on purpose. The request can fail
	 * for reasons that say nothing about the redirect - a timeout, a host
	 * that refuses the request - and treating an unknown as broken would let a
	 * flaky network disable working redirects under --fix.
	 *
	 * @return bool
	 */
	public function is_warning(): bool {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return in_array( $this, array( self::RESERVED_SOURCE, self::POSSIBLE_LOOP, self::SOURCE_REQUEST_FAILED ), true );
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
			self::SOURCE_DID_NOT_REDIRECT => 'Source does not redirect',
			self::REDIRECT_MISMATCH => 'Redirect goes elsewhere',
			self::SOURCE_REQUEST_FAILED => 'Source request failed',
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
			self::EXTERNAL_HOST_NOT_ALLOWED => 'The destination host is not allowed, so the redirect will not run. Add the domain to the "allowed_redirect_hosts" filter',
			self::URL_NOT_FOUND    => 'The destination URL returns a 404 Not Found response',
			self::URL_SERVER_ERROR => 'The destination URL returns a server error',
			self::URL_REQUEST_FAILED => 'Failed to connect to the destination URL',
			self::CORRUPT_DATA     => 'The stored row cannot be read as a valid redirect; delete it, or update it with a new source and destination',
			self::RESERVED_SOURCE  => 'The source is a path WordPress itself serves. The redirect lies dormant while that path works, but answers it if the path ever returns a 404; for /wp-admin or /wp-login.php that locks you out of the dashboard',
			self::POSSIBLE_LOOP    => 'Following the destination through the stored redirects leads back to this one. The loop only runs while every source in it returns a 404; break it by re-pointing or disabling one member',
			self::SOURCE_DID_NOT_REDIRECT => 'The source answered without redirecting, so the redirect never fired. Usually a real page, post or archive is winning over it, so check whether the source now conflicts with existing content',
			self::REDIRECT_MISMATCH => 'The source redirected, but somewhere other than this redirect\'s destination. Either another redirect claims this source, or the redirect has been edited since it was cached',
			self::SOURCE_REQUEST_FAILED => 'The request to the source failed, so whether the redirect fires is unknown. This is often a timeout or a host refusing the request, so re-run before acting on it',
		};

		if ( null !== $extra_info && '' !== $extra_info ) {
			return $base . ' (' . $extra_info . ')';
		}

		return $base;
	}
}
