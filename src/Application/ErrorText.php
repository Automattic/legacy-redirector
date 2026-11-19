<?php
/**
 * ErrorText presenter.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\InvalidRedirectValue;
use Automattic\LegacyRedirector\Domain\RedirectPersistenceException;

/**
 * Says why a redirect was refused, in the user's language.
 *
 * The Domain's exceptions carry English messages, for logs and the CLI, and
 * a stable reason. Every other surface uses this instead of getMessage(). The
 * English here matches the Domain's word for word, and a test holds them
 * together.
 */
final class ErrorText {

	/**
	 * The translated message for an exception.
	 *
	 * @param \Throwable $e The exception.
	 * @return string The message, or the exception's own when it has no reason to translate.
	 */
	public static function of( \Throwable $e ): string {
		if ( $e instanceof InvalidRedirectValue ) {
			return self::invalid_value( $e->reason() ) ?? $e->getMessage();
		}

		if ( $e instanceof RedirectPersistenceException ) {
			return self::persistence( $e ) ?? $e->getMessage();
		}

		return $e->getMessage();
	}

	/**
	 * Why a source or destination was refused.
	 *
	 * @param string $reason One of InvalidRedirectValue's constants.
	 * @return string|null The message, or null for an unknown reason.
	 */
	private static function invalid_value( string $reason ): ?string {
		return match ( $reason ) {
			InvalidRedirectValue::SOURCE_INVALID       => __( 'The URL does not validate.', 'legacy-redirector' ),
			InvalidRedirectValue::SOURCE_EMPTY         => __( 'The URL contains neither a path nor query string.', 'legacy-redirector' ),
			InvalidRedirectValue::SOURCE_UNPARSEABLE   => __( 'The URL could not be parsed.', 'legacy-redirector' ),
			InvalidRedirectValue::SOURCE_NOT_UTF8      => __( 'The URL is not valid UTF-8.', 'legacy-redirector' ),
			InvalidRedirectValue::DESTINATION_EMPTY    => __( 'Destination URL cannot be empty.', 'legacy-redirector' ),
			InvalidRedirectValue::DESTINATION_SCHEME   => __( 'Absolute destination URLs must use http or https scheme.', 'legacy-redirector' ),
			InvalidRedirectValue::DESTINATION_NO_HOST  => __( 'Absolute destination URLs must include a host.', 'legacy-redirector' ),
			InvalidRedirectValue::POST_ID_NOT_POSITIVE => __( 'Post ID must be a positive integer.', 'legacy-redirector' ),
			InvalidRedirectValue::POST_ID_NOT_NUMERIC  => __( 'Post ID must contain only digits.', 'legacy-redirector' ),
			default                                    => null,
		};
	}

	/**
	 * Why a save was refused.
	 *
	 * @param RedirectPersistenceException $e The exception.
	 * @return string|null The message, or null for an unknown reason.
	 */
	private static function persistence( RedirectPersistenceException $e ): ?string {
		return match ( $e->reason() ) {
			RedirectPersistenceException::SAVE_FAILED      => sprintf(
				/* translators: 1: source path, 2: why WordPress refused the write */
				__( 'Failed to save redirect for "%1$s": %2$s', 'legacy-redirector' ),
				$e->source_path(),
				$e->detail()
			),
			RedirectPersistenceException::DUPLICATE_SOURCE => sprintf(
				/* translators: %s: source path */
				__( 'A redirect already exists for "%s"', 'legacy-redirector' ),
				$e->source_path()
			),
			RedirectPersistenceException::SOURCE_TAKEN     => sprintf(
				/* translators: 1: ID of the redirect that has the source, 2: source path */
				__( 'Redirect #%1$d already has the source "%2$s", and a source can answer for only one redirect. Change the destination of #%1$d instead, or delete one of the two.', 'legacy-redirector' ),
				$e->redirect_id(),
				$e->source_path()
			),
			RedirectPersistenceException::CORRUPT_REDIRECT => sprintf(
				/* translators: %d: redirect ID */
				__( 'Redirect %d holds corrupt stored data and cannot be re-saved; delete it, or update it with a new source and destination.', 'legacy-redirector' ),
				$e->redirect_id()
			),
			default                                        => null,
		};
	}
}
