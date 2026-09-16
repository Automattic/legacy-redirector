<?php
/**
 * Redirect entity.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Redirect entity - the core domain model.
 *
 * Represents a redirect rule with identity (ID), lifecycle (status),
 * and the source/destination mapping. This is an entity because it
 * has a distinct identity that persists over time.
 */
final class Redirect {

	/**
	 * The redirect ID (null if not yet persisted).
	 *
	 * @var int|null
	 */
	private ?int $id;

	/**
	 * The source URL to redirect from.
	 *
	 * @var SourceUrl
	 */
	private SourceUrl $source;

	/**
	 * The destination to redirect to.
	 *
	 * @var Destination
	 */
	private Destination $destination;

	/**
	 * The post status (publish, draft, trash).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * When the redirect was created.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $created_at;

	/**
	 * Why the stored row could not be read as a valid redirect, or null if healthy.
	 *
	 * A corrupt redirect carries placeholder source/destination values so it
	 * can still be listed, reported, and deleted, but it must never be served
	 * to visitors or re-saved as-is.
	 *
	 * @var string|null
	 */
	private ?string $corruption;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param int|null               $id          The redirect ID.
	 * @param SourceUrl              $source      The source URL.
	 * @param Destination            $destination The destination.
	 * @param string                 $status      The status.
	 * @param DateTimeImmutable|null $created_at  When created.
	 * @param string|null            $corruption  Why the stored row is unreadable, or null if healthy.
	 */
	private function __construct(
		?int $id,
		SourceUrl $source,
		Destination $destination,
		string $status,
		?DateTimeImmutable $created_at,
		?string $corruption = null
	) {
		$this->id          = $id;
		$this->source      = $source;
		$this->destination = $destination;
		$this->status      = $status;
		$this->created_at  = $created_at;
		$this->corruption  = $corruption;
	}

	/**
	 * Create a new redirect (not yet persisted).
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @return self
	 */
	public static function create( SourceUrl $source, Destination $destination ): self {
		return new self(
			null,
			$source,
			$destination,
			'publish',
			new DateTimeImmutable()
		);
	}

	/**
	 * Reconstitute a redirect from persistence.
	 *
	 * Used by the repository when loading from the database.
	 *
	 * @param int                    $id          The redirect ID.
	 * @param SourceUrl              $source      The source URL.
	 * @param Destination            $destination The destination.
	 * @param string                 $status      The status.
	 * @param DateTimeImmutable|null $created_at  When created.
	 * @param string|null            $corruption  Why the stored row is unreadable, or null if healthy.
	 *                                            When set, $source and $destination may be placeholders.
	 * @return self
	 */
	public static function reconstitute(
		int $id,
		SourceUrl $source,
		Destination $destination,
		string $status,
		?DateTimeImmutable $created_at = null,
		?string $corruption = null
	): self {
		return new self( $id, $source, $destination, $status, $created_at, $corruption );
	}

	/**
	 * Get the redirect ID.
	 *
	 * @return int|null The ID, or null if not persisted.
	 */
	public function id(): ?int {
		return $this->id;
	}

	/**
	 * Check if this redirect has been persisted.
	 *
	 * @return bool True if persisted (has an ID).
	 */
	public function is_persisted(): bool {
		return null !== $this->id;
	}

	/**
	 * Get the source URL.
	 *
	 * @return SourceUrl
	 */
	public function source(): SourceUrl {
		return $this->source;
	}

	/**
	 * Get the destination.
	 *
	 * @return Destination
	 */
	public function destination(): Destination {
		return $this->destination;
	}

	/**
	 * Get the status.
	 *
	 * @return string The status (publish, draft, trash).
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Check if the redirect is active (published).
	 *
	 * @return bool True if status is 'publish'.
	 */
	public function is_active(): bool {
		return 'publish' === $this->status;
	}

	/**
	 * Check if the redirect is trashed.
	 *
	 * @return bool True if status is 'trash'.
	 */
	public function is_trashed(): bool {
		return 'trash' === $this->status;
	}

	/**
	 * Get the creation timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function created_at(): ?DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Check whether the stored row could not be read as a valid redirect.
	 *
	 * A corrupt redirect carries placeholder source/destination values: it
	 * must never be served to visitors or re-saved, but it can be listed,
	 * reported, deleted, or fully replaced.
	 *
	 * @return bool True if the redirect holds corrupt stored data.
	 */
	public function is_corrupt(): bool {
		return null !== $this->corruption;
	}

	/**
	 * Get the reason the stored row is unreadable.
	 *
	 * @return string|null The corruption reason, or null if healthy.
	 */
	public function corruption(): ?string {
		return $this->corruption;
	}

	/**
	 * Create a copy with a new ID (used after persisting).
	 *
	 * @param int $id The new ID.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->source,
			$this->destination,
			$this->status,
			$this->created_at,
			$this->corruption
		);
	}

	/**
	 * Create a copy with a new status.
	 *
	 * @param string $status The new status ('publish', 'draft', or 'trash').
	 * @return self
	 * @throws InvalidArgumentException If the status is not a known status.
	 */
	public function with_status( string $status ): self {
		if ( ! in_array( $status, array( 'publish', 'draft', 'trash' ), true ) ) {
			throw new InvalidArgumentException( "The status must be 'publish', 'draft', or 'trash'." );
		}

		return new self(
			$this->id,
			$this->source,
			$this->destination,
			$status,
			$this->created_at,
			$this->corruption
		);
	}

	/**
	 * Publish this redirect (set status to publish).
	 *
	 * @return self
	 */
	public function publish(): self {
		return $this->with_status( 'publish' );
	}

	/**
	 * Trash this redirect (set status to trash).
	 *
	 * @return self
	 */
	public function trash(): self {
		return $this->with_status( 'trash' );
	}

	/**
	 * Create a copy with a new destination.
	 *
	 * @param Destination $destination The new destination.
	 * @return self
	 */
	public function with_destination( Destination $destination ): self {
		return new self(
			$this->id,
			$this->source,
			$destination,
			$this->status,
			$this->created_at,
			$this->corruption
		);
	}

	/**
	 * Create a copy with a new source URL.
	 *
	 * Note: Changing the source URL is an unusual operation, typically done
	 * only from admin edit screens. This invalidates the old cache entry.
	 *
	 * @param SourceUrl $source The new source URL.
	 * @return self
	 */
	public function with_source( SourceUrl $source ): self {
		return new self(
			$this->id,
			$source,
			$this->destination,
			$this->status,
			$this->created_at,
			$this->corruption
		);
	}
}
