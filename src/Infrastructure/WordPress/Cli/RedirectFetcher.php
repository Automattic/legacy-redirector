<?php
/**
 * Redirect fetcher for CLI commands.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Resolves CLI redirect identifiers to Redirect entities.
 *
 * An identifier is either a numeric redirect ID or a source path.
 * The two are unambiguous because source paths always start with a slash.
 */
final class RedirectFetcher {

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
	 * Fetch a redirect by ID or source path.
	 *
	 * @param string $identifier A numeric redirect ID or a source path.
	 * @return Redirect|null The redirect, or null if not found.
	 *
	 * @throws \InvalidArgumentException If the identifier is not a valid ID or source path.
	 */
	public function fetch( string $identifier ): ?Redirect {
		if ( ctype_digit( $identifier ) ) {
			return $this->repository->find_by_id( (int) $identifier );
		}

		$source      = SourceUrl::from_string( $identifier );
		$redirect_id = $this->repository->get_id_by_source( $source );

		return $redirect_id > 0 ? $this->repository->find_by_id( $redirect_id ) : null;
	}
}
