<?php
/**
 * InvalidRedirectValue exception.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use InvalidArgumentException;

/**
 * Thrown when a source or destination cannot be made into a redirect.
 *
 * The message is English, for logs and the CLI. The reason is a stable code,
 * so other surfaces can say the same thing in the user's language.
 */
final class InvalidRedirectValue extends InvalidArgumentException {

	public const SOURCE_INVALID       = 'source_invalid';
	public const SOURCE_EMPTY         = 'source_empty';
	public const SOURCE_UNPARSEABLE   = 'source_unparseable';
	public const SOURCE_NOT_UTF8      = 'source_not_utf8';
	public const DESTINATION_EMPTY    = 'destination_empty';
	public const DESTINATION_SCHEME   = 'destination_scheme';
	public const DESTINATION_NO_HOST  = 'destination_no_host';
	public const POST_ID_NOT_POSITIVE = 'post_id_not_positive';
	public const POST_ID_NOT_NUMERIC  = 'post_id_not_numeric';

	/**
	 * Why the value was refused, one of the class constants.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * Create the exception for a reason.
	 *
	 * @param string $reason One of the class constants.
	 * @return self
	 */
	public static function because( string $reason ): self {
		$exception = new self(
			match ( $reason ) {
				self::SOURCE_INVALID       => 'The URL does not validate.',
				self::SOURCE_EMPTY         => 'The URL contains neither a path nor query string.',
				self::SOURCE_UNPARSEABLE   => 'The URL could not be parsed.',
				self::SOURCE_NOT_UTF8      => 'The URL is not valid UTF-8.',
				self::DESTINATION_EMPTY    => 'Destination URL cannot be empty.',
				self::DESTINATION_SCHEME   => 'Absolute destination URLs must use http or https scheme.',
				self::DESTINATION_NO_HOST  => 'Absolute destination URLs must include a host.',
				self::POST_ID_NOT_POSITIVE => 'Post ID must be a positive integer.',
				self::POST_ID_NOT_NUMERIC  => 'Post ID must be numeric.',
			}
		);

		$exception->reason = $reason;

		return $exception;
	}

	/**
	 * Why the value was refused.
	 *
	 * @return string One of the class constants.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
