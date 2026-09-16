<?php
/**
 * DestinationPostId value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use InvalidArgumentException;

/**
 * Immutable value object representing a redirect destination post ID.
 *
 * Encapsulates internal redirects that point to a WordPress post by ID.
 * Validates that the ID is a positive integer. Post existence validation
 * should be done at the application layer.
 */
final class DestinationPostId {

	/**
	 * The post ID.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param int $post_id The post ID.
	 */
	private function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}

	/**
	 * Create a DestinationPostId from an integer.
	 *
	 * @param int $post_id The post ID.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the post ID is not positive.
	 */
	public static function from_int( int $post_id ): self {
		if ( $post_id <= 0 ) {
			throw new InvalidArgumentException( 'Post ID must be a positive integer.' );
		}

		return new self( $post_id );
	}

	/**
	 * Create a DestinationPostId from a mixed value.
	 *
	 * Accepts integers or numeric strings.
	 *
	 * @param int|string $value The post ID value.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the value is not numeric or not positive.
	 */
	public static function from_mixed( int|string $value ): self {
		if ( ! is_numeric( $value ) ) {
			throw new InvalidArgumentException( 'Post ID must be numeric.' );
		}

		return self::from_int( (int) $value );
	}

	/**
	 * Get the post ID value.
	 *
	 * @return int The post ID.
	 */
	public function value(): int {
		return $this->post_id;
	}

	/**
	 * String representation of the DestinationPostId.
	 *
	 * @return string The post ID as a string.
	 */
	public function __toString(): string {
		return (string) $this->post_id;
	}
}
