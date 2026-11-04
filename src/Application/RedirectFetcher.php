<?php
/**
 * Redirect fetcher.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Resolves redirect identifiers to Redirect entities.
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
	 * A path a disabled duplicate shares with the live redirect is refused.
	 * It would resolve to the live one, whichever spelling was typed, and the
	 * usual reason to name a duplicate is to delete it.
	 *
	 * @throws AmbiguousSourceException If more than one redirect has the source. An invalid source path
	 *                                  throws the Domain's InvalidRedirectValue.
	 */
	public function fetch( string $identifier ): ?Redirect {
		if ( ctype_digit( $identifier ) ) {
			return $this->repository->find_by_id( (int) $identifier );
		}

		$source      = SourceUrl::from_string( $identifier, HomePath::current() );
		$redirect_id = $this->repository->get_id_by_source( $source );

		if ( 0 === $redirect_id ) {
			return null;
		}

		$duplicate_ids = $this->repository->duplicate_ids( $redirect_id );

		if ( array() !== $duplicate_ids ) {
			$ids     = array_merge( array( $redirect_id ), $duplicate_ids );
			$message = sprintf(
				/* translators: 1: number of redirects, always two or more; 2: list of their IDs, such as "7 and 9" */
				_n( '%1$s redirect has this source, IDs %2$s. Name one by its ID.', '%1$s redirects share this source, IDs %2$s. Name one by its ID.', count( $ids ), 'legacy-redirector' ),
				number_format_i18n( count( $ids ) ),
				wp_sprintf_l( '%l', $ids )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw new AmbiguousSourceException( $message );
		}

		return $this->repository->find_by_id( $redirect_id );
	}
}
