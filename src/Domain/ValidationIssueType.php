<?php
/**
 * ValidationIssueType enum.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Types of validation issues that can be found when validating redirect destinations.
 *
 * Used by the validate command to categorise and report issues with
 * redirect destinations.
 */
enum ValidationIssueType: string {

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
	 * Get a human-readable label for this issue type.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Valid enum syntax.
		return match ( $this ) {
			self::POST_TRASHED     => 'Post trashed',
			self::POST_DELETED     => 'Post deleted',
			self::POST_UNPUBLISHED => 'Post not published',
			self::URL_NOT_FOUND    => 'Destination returns 404',
			self::URL_SERVER_ERROR => 'Destination returns server error',
			self::URL_REQUEST_FAILED => 'Request failed',
			self::CORRUPT_DATA     => 'Corrupt stored data',
			self::RESERVED_SOURCE  => 'Reserved WordPress path',
		};
	}

	/**
	 * Get a detailed description of this issue type.
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
			self::URL_NOT_FOUND    => 'The destination URL returns a 404 Not Found response',
			self::URL_SERVER_ERROR => 'The destination URL returns a server error',
			self::URL_REQUEST_FAILED => 'Failed to connect to the destination URL',
			self::CORRUPT_DATA     => 'The stored row cannot be read as a valid redirect; delete it, or update it with a new source and destination',
			self::RESERVED_SOURCE  => 'The source is a path WordPress itself serves. The redirect lies dormant while that path works, but answers it if the path ever returns a 404; for /wp-admin or /wp-login.php that locks you out of the dashboard',
		};

		if ( null !== $extra_info && '' !== $extra_info ) {
			return $base . ' (' . $extra_info . ')';
		}

		return $base;
	}
}
