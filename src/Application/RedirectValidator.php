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
		// filter at redirect time (see RedirectRequestHandler::allow_redirect_host).
		return ValidationResult::valid();
	}

	/**
	 * Validate a relative path destination.
	 *
	 * When the path resolves to a post, its status is checked. A path that
	 * resolves to no post at all is indeterminate rather than invalid:
	 * archives, rewrite endpoints, dated permalinks with structures the slug
	 * walk cannot follow, and URLs served outside WordPress are all real
	 * destinations with no post to find. Reporting those as broken would be
	 * wrong far more often than it would be right; the HTTP 404 check
	 * (validate_destination_not_404()) is the authority on reachability, and
	 * both admin validation flows run it directly after this check.
	 *
	 * @param string $path The relative path.
	 * @return ValidationResult The validation result.
	 */
	public function validate_relative_path( string $path ): ValidationResult {
		// A query string or fragment can never be part of a slug match.
		$slug_path = (string) strtok( $path, '?#' );

		$post_types = get_post_types();
		$post       = get_page_by_path( ltrim( $slug_path, '/' ), OBJECT, $post_types );

		// get_page_by_path() only walks hierarchical slugs; url_to_postid()
		// resolves anything matching the site's permalink structure, such as
		// dated permalinks.
		if ( null === $post ) {
			$post_id = url_to_postid( home_url( $slug_path ) );
			$post    = 0 !== $post_id ? get_post( $post_id ) : null;
		}

		if ( null === $post ) {
			return ValidationResult::valid();
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
			$response = vip_safe_wp_remote_get( $url, '', 3, 1, 20, array( 'reject_unsafe_urls' => true ) );
		} else {
			$response = wp_safe_remote_get( $url );
		}

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}
}
