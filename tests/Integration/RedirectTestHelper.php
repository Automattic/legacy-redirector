<?php
/**
 * Redirect test helper trait.
 *
 * Provides helper methods for creating redirects in integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectResolver;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;

/**
 * Trait providing helper methods for redirect operations in tests.
 *
 * Builds plugin services directly, mirroring the production Container wiring,
 * so tests construct exactly what they use instead of routing through the
 * DI container singleton. Services are memoised per test instance.
 */
trait RedirectTestHelper {

	/**
	 * Memoised service instances.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Get the caching redirect repository.
	 *
	 * @return CachingRedirectRepository The caching repository.
	 */
	protected function repository(): CachingRedirectRepository {
		return $this->services['repository'] ??= new CachingRedirectRepository( new PostTypeRedirectRepository() );
	}

	/**
	 * Get the redirect manager.
	 *
	 * @return RedirectManager The manager.
	 */
	protected function manager(): RedirectManager {
		return $this->services['manager'] ??= new RedirectManager( $this->repository() );
	}

	/**
	 * Get the redirect validator.
	 *
	 * @return RedirectValidator The validator.
	 */
	protected function validator(): RedirectValidator {
		return $this->services['validator'] ??= new RedirectValidator( $this->repository() );
	}

	/**
	 * Get the redirect resolver.
	 *
	 * @return RedirectResolver The resolver.
	 */
	protected function resolver(): RedirectResolver {
		return $this->services['resolver'] ??= new RedirectResolver( $this->repository() );
	}

	/**
	 * Get the redirect query repository.
	 *
	 * @return PostTypeRedirectQueryRepository The query repository.
	 */
	protected function query_repository(): PostTypeRedirectQueryRepository {
		return $this->services['query_repository'] ??= new PostTypeRedirectQueryRepository();
	}

	/**
	 * Create a redirect using the new API.
	 *
	 * @param string     $from     The source URL path.
	 * @param string|int $to       The destination URL or post ID.
	 * @param bool       $validate Whether to validate the redirect (default false).
	 * @return int The redirect post ID.
	 *
	 * @throws \RuntimeException If the redirect could not be created.
	 */
	protected function create_redirect( string $from, $to, bool $validate = false ): int {
		$manager     = $this->manager();
		$source      = SourceUrl::from_string( $from, HomePath::current() );
		$destination = Destination::from_mixed( $to );
		$result      = $manager->create_redirect( $source, $destination, $validate );

		if ( $result->is_error() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not output to browser.
			throw new \RuntimeException( $result->error_message() );
		}

		return $result->redirect_id();
	}

	/**
	 * Create a redirect and return boolean success (mimics legacy API).
	 *
	 * @param string     $from     The source URL path.
	 * @param string|int $to       The destination URL or post ID.
	 * @param bool       $validate Whether to validate the redirect (default false).
	 * @return bool True on success.
	 */
	protected function create_redirect_bool( string $from, $to, bool $validate = false ): bool {
		try {
			$this->create_redirect( $from, $to, $validate );
			return true;
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Create a redirect and return the result object.
	 *
	 * Useful when testing error conditions.
	 *
	 * @param string     $from     The source URL path.
	 * @param string|int $to       The destination URL or post ID.
	 * @param bool       $validate Whether to validate the redirect (default false).
	 * @return \Automattic\LegacyRedirector\Application\RedirectCreationResult The result.
	 */
	protected function create_redirect_result( string $from, $to, bool $validate = false ) {
		$manager     = $this->manager();
		$source      = SourceUrl::from_string( $from, HomePath::current() );
		$destination = Destination::from_mixed( $to );

		return $manager->create_redirect( $source, $destination, $validate );
	}

	/**
	 * Insert a redirect row directly, bypassing all plugin validation.
	 *
	 * For corrupt-row fixtures that the plugin itself would refuse to create
	 * (hand-edited rows, partial 1.x imports).
	 *
	 * @param array<string, mixed> $args Overrides for the wp_insert_post args.
	 * @return int The post ID.
	 *
	 * @throws \RuntimeException If the insert fails, so a bad fixture cannot pass a test vacuously.
	 */
	protected function insert_redirect_post( array $args = array() ): int {
		$post_id = wp_insert_post(
			array_merge(
				array(
					'post_type'    => \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_excerpt' => 'https://example.com/destination',
				),
				$args
			),
			true
		);

		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is not output to browser.
			throw new \RuntimeException( 'Failed to insert redirect post fixture.' );
		}

		return $post_id;
	}

	/**
	 * Drop the cached lookup entry for a source path.
	 *
	 * Goes through the repository's own key builder, because a key built by
	 * hand deletes nothing when it misses the blog ID prefix, and a test whose
	 * cache clear silently did nothing still passes - for the wrong reason.
	 *
	 * No home path is passed, mirroring the resolver's hot path: what it
	 * caches is keyed on a bare request path, so stripping here would build a
	 * key for an entry that was never written.
	 *
	 * @param string $from The source path.
	 * @return void
	 */
	protected function clear_lookup_cache( string $from ): void {
		wp_cache_delete(
			CachingRedirectRepository::cache_key( SourceUrl::from_string( $from )->hash() ),
			CachingRedirectRepository::CACHE_GROUP
		);
	}
}
