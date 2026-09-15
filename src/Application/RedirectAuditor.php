<?php
/**
 * Redirect auditor service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;

/**
 * Service for auditing existing redirects.
 *
 * Where RedirectValidator gatekeeps proposed redirects before persistence,
 * this service inspects redirects that are already stored and reports broken
 * destinations (deleted, trashed, or unpublished posts; unreachable URLs) as
 * ValidationIssue records. Used by the `validate` WP-CLI command.
 */
class RedirectAuditor {

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

		// The raw property, so a trashed post is reported as trashed rather
		// than resolved through get_post_status()'s attachment rules, which
		// report an attachment of a trashed parent by its pre-trash status.
		if ( 'trash' === $post->post_status ) {
			return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
		}

		// Attachments store 'inherit', never 'publish'; get_post_status()
		// resolves that against the parent, so a redirect to a media item is
		// not reported as unpublished.
		$status = get_post_status( $post );

		if ( 'publish' !== $status ) {
			return new ValidationIssue(
				$redirect,
				ValidationIssueType::POST_UNPUBLISHED,
				'status: ' . $status
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
		$slug = trim( $path, '/' );

		// The home page has no slug to look up. get_page_by_path( '' ) matches
		// any post with an empty post_name - every draft and pending post has
		// one - so looking it up would report an arbitrary, unrelated post's
		// status as this redirect's problem. Only an HTTP check can say
		// anything about '/'.
		if ( '' === $slug ) {
			return $check_urls ? $this->check_url_destination( $redirect, home_url( $path ) ) : null;
		}

		// Try to find a post by path.
		$post = get_page_by_path( $slug, OBJECT, array( 'post', 'page' ) );

		// Trashing renames post_name with a __trashed suffix, so a direct
		// lookup misses trashed destinations - check for the renamed slug.
		if ( null === $post ) {
			$trashed = get_page_by_path( $slug . '__trashed', OBJECT, array( 'post', 'page' ) );
			if ( null !== $trashed && 'trash' === $trashed->post_status ) {
				return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
			}
		}

		if ( null !== $post ) {
			if ( 'trash' === $post->post_status ) {
				return new ValidationIssue( $redirect, ValidationIssueType::POST_TRASHED );
			}
			$status = get_post_status( $post );
			if ( 'publish' !== $status ) {
				return new ValidationIssue(
					$redirect,
					ValidationIssueType::POST_UNPUBLISHED,
					'status: ' . $status
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
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0, // Don't follow redirects.
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
