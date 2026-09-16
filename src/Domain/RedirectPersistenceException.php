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
 */
final class RedirectPersistenceException extends RuntimeException {

	/**
	 * Create an exception for a failed save.
	 *
	 * @param SourceUrl $source The source URL that failed to save.
	 * @param string    $reason The reason for the failure.
	 * @return self
	 */
	public static function save_failed( SourceUrl $source, string $reason ): self {
		return new self(
			sprintf(
				'Failed to save redirect for "%s": %s',
				$source->path(),
				$reason
			)
		);
	}

	/**
	 * Create an exception for a duplicate source URL.
	 *
	 * @param SourceUrl $source The duplicate source URL.
	 * @return self
	 */
	public static function duplicate_source( SourceUrl $source ): self {
		return new self(
			sprintf(
				'A redirect already exists for "%s"',
				$source->path()
			)
		);
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
		return new self(
			sprintf(
				'Redirect %d holds corrupt stored data and cannot be re-saved; delete it, or update it with a new source and destination.',
				(int) $id
			)
		);
	}
}
