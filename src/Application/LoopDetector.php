<?php
/**
 * Redirect loop detector.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;

/**
 * Finds cycles through stored redirects.
 *
 * A redirect's relative destination can itself be another redirect's source;
 * following those hops can lead back to where it started, and when every
 * source in that cycle returns a 404, a visitor bounces between them until
 * the browser gives up. This walks the hops the way the request-time resolver
 * would: the destination converted to source form and looked up publish-only,
 * because a disabled or trashed redirect never fires and so cannot be a hop.
 *
 * The auditor's own collaborator: it lives outside RedirectAuditor only
 * because it needs the repository, which no other audit rule does.
 */
class LoopDetector {

	/**
	 * How many hops to follow before giving up.
	 *
	 * A real cycle almost always closes in two or three hops; the cap only
	 * exists so a long redirect chain costs a bounded number of lookups.
	 *
	 * @var int
	 */
	private const int MAX_HOPS = 10;

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
	 * Find the cycle a redirect is part of, if any.
	 *
	 * Only a cycle that passes back through the given redirect is returned:
	 * a cycle met further along the walk belongs to its own members, each of
	 * which reports it when audited, so reporting it here too would flag
	 * every redirect that merely points at a loop.
	 *
	 * @param Redirect $redirect The redirect to start from, stored or proposed.
	 * @return string[] The source paths of the cycle in hop order, ending back
	 *                  at the start; empty when there is no cycle.
	 */
	public function find_cycle( Redirect $redirect ): array {
		$start_path = $redirect->source()->path();
		$trail      = array( $start_path );
		$current    = $redirect;

		for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
			$next_source = $this->destination_as_source( $current );

			if ( null === $next_source ) {
				return array();
			}

			$next_path = $next_source->path();

			if ( $next_path === $start_path ) {
				$trail[] = $next_path;
				return $trail;
			}

			if ( in_array( $next_path, $trail, true ) ) {
				// A cycle, but not through the start.
				return array();
			}

			$next = $this->repository->find_by_source( $next_source );

			if ( null === $next || $next->is_corrupt() ) {
				return array();
			}

			$trail[] = $next_path;
			$current = $next;
		}

		return array();
	}

	/**
	 * Follow the redirect chain to its last member.
	 *
	 * Walks the same hops as find_cycle() and returns the final redirect
	 * whose destination is not another stored redirect's source - the one
	 * whose destination decides where the whole chain actually lands. Returns
	 * null when the walk meets a cycle or exceeds the hop cap, since such a
	 * chain lands nowhere.
	 *
	 * @param Redirect $redirect The redirect to start from.
	 * @return Redirect|null The chain's last member (the start itself when its
	 *                       destination is no redirect's source), or null.
	 */
	public function follow( Redirect $redirect ): ?Redirect {
		$trail   = array( $redirect->source()->path() );
		$current = $redirect;

		for ( $hop = 0; $hop < self::MAX_HOPS; $hop++ ) {
			$next_source = $this->destination_as_source( $current );

			if ( null === $next_source ) {
				return $current;
			}

			if ( in_array( $next_source->path(), $trail, true ) ) {
				return null;
			}

			$next = $this->repository->find_by_source( $next_source );

			if ( null === $next || $next->is_corrupt() ) {
				return $current;
			}

			$trail[] = $next_source->path();
			$current = $next;
		}

		return null;
	}

	/**
	 * Convert a redirect's destination into the source form a request for it
	 * would be looked up by.
	 *
	 * Post IDs and absolute URLs end the walk: a post ID destination serves
	 * that post's permalink, and internal absolute URLs are normalized to
	 * relative on save, so an absolute destination is on another host.
	 *
	 * @param Redirect $redirect The redirect whose destination to convert.
	 * @return SourceUrl|null The destination as a source, or null when the walk ends.
	 */
	private function destination_as_source( Redirect $redirect ): ?SourceUrl {
		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			return null;
		}

		$url = $destination->as_url()->value();

		if ( ! $destination->as_url()->is_relative() ) {
			return null;
		}

		try {
			return SourceUrl::from_string( $url, HomePath::current() );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}
}
