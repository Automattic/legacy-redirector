<?php
/**
 * Redirect creation result.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * Result object for redirect creation and update operations.
 *
 * Encapsulates either a successful write (with redirect ID) or
 * a validation/error failure with details.
 */
final class RedirectCreationResult {

	/**
	 * The redirect ID if successful.
	 *
	 * @var int|null
	 */
	private ?int $redirect_id;

	/**
	 * The error code if failed.
	 *
	 * @var string|null
	 */
	private ?string $error_code;

	/**
	 * The error message if failed.
	 *
	 * @var string|null
	 */
	private ?string $error_message;

	/**
	 * Private constructor - use static factory methods.
	 *
	 * @param int|null    $redirect_id   The redirect ID if successful.
	 * @param string|null $error_code    The error code if failed.
	 * @param string|null $error_message The error message if failed.
	 */
	private function __construct( ?int $redirect_id, ?string $error_code, ?string $error_message ) {
		$this->redirect_id   = $redirect_id;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Create a successful result.
	 *
	 * @param int $redirect_id The created redirect ID.
	 * @return self
	 */
	public static function success( int $redirect_id ): self {
		return new self( $redirect_id, null, null );
	}

	/**
	 * Create an error result.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 * @return self
	 */
	public static function error( string $code, string $message ): self {
		return new self( null, $code, $message );
	}

	/**
	 * Create a result from a validation result.
	 *
	 * @param ValidationResult $validation The validation result.
	 * @return self
	 *
	 * @throws \InvalidArgumentException If validation is valid (only errors can be converted).
	 */
	public static function from_validation( ValidationResult $validation ): self {
		if ( $validation->is_valid() ) {
			throw new \InvalidArgumentException( 'Cannot create error result from valid validation.' );
		}

		return new self( null, $validation->error_code(), $validation->error_message() );
	}

	/**
	 * Check if the result is successful.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return null !== $this->redirect_id;
	}

	/**
	 * Check if the result is an error.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return null === $this->redirect_id;
	}

	/**
	 * Get the redirect ID.
	 *
	 * @return int|null The redirect ID, or null if failed.
	 */
	public function redirect_id(): ?int {
		return $this->redirect_id;
	}

	/**
	 * Get the error code.
	 *
	 * @return string|null The error code, or null if successful.
	 */
	public function error_code(): ?string {
		return $this->error_code;
	}

	/**
	 * Get the error message.
	 *
	 * @return string|null The error message, or null if successful.
	 */
	public function error_message(): ?string {
		return $this->error_message;
	}

	/**
	 * Convert to WP_Error if this is an error result.
	 *
	 * @return \WP_Error|null WP_Error instance, or null if successful.
	 */
	public function to_wp_error(): ?\WP_Error {
		if ( $this->is_success() ) {
			return null;
		}

		return new \WP_Error( $this->error_code, $this->error_message );
	}
}
