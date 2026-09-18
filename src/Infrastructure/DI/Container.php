<?php
/**
 * Service container for dependency injection.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\DI
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\DI;

use Automattic\LegacyRedirector\Application\LoopDetector;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectResolver;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Simple service container for the plugin.
 *
 * Provides lazy-loaded access to the plugin's services with proper
 * dependency injection. Services are created once and reused.
 */
final class Container {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Cached services.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the container (for testing).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Private constructor - use instance().
	 */
	private function __construct() {}

	/**
	 * Get the redirect repository.
	 *
	 * Returns a caching repository wrapping the post type repository.
	 *
	 * @return RedirectRepositoryInterface
	 */
	public function repository(): RedirectRepositoryInterface {
		if ( ! isset( $this->services['repository'] ) ) {
			$inner                        = new PostTypeRedirectRepository();
			$this->services['repository'] = new CachingRedirectRepository( $inner );
		}
		return $this->services['repository'];
	}

	/**
	 * Get the redirect validator.
	 *
	 * @return RedirectValidator
	 */
	public function validator(): RedirectValidator {
		if ( ! isset( $this->services['validator'] ) ) {
			$this->services['validator'] = new RedirectValidator( $this->repository(), $this->auditor() );
		}
		return $this->services['validator'];
	}

	/**
	 * Get the redirect resolver.
	 *
	 * @return RedirectResolver
	 */
	public function resolver(): RedirectResolver {
		if ( ! isset( $this->services['resolver'] ) ) {
			$this->services['resolver'] = new RedirectResolver( $this->repository() );
		}
		return $this->services['resolver'];
	}

	/**
	 * Get the redirect manager for admin operations.
	 *
	 * @return RedirectManager
	 */
	public function manager(): RedirectManager {
		if ( ! isset( $this->services['manager'] ) ) {
			$this->services['manager'] = new RedirectManager( $this->repository() );
		}
		return $this->services['manager'];
	}

	/**
	 * Get the redirect query repository for listing operations.
	 *
	 * @return RedirectQueryRepositoryInterface
	 */
	public function query_repository(): RedirectQueryRepositoryInterface {
		if ( ! isset( $this->services['query_repository'] ) ) {
			$this->services['query_repository'] = new PostTypeRedirectQueryRepository();
		}
		return $this->services['query_repository'];
	}

	/**
	 * Get the redirect fetcher, which resolves IDs and source paths to redirects.
	 *
	 * @return RedirectFetcher
	 */
	public function fetcher(): RedirectFetcher {
		if ( ! isset( $this->services['fetcher'] ) ) {
			$this->services['fetcher'] = new RedirectFetcher( $this->repository() );
		}
		return $this->services['fetcher'];
	}

	/**
	 * Get the batch resolver, which acts on a list of redirect identifiers.
	 *
	 * @return RedirectBatch
	 */
	public function batch(): RedirectBatch {
		if ( ! isset( $this->services['batch'] ) ) {
			$this->services['batch'] = new RedirectBatch( $this->fetcher() );
		}
		return $this->services['batch'];
	}

	/**
	 * Get the redirect auditor, which checks redirects for broken destinations.
	 *
	 * @return RedirectAuditor
	 */
	public function auditor(): RedirectAuditor {
		if ( ! isset( $this->services['auditor'] ) ) {
			$this->services['auditor'] = new RedirectAuditor( new LoopDetector( $this->repository() ) );
		}
		return $this->services['auditor'];
	}

	/**
	 * Get the data upgrade routine.
	 *
	 * @return Upgrader
	 */
	public function upgrader(): Upgrader {
		if ( ! isset( $this->services['upgrader'] ) ) {
			$this->services['upgrader'] = new Upgrader();
		}
		return $this->services['upgrader'];
	}
}
