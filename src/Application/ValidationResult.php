<?php
/**
 * Validation result value object.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * Immutable value object representing the result of a validation operation.
 *
 * Can be either valid (no errors) or invalid (with error code and message).
 */
final class ValidationResult {

	/**
	 * Whether the validation passed.
	 *
	 * @var bool
	 */
	private bool $is_valid;

	/**
	 * The error code if invalid.
	 *
	 * @var string|null
	 */
	private ?string $error_code;

	/**
	 * The error message if invalid.
	 *
	 * @var string|null
	 */
	private ?string $error_message;

	/**
	 * Private constructor - use named constructors.
	 *
	 * @param bool        $is_valid      Whether valid.
	 * @param string|null $error_code    Error code.
	 * @param string|null $error_message Error message.
	 */
	private function __construct( bool $is_valid, ?string $error_code, ?string $error_message ) {
		$this->is_valid      = $is_valid;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Create a valid result.
	 *
	 * @return self
	 */
	public static function valid(): self {
		return new self( true, null, null );
	}

	/**
	 * Create an invalid result with error details.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 * @return self
	 */
	public static function invalid( string $code, string $message ): self {
		return new self( false, $code, $message );
	}

	/**
	 * Check if the validation passed.
	 *
	 * @return bool True if valid.
	 */
	public function is_valid(): bool {
		return $this->is_valid;
	}

	/**
	 * Check if the validation failed.
	 *
	 * @return bool True if invalid.
	 */
	public function is_invalid(): bool {
		return ! $this->is_valid;
	}

	/**
	 * Get the error code.
	 *
	 * @return string|null The error code, or null if valid.
	 */
	public function error_code(): ?string {
		return $this->error_code;
	}

	/**
	 * Get the error message.
	 *
	 * @return string|null The error message, or null if valid.
	 */
	public function error_message(): ?string {
		return $this->error_message;
	}
}
