<?php
/**
 * Destination value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

use InvalidArgumentException;

/**
 * Immutable value object representing a redirect destination.
 *
 * A destination can be either a URL (external or relative path) or
 * an internal post ID. This class wraps both types and provides
 * type-safe access to the underlying value.
 */
final class Destination {

	/**
	 * The destination URL (if URL type).
	 *
	 * @var DestinationUrl|null
	 */
	private ?DestinationUrl $url;

	/**
	 * The destination post ID (if post type).
	 *
	 * @var DestinationPostId|null
	 */
	private ?DestinationPostId $post_id;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param DestinationUrl|null    $url     The URL destination.
	 * @param DestinationPostId|null $post_id The post ID destination.
	 */
	private function __construct( ?DestinationUrl $url, ?DestinationPostId $post_id ) {
		$this->url     = $url;
		$this->post_id = $post_id;
	}

	/**
	 * Create a Destination from a URL.
	 *
	 * @param DestinationUrl $url The destination URL.
	 * @return self
	 */
	public static function from_url( DestinationUrl $url ): self {
		return new self( $url, null );
	}

	/**
	 * Create a Destination from a post ID.
	 *
	 * @param DestinationPostId $post_id The destination post ID.
	 * @return self
	 */
	public static function from_post_id( DestinationPostId $post_id ): self {
		return new self( null, $post_id );
	}

	/**
	 * Create a Destination from a mixed value.
	 *
	 * Automatically determines if the value is a post ID (numeric) or URL.
	 *
	 * @param int|string $value The destination value.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the value cannot be parsed.
	 */
	public static function from_mixed( int|string $value ): self {
		// Numeric values are treated as post IDs.
		if ( is_numeric( $value ) ) {
			return self::from_post_id( DestinationPostId::from_mixed( $value ) );
		}

		// String values are treated as URLs.
		return self::from_url( DestinationUrl::from_string( (string) $value ) );
	}

	/**
	 * Check if this is a URL destination.
	 *
	 * @return bool True if the destination is a URL.
	 */
	public function is_url(): bool {
		return null !== $this->url;
	}

	/**
	 * Check if this is a post ID destination.
	 *
	 * @return bool True if the destination is a post ID.
	 */
	public function is_post_id(): bool {
		return null !== $this->post_id;
	}

	/**
	 * Get the URL destination.
	 *
	 * @return DestinationUrl
	 *
	 * @throws \LogicException If this is not a URL destination.
	 */
	public function as_url(): DestinationUrl {
		if ( null === $this->url ) {
			throw new \LogicException( 'Cannot get URL from a post ID destination.' );
		}
		return $this->url;
	}

	/**
	 * Get the post ID destination.
	 *
	 * @return DestinationPostId
	 *
	 * @throws \LogicException If this is not a post ID destination.
	 */
	public function as_post_id(): DestinationPostId {
		if ( null === $this->post_id ) {
			throw new \LogicException( 'Cannot get post ID from a URL destination.' );
		}
		return $this->post_id;
	}

	/**
	 * Get the raw value (URL string or post ID integer).
	 *
	 * @return string|int The raw destination value.
	 */
	public function raw_value(): string|int {
		if ( $this->is_url() ) {
			return $this->url->value();
		}
		return $this->post_id->value();
	}

	/**
	 * String representation of the Destination.
	 *
	 * @return string The destination as a string.
	 */
	public function __toString(): string {
		return (string) $this->raw_value();
	}
}
