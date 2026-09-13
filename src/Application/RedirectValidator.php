<?php
/**
 * Redirect validator service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;

/**
 * Service for validating redirects before persistence.
 *
 * Consolidates all validation rules for creating and updating redirects.
 */
class RedirectValidator {

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 */
	public function __construct( RedirectRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Validate a redirect for creation.
	 *
	 * Performs all validation checks required before creating a new redirect:
	 * - Source URL must not already have a redirect
	 * - Destination must be valid (post exists and is published, or URL is allowed)
	 * - Source and destination must be different
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @return ValidationResult The validation result.
	 */
	public function validate_for_creation( SourceUrl $source, Destination $destination ): ValidationResult {
		// Check for duplicate source URL.
		if ( $this->repository->exists( $source ) ) {
			return ValidationResult::invalid(
				'duplicate-redirect-uri',
				__( 'A redirect for this URI already exists', 'wpcom-legacy-redirector' )
			);
		}

		// Validate source and destination are different.
		$same_result = $this->validate_source_destination_different( $source, $destination );
		if ( $same_result->is_invalid() ) {
			return $same_result;
		}

		// Validate destination.
		return $this->validate_destination( $destination );
	}

	/**
	 * Validate a redirect for update.
	 *
	 * Performs validation checks required before updating an existing redirect.
	 *
	 * @param Redirect    $existing       The existing redirect.
	 * @param Destination $new_destination The new destination.
	 * @return ValidationResult The validation result.
	 */
	public function validate_for_update( Redirect $existing, Destination $new_destination ): ValidationResult {
		// Validate source and destination are different.
		$same_result = $this->validate_source_destination_different( $existing->source(), $new_destination );
		if ( $same_result->is_invalid() ) {
			return $same_result;
		}

		// Validate destination.
		return $this->validate_destination( $new_destination );
	}

	/**
	 * Validate that source and destination are different.
	 *
	 * Prevents redirect loops where source equals destination.
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @return ValidationResult The validation result.
	 */
	public function validate_source_destination_different( SourceUrl $source, Destination $destination ): ValidationResult {
		// If destination is a post ID, resolve to URL for comparison.
		if ( $destination->is_post_id() ) {
			$post_permalink = get_permalink( $destination->as_post_id()->value() );
			if ( false !== $post_permalink ) {
				$destination_path = wp_parse_url( $post_permalink, PHP_URL_PATH );
				if ( $destination_path && $this->normalise_path( $source->path() ) === $this->normalise_path( $destination_path ) ) {
					return ValidationResult::invalid(
						'invalid-values',
						__( '"Redirect From" and "Redirect To" values are required and should not match.', 'wpcom-legacy-redirector' )
					);
				}
			}
			return ValidationResult::valid();
		}

		// Compare source path with destination URL path.
		$destination_url  = $destination->as_url()->value();
		$parsed           = wp_parse_url( $destination_url );
		$destination_path = $parsed['path'] ?? '';

		if ( $this->normalise_path( $source->path() ) === $this->normalise_path( $destination_path ) ) {
			return ValidationResult::invalid(
				'invalid-values',
				__( '"Redirect From" and "Redirect To" values are required and should not match.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Validate a destination.
	 *
	 * Checks that the destination is valid:
	 * - For post IDs: post must exist and be published
	 * - For URLs: must be allowed by wp_validate_redirect
	 *
	 * @param Destination $destination The destination to validate.
	 * @return ValidationResult The validation result.
	 */
	public function validate_destination( Destination $destination ): ValidationResult {
		if ( $destination->is_post_id() ) {
			return $this->validate_destination_post_id( $destination->as_post_id()->value() );
		}

		return $this->validate_destination_url( $destination->as_url()->value() );
	}

	/**
	 * Validate a destination post ID.
	 *
	 * @param int $post_id The post ID.
	 * @return ValidationResult The validation result.
	 */
	public function validate_destination_post_id( int $post_id ): ValidationResult {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return ValidationResult::invalid(
				'empty-postid',
				__( 'Redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' )
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return ValidationResult::invalid(
				'non-public',
				__( 'You are trying to redirect to a post that is not published.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Validate a destination URL.
	 *
	 * @param string $url The URL to validate.
	 * @return ValidationResult The validation result.
	 */
	public function validate_destination_url( string $url ): ValidationResult {
		// Root path is always valid.
		if ( '/' === $url ) {
			return ValidationResult::valid();
		}

		// Relative paths are validated as internal redirects.
		if ( str_starts_with( $url, '/' ) ) {
			return $this->validate_relative_path( $url );
		}

		// External URLs - validate they have a valid format.
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return ValidationResult::invalid(
				'invalid-url',
				__( 'The URL is not valid. External URLs must include the scheme (http:// or https://).', 'wpcom-legacy-redirector' )
			);
		}

		// Ensure scheme is http or https.
		if ( ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			return ValidationResult::invalid(
				'invalid-scheme',
				__( 'Only http and https URLs are supported.', 'wpcom-legacy-redirector' )
			);
		}

		// External URLs with valid http/https scheme are considered valid.
		// The plugin automatically adds the destination host to the allowed_redirect_hosts
		// filter at redirect time (see RedirectExecutor::allow_redirect_host).
		return ValidationResult::valid();
	}

	/**
	 * Validate a relative path destination.
	 *
	 * Checks that the path resolves to a published post.
	 *
	 * @param string $path The relative path.
	 * @return ValidationResult The validation result.
	 */
	public function validate_relative_path( string $path ): ValidationResult {
		$post_types = get_post_types();
		$post       = get_page_by_path( ltrim( $path, '/' ), OBJECT, $post_types );

		if ( null === $post ) {
			return ValidationResult::invalid(
				'invalid',
				__( 'You are trying to redirect to a URL that does not exist.', 'wpcom-legacy-redirector' )
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return ValidationResult::invalid(
				'non-public',
				__( 'You are trying to redirect to a URL that is currently not public.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Validate that the source URL returns a 404.
	 *
	 * This is an optional validation that can be used to ensure
	 * redirects are only created for non-existent URLs.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return ValidationResult The validation result.
	 */
	public function validate_source_is_404( SourceUrl $source ): ValidationResult {
		$url           = home_url( $source->path() );
		$response_code = $this->get_response_code( $url );

		if ( 404 !== $response_code ) {
			return ValidationResult::invalid(
				'non-404',
				__( 'Redirects need to be from URLs that have a 404 status.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Validate that the source URL is not currently private.
	 *
	 * @param SourceUrl $source The source URL.
	 * @return ValidationResult The validation result.
	 */
	public function validate_source_not_private( SourceUrl $source ): ValidationResult {
		$post_types = get_post_types();
		$post       = get_page_by_path( ltrim( $source->path(), '/' ), OBJECT, $post_types );

		if ( null !== $post && 'publish' !== $post->post_status ) {
			return ValidationResult::invalid(
				'private-url',
				__( 'You are trying to redirect from a URL that is currently private.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Validate that the destination does not return a 404.
	 *
	 * Performs an HTTP request to check the destination is reachable.
	 *
	 * @param Destination $destination The destination to check.
	 * @return ValidationResult The validation result.
	 */
	public function validate_destination_not_404( Destination $destination ): ValidationResult {
		$url = $this->resolve_destination_url( $destination );

		if ( null === $url ) {
			return ValidationResult::invalid(
				'404',
				__( 'Redirect is pointing to a page with the HTTP status of 404.', 'wpcom-legacy-redirector' )
			);
		}

		$response_code = $this->get_response_code( $url );

		if ( 404 === $response_code ) {
			return ValidationResult::invalid(
				'404',
				__( 'Redirect is pointing to a page with the HTTP status of 404.', 'wpcom-legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * Resolve a destination to a full URL.
	 *
	 * @param Destination $destination The destination.
	 * @return string|null The resolved URL, or null if unresolvable.
	 */
	public function resolve_destination_url( Destination $destination ): ?string {
		if ( $destination->is_post_id() ) {
			$post_id   = $destination->as_post_id()->value();
			$permalink = get_permalink( $post_id );
			return false !== $permalink ? $permalink : null;
		}

		$url = $destination->as_url()->value();

		// Relative paths need to be resolved against home URL.
		if ( str_starts_with( $url, '/' ) ) {
			return home_url( $url );
		}

		return $url;
	}

	/**
	 * Normalise a path for comparison.
	 *
	 * @param string $path The path.
	 * @return string Normalised path.
	 */
	private function normalise_path( string $path ): string {
		return strtolower( trim( $path, '/' ) );
	}

	/**
	 * Get the HTTP response code for a URL.
	 *
	 * @param string $url The URL to check.
	 * @return int The response code, or 0 on error.
	 */
	protected function get_response_code( string $url ): int {
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url );
		} else {
			$response = wp_remote_get( $url );
			// Retry without SSL verification for self-signed certificates.
			if ( is_wp_error( $response ) ) {
				$response = wp_remote_get( $url, array( 'sslverify' => false ) );
			}
		}

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	// =========================================================================
	// Destination validation for existing redirects (used by validate command)
	// =========================================================================

	/**
	 * Validate an existing redirect's destination.
	 *
	 * Checks if the redirect's destination is still valid:
	 * - For post IDs: checks if post exists and is published
	 * - For relative paths: checks if path resolves to a published post
	 * - For URLs: optionally checks if URL returns success response
	 *
	 * @param Redirect $redirect   The redirect to validate.
	 * @param bool     $check_urls Whether to make HTTP requests to check URL destinations.
	 * @return ValidationIssue|null The issue if broken, null if valid.
	 */
	public function validate_redirect_destination( Redirect $redirect, bool $check_urls = false ): ?ValidationIssue {
		$destination = $redirect->destination();

		// Check post ID destinations.
		if ( $destination->is_post_id() ) {
			return $this->check_post_destination( $redirect, $destination->as_post_id()->value() );
		}

		// Check URL destinations.
		$url = $destination->as_url()->value();

		if ( empty( $url ) ) {
			return new ValidationIssue( $redirect, ValidationIssueType::EMPTY_DESTINATION );
		}

		// Relative paths.
		if ( $this->is_relative_path( $url ) ) {
			return $this->check_relative_path_destination( $redirect, $url, $check_urls );
		}

		// External URLs - only check if requested.
		if ( $check_urls ) {
			return $this->check_url_destination( $redirect, $url );
		}

		return null;
	}

	/**
	 * Check if a post ID destination is valid.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param int      $post_id  The destination post ID.
	 * @return ValidationIssue|null The issue if broken, null if valid.
	 */
	private function check_post_destination( Redirect $redirect, int $post_id ): ?ValidationIssue {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return new ValidationIssue( $redirect, ValidationIssueType::POST_DELETED );
		}

		if ( 'trash' === $post->post_status ) {
			return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
		}

		if ( 'publish' !== $post->post_status ) {
			return new ValidationIssue(
				$redirect,
				ValidationIssueType::POST_UNPUBLISHED,
				'status: ' . $post->post_status
			);
		}

		return null;
	}

	/**
	 * Check if a relative path destination is valid.
	 *
	 * @param Redirect $redirect   The redirect.
	 * @param string   $path       The relative path.
	 * @param bool     $check_urls Whether to check via HTTP if path lookup fails.
	 * @return ValidationIssue|null The issue if broken, null if valid.
	 */
	private function check_relative_path_destination( Redirect $redirect, string $path, bool $check_urls ): ?ValidationIssue {
		// Try to find a post by path.
		$post = get_page_by_path( ltrim( $path, '/' ), OBJECT, array( 'post', 'page' ) );

		// Trashing renames post_name with a __trashed suffix, so a direct
		// lookup misses trashed destinations - check for the renamed slug.
		if ( null === $post ) {
			$trashed = get_page_by_path( ltrim( $path, '/' ) . '__trashed', OBJECT, array( 'post', 'page' ) );
			if ( null !== $trashed && 'trash' === $trashed->post_status ) {
				return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
			}
		}

		if ( null !== $post ) {
			if ( 'trash' === $post->post_status ) {
				return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
			}
			if ( 'publish' !== $post->post_status ) {
				return new ValidationIssue(
					$redirect,
					ValidationIssueType::POST_UNPUBLISHED,
					'status: ' . $post->post_status
				);
			}
			return null;
		}

		// If URL checking is enabled, verify via HTTP.
		if ( $check_urls ) {
			$full_url = home_url( $path );
			return $this->check_url_destination( $redirect, $full_url );
		}

		// Can't determine without HTTP check - assume OK.
		return null;
	}

	/**
	 * Check if a URL destination is reachable.
	 *
	 * @param Redirect $redirect The redirect.
	 * @param string   $url      The destination URL.
	 * @return ValidationIssue|null The issue if broken, null if valid.
	 */
	private function check_url_destination( Redirect $redirect, string $url ): ?ValidationIssue {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0, // Don't follow redirects.
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new ValidationIssue(
				$redirect,
				ValidationIssueType::URL_REQUEST_FAILED,
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $status_code ) {
			return new ValidationIssue( $redirect, ValidationIssueType::URL_NOT_FOUND );
		}

		if ( $status_code >= 500 ) {
			return new ValidationIssue(
				$redirect,
				ValidationIssueType::URL_SERVER_ERROR,
				'status: ' . $status_code
			);
		}

		return null;
	}

	/**
	 * Check if a URL is a relative path (not an absolute URL).
	 *
	 * @param string $url The URL to check.
	 * @return bool True if relative path.
	 */
	private function is_relative_path( string $url ): bool {
		return ! preg_match( '#^https?://#i', $url );
	}

	/**
	 * Validate a batch of redirects.
	 *
	 * @param Redirect[] $redirects         The redirects to validate.
	 * @param bool       $check_urls        Whether to check URL destinations.
	 * @param callable   $progress_callback Optional callback called after each redirect (receives count).
	 * @return ValidationIssue[] Array of validation issues found.
	 */
	public function validate_batch( array $redirects, bool $check_urls = false, ?callable $progress_callback = null ): array {
		$issues  = array();
		$checked = 0;

		foreach ( $redirects as $redirect ) {
			++$checked;

			$issue = $this->validate_redirect_destination( $redirect, $check_urls );
			if ( null !== $issue ) {
				$issues[] = $issue;
			}

			if ( null !== $progress_callback ) {
				$progress_callback( $checked );
			}

			// Memory cleanup every 100 items.
			if ( 0 === $checked % 100 ) {
				$this->cleanup_memory();
			}
		}

		return $issues;
	}

	/**
	 * Clean up memory during batch operations.
	 *
	 * @return void
	 */
	private function cleanup_memory(): void {
		if ( function_exists( 'stop_the_insanity' ) ) {
			stop_the_insanity();
		}
	}
}
