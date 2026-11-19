<?php
/**
 * Redirect persistence exception.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use RuntimeException;

/**
 * Exception thrown when a redirect cannot be persisted.
 *
 * The message is English, for logs and the CLI. The reason and the facts
 * behind it are kept apart, so other surfaces can say the same thing in the
 * user's language.
 */
final class RedirectPersistenceException extends RuntimeException {

	public const SAVE_FAILED      = 'save_failed';
	public const DUPLICATE_SOURCE = 'duplicate_source';
	public const SOURCE_TAKEN     = 'source_taken';
	public const CORRUPT_REDIRECT = 'corrupt_redirect';

	/**
	 * Why the save was refused, one of the class constants.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * The source path the save was for, or '' when not known.
	 *
	 * @var string
	 */
	private string $source_path = '';

	/**
	 * The redirect the reason names: the one holding the source, or the corrupt one.
	 *
	 * @var int
	 */
	private int $redirect_id = 0;

	/**
	 * Why WordPress refused the write, in whatever language WordPress said it.
	 *
	 * @var string
	 */
	private string $detail = '';

	/**
	 * Create an exception for a failed save.
	 *
	 * @param SourceUrl $source The source URL that failed to save.
	 * @param string    $reason The reason for the failure.
	 * @return self
	 */
	public static function save_failed( SourceUrl $source, string $reason ): self {
		$exception = new self(
			sprintf(
				'Failed to save redirect for "%s": %s',
				$source->path(),
				$reason
			)
		);

		$exception->reason      = self::SAVE_FAILED;
		$exception->source_path = $source->path();
		$exception->detail      = $reason;

		return $exception;
	}

	/**
	 * Create an exception for a duplicate source URL.
	 *
	 * @param SourceUrl $source The duplicate source URL.
	 * @return self
	 */
	public static function duplicate_source( SourceUrl $source ): self {
		$exception = new self(
			sprintf(
				'A redirect already exists for "%s"',
				$source->path()
			)
		);

		$exception->reason      = self::DUPLICATE_SOURCE;
		$exception->source_path = $source->path();

		return $exception;
	}

	/**
	 * Create an exception for saving a redirect onto a source another one already has.
	 *
	 * A source can answer for only one redirect, so the message says which
	 * redirect has it and what to do instead.
	 *
	 * @param SourceUrl $source    The source.
	 * @param int       $holder_id The ID of the redirect that already has it.
	 * @return self
	 */
	public static function source_taken( SourceUrl $source, int $holder_id ): self {
		$exception = new self(
			sprintf(
				'Redirect #%1$d already has the source "%2$s", and a source can answer for only one redirect. Change the destination of #%1$d instead, or delete one of the two.',
				$holder_id,
				$source->path()
			)
		);

		$exception->reason      = self::SOURCE_TAKEN;
		$exception->source_path = $source->path();
		$exception->redirect_id = $holder_id;

		return $exception;
	}

	/**
	 * Create an exception for an attempt to save a corrupt redirect.
	 *
	 * A corrupt redirect carries placeholder values; saving it would replace
	 * the stored row with those placeholders.
	 *
	 * @param int|null $id The redirect ID.
	 * @return self
	 */
	public static function corrupt_redirect( ?int $id ): self {
		$exception = new self(
			sprintf(
				'Redirect %d holds corrupt stored data and cannot be re-saved; delete it, or update it with a new source and destination.',
				(int) $id
			)
		);

		$exception->reason      = self::CORRUPT_REDIRECT;
		$exception->redirect_id = (int) $id;

		return $exception;
	}

	/**
	 * Why the save was refused.
	 *
	 * @return string One of the class constants.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The source path the save was for.
	 *
	 * @return string The path, or '' for a corrupt redirect.
	 */
	public function source_path(): string {
		return $this->source_path;
	}

	/**
	 * The redirect the reason names.
	 *
	 * @return int The ID holding the source, or the corrupt redirect's ID; 0 otherwise.
	 */
	public function redirect_id(): int {
		return $this->redirect_id;
	}

	/**
	 * Why WordPress refused the write.
	 *
	 * @return string The message, or '' when the reason is not a failed write.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
