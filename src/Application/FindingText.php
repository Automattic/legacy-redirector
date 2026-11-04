<?php
/**
 * FindingText presenter.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\AuditDetail;
use Automattic\LegacyRedirector\Domain\AuditFindingType;

/**
 * Says what a finding means, and what to do about it, in the user's language.
 *
 * The Domain words each finding in English for the CLI, which is not
 * translated. Every other surface - the admin, REST and the abilities - uses
 * this instead. The English here matches the Domain's, sentence for sentence,
 * and a test holds them together.
 *
 * Descriptions and remedies are whole sentences with their own punctuation,
 * so no surface has to join fragments.
 */
final class FindingText {

	/**
	 * A short name for the finding, such as "Post trashed".
	 *
	 * @param AuditFindingType $type The finding type.
	 * @return string
	 */
	public static function label( AuditFindingType $type ): string {
		return match ( $type ) {
			AuditFindingType::POST_TRASHED               => __( 'Post trashed', 'legacy-redirector' ),
			AuditFindingType::POST_DELETED               => __( 'Post deleted', 'legacy-redirector' ),
			AuditFindingType::POST_UNPUBLISHED           => __( 'Post not published', 'legacy-redirector' ),
			AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED  => __( 'Destination host not allowed', 'legacy-redirector' ),
			AuditFindingType::URL_NOT_FOUND              => __( 'Destination returns 404', 'legacy-redirector' ),
			AuditFindingType::URL_SERVER_ERROR           => __( 'Destination returns server error', 'legacy-redirector' ),
			AuditFindingType::URL_REQUEST_FAILED         => __( 'Request failed', 'legacy-redirector' ),
			AuditFindingType::CORRUPT_DATA               => __( 'Corrupt stored data', 'legacy-redirector' ),
			AuditFindingType::RESERVED_SOURCE            => __( 'Reserved WordPress path', 'legacy-redirector' ),
			AuditFindingType::POSSIBLE_LOOP              => __( 'Possible redirect loop', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REDIRECTS_ELSEWHERE => __( 'Source redirects elsewhere', 'legacy-redirector' ),
			AuditFindingType::SOURCE_NOT_FIRING          => __( 'Redirect did not fire', 'legacy-redirector' ),
			AuditFindingType::SOURCE_SERVES_CONTENT      => __( 'Source serves content', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REQUEST_FAILED      => __( 'Source could not be checked', 'legacy-redirector' ),
		};
	}

	/**
	 * What is wrong, followed by the specific fact behind it when there is one.
	 *
	 * @param AuditFindingType $type   The finding type.
	 * @param AuditDetail|null $detail The specific fact, such as a post status or host.
	 * @return string
	 */
	public static function description( AuditFindingType $type, ?AuditDetail $detail = null ): string {
		$description = match ( $type ) {
			AuditFindingType::POST_TRASHED               => __( 'The destination post has been moved to trash.', 'legacy-redirector' ),
			AuditFindingType::POST_DELETED               => __( 'The destination post no longer exists.', 'legacy-redirector' ),
			AuditFindingType::POST_UNPUBLISHED           => __( 'The destination post is not published.', 'legacy-redirector' ),
			AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED  => __( 'The destination host is not allowed, so the redirect will not run.', 'legacy-redirector' ),
			AuditFindingType::URL_NOT_FOUND              => __( 'The destination URL returns a 404 Not Found response.', 'legacy-redirector' ),
			AuditFindingType::URL_SERVER_ERROR           => __( 'The destination URL returns a server error.', 'legacy-redirector' ),
			AuditFindingType::URL_REQUEST_FAILED         => __( 'Failed to connect to the destination URL.', 'legacy-redirector' ),
			AuditFindingType::CORRUPT_DATA               => __( 'The stored row cannot be read as a valid redirect.', 'legacy-redirector' ),
			AuditFindingType::RESERVED_SOURCE            => __( 'The source is a path WordPress itself serves. The redirect lies dormant while that path works, but answers it if the path ever returns a 404; for /wp-admin or /wp-login.php that locks you out of the dashboard.', 'legacy-redirector' ),
			AuditFindingType::POSSIBLE_LOOP              => __( 'Following the destination through the stored redirects leads back to this one. The loop only runs while every source in it returns a 404.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REDIRECTS_ELSEWHERE => __( 'Visiting the source redirects somewhere other than this redirect\'s destination, so something else is answering it first: another plugin, a server rule, or WordPress\'s own canonical redirect.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_NOT_FIRING          => __( 'Visiting the source returns a 404 without redirecting, so the redirect is not matching the URL visitors request.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_SERVES_CONTENT      => __( 'The site serves its own content at the source, such as a published post or page. Redirects only answer 404s, so this one lies dormant while that content is there.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REQUEST_FAILED      => __( 'The request to the source failed or the site answered with an error, so whether the redirect fires is unknown.', 'legacy-redirector' ),
		};

		if ( null === $detail ) {
			return $description;
		}

		/* translators: 1: what is wrong, a full sentence; 2: the specific fact behind it, such as "Post status: Draft." */
		return sprintf( _x( '%1$s %2$s', 'finding description, then its detail', 'legacy-redirector' ), $description, self::detail( $detail ) );
	}

	/**
	 * What a person can do about the finding.
	 *
	 * @param AuditFindingType $type The finding type.
	 * @return string
	 */
	public static function remedy( AuditFindingType $type ): string {
		return match ( $type ) {
			AuditFindingType::POST_TRASHED               => __( 'Restore the post from the trash, or point this redirect elsewhere.', 'legacy-redirector' ),
			AuditFindingType::POST_DELETED               => __( 'Point this redirect at another destination, or delete it.', 'legacy-redirector' ),
			AuditFindingType::POST_UNPUBLISHED           => __( 'Publish the post, or point this redirect elsewhere.', 'legacy-redirector' ),
			/* translators: allowed_redirect_hosts is the name of a WordPress filter; do not translate it. */
			AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED  => __( 'Add the host to the allowed_redirect_hosts filter in code, or point this redirect elsewhere.', 'legacy-redirector' ),
			AuditFindingType::URL_NOT_FOUND              => __( 'Point this redirect at a page that exists, or delete it.', 'legacy-redirector' ),
			AuditFindingType::URL_SERVER_ERROR           => __( 'Test again later; if the error persists, point this redirect elsewhere.', 'legacy-redirector' ),
			AuditFindingType::URL_REQUEST_FAILED         => __( 'Test again later: the request may have timed out or been throttled.', 'legacy-redirector' ),
			AuditFindingType::CORRUPT_DATA               => __( 'Delete it, or edit it with a full new source and destination.', 'legacy-redirector' ),
			AuditFindingType::RESERVED_SOURCE            => __( 'Keep it only if the path is a genuine legacy URL; otherwise delete it.', 'legacy-redirector' ),
			AuditFindingType::POSSIBLE_LOOP              => __( 'Point one redirect in the loop elsewhere, or disable it.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REDIRECTS_ELSEWHERE => __( 'Find what else redirects this URL (another plugin or a server rule) and remove that, or delete this redirect.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_NOT_FIRING          => __( 'Check the source matches the URL visitors request, including its encoding and the site\'s path, then test again.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_SERVES_CONTENT      => __( 'Unpublish the content at the source if the redirect should fire; otherwise the redirect is a fallback for when it goes.', 'legacy-redirector' ),
			AuditFindingType::SOURCE_REQUEST_FAILED      => __( 'Test again later: the site may have been busy or throttling requests to itself.', 'legacy-redirector' ),
		};
	}

	/**
	 * The description and remedy together, for a surface with room for one line.
	 *
	 * @param AuditFindingType $type   The finding type.
	 * @param AuditDetail|null $detail The specific fact, such as a post status or host.
	 * @return string
	 */
	public static function summary( AuditFindingType $type, ?AuditDetail $detail = null ): string {
		/* translators: 1: what is wrong, one or more full sentences; 2: what to do about it, a full sentence */
		return sprintf( _x( '%1$s %2$s', 'finding description, then its remedy', 'legacy-redirector' ), self::description( $type, $detail ), self::remedy( $type ) );
	}

	/**
	 * The specific fact behind a finding, as a sentence.
	 *
	 * @param AuditDetail $detail The detail.
	 * @return string
	 */
	private static function detail( AuditDetail $detail ): string {
		$value = $detail->value();

		switch ( $detail->kind() ) {
			case AuditDetail::POST_STATUS:
				$status = get_post_status_object( (string) $value );
				/* translators: %s: post status name, such as Draft */
				return sprintf( __( 'Post status: %s.', 'legacy-redirector' ), null !== $status ? $status->label : $value );

			case AuditDetail::POST_TYPE:
				$post_type = get_post_type_object( (string) $value );
				/* translators: %s: post type name, such as Template */
				return sprintf( __( 'Post type: %s.', 'legacy-redirector' ), null !== $post_type ? $post_type->labels->singular_name : $value );

			case AuditDetail::HOST:
				/* translators: %s: host name, such as example.com */
				return sprintf( __( 'Host: %s.', 'legacy-redirector' ), $value );

			case AuditDetail::HTTP_STATUS:
				/* translators: %s: HTTP status code, such as 503 */
				return sprintf( __( 'HTTP status: %s.', 'legacy-redirector' ), $value );

			case AuditDetail::LOCATION:
				/* translators: %s: URL */
				return sprintf( __( 'It redirected to %s.', 'legacy-redirector' ), $value );

			case AuditDetail::LOOP:
				/* translators: %s: the source paths in the loop, in order, such as "/a → /b → /a" */
				return sprintf( __( 'Loop: %s.', 'legacy-redirector' ), implode( _x( ' → ', 'separator between the redirects of a loop', 'legacy-redirector' ), (array) $value ) );

			case AuditDetail::NO_STATUS_CODE:
				return __( 'The response had no status code.', 'legacy-redirector' );

			case AuditDetail::TOO_MANY_REDIRECTS:
				return __( 'The destination redirected too many times.', 'legacy-redirector' );

			default:
				// An error message from the HTTP API, or why a row is corrupt.
				return (string) $value;
		}
	}
}
