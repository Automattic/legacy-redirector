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

use Automattic\LegacyRedirector\Application\RedirectExecutor;
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
	 * Get the uncached redirect repository.
	 *
	 * @return PostTypeRedirectRepository The repository.
	 */
	protected function inner_repository(): PostTypeRedirectRepository {
		return $this->services['inner_repository'] ??= new PostTypeRedirectRepository();
	}

	/**
	 * Get the caching redirect repository.
	 *
	 * @return CachingRedirectRepository The caching repository.
	 */
	protected function repository(): CachingRedirectRepository {
		return $this->services['repository'] ??= new CachingRedirectRepository( $this->inner_repository() );
	}

	/**
	 * Get the redirect manager.
	 *
	 * @return RedirectManager The manager.
	 */
	protected function manager(): RedirectManager {
		return $this->services['manager'] ??= new RedirectManager( $this->inner_repository() );
	}

	/**
	 * Get the redirect validator.
	 *
	 * @return RedirectValidator The validator.
	 */
	protected function validator(): RedirectValidator {
		return $this->services['validator'] ??= new RedirectValidator( $this->inner_repository() );
	}

	/**
	 * Get the redirect executor.
	 *
	 * @return RedirectExecutor The executor.
	 */
	protected function executor(): RedirectExecutor {
		return $this->services['executor'] ??= new RedirectExecutor( $this->repository(), 'WPCOM Legacy Redirector' );
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
		$source      = SourceUrl::from_string( $from );
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
		$source      = SourceUrl::from_string( $from );
		$destination = Destination::from_mixed( $to );

		return $manager->create_redirect( $source, $destination, $validate );
	}
}
