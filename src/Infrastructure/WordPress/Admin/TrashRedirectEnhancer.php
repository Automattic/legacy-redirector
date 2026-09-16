<?php
/**
 * Trash redirect enhancer.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Enhances trash redirect URLs by adding the redirect source.
 *
 * When a redirect is trashed, this adds the source path to the redirect URL
 * so that the admin notice can display which redirect was trashed.
 */
final class TrashRedirectEnhancer {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 */
	public function __construct( RedirectRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_redirect', array( $this, 'add_source_to_redirect' ) );
	}

	/**
	 * Add redirect source to the trash redirect URL for better messaging.
	 *
	 * When a single redirect is trashed, this adds the source path to the URL
	 * so we can display which redirect was trashed in the admin notice.
	 *
	 * @param string $location The redirect URL.
	 * @return string Modified redirect URL.
	 */
	public function add_source_to_redirect( string $location ): string {
		// Only process redirects to our post type edit screen with trashed=1.
		if ( ! str_contains( $location, 'post_type=' . PostType::POST_TYPE ) || ! str_contains( $location, 'trashed=1' ) ) {
			return $location;
		}

		// Extract the IDs from the URL.
		$query_string = wp_parse_url( $location, PHP_URL_QUERY );
		if ( ! $query_string ) {
			return $location;
		}

		parse_str( $query_string, $query_args );

		// Only add source for single item trash.
		if ( ! isset( $query_args['ids'] ) ) {
			return $location;
		}

		$ids = explode( ',', $query_args['ids'] );
		if ( count( $ids ) !== 1 ) {
			return $location;
		}

		// Get the redirect source from the trashed row. The repository returns
		// trashed rows from find_by_id(), and null for other post types.
		$redirect = $this->repository->find_by_id( absint( $ids[0] ) );
		if ( null === $redirect ) {
			return $location;
		}

		return add_query_arg( 'redirect_source', rawurlencode( $redirect->source()->path() ), $location );
	}
}
