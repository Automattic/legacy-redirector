<?php
/**
 * Shared WP_Post to Redirect mapping.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use DateTimeImmutable;
use InvalidArgumentException;
use WP_Post;

/**
 * Maps vip-legacy-redirect posts to Redirect entities.
 *
 * Shared by the command and query repositories so both read a stored row the
 * same way, including the handling of corrupt rows.
 */
trait RedirectPostMapper {

	/**
	 * Map a WP_Post to a Redirect entity.
	 *
	 * A row whose stored source or destination no longer validates - a
	 * hand-edited post, a partial 1.x import, or a post created under this
	 * post type by something other than this plugin - is mapped to a corrupt
	 * Redirect (see Redirect::is_corrupt()) carrying placeholder values, so
	 * one bad row cannot take down the front-end 404 lookup or a listing,
	 * while management surfaces can still see, report, and delete it.
	 *
	 * @param WP_Post $post The post to map.
	 * @return Redirect The redirect entity, flagged as corrupt if the row is unreadable.
	 */
	private function map_post_to_redirect( WP_Post $post ): Redirect {
		$corruption = null;

		try {
			$source = SourceUrl::from_string( $post->post_title );
		} catch ( InvalidArgumentException $e ) {
			$source     = SourceUrl::from_string( '/__corrupt__/' . $post->ID );
			$corruption = 'Invalid source: ' . $e->getMessage();
		}

		try {
			$destination = $this->extract_destination_from_post( $post );
		} catch ( InvalidArgumentException $e ) {
			$destination = Destination::from_url( DestinationUrl::home() );
			$corruption  = trim( ( $corruption ?? '' ) . ' Invalid destination: ' . $e->getMessage() );
		}

		return Redirect::reconstitute(
			$post->ID,
			$source,
			$destination,
			$post->post_status,
			$this->parse_date( $post->post_date_gmt ),
			$corruption
		);
	}

	/**
	 * Extract the destination from a post.
	 *
	 * @param WP_Post $post The redirect post.
	 * @return Destination The destination.
	 *
	 * @throws InvalidArgumentException If the stored destination is invalid.
	 */
	private function extract_destination_from_post( WP_Post $post ): Destination {
		// Check for internal redirect (post_parent).
		if ( $post->post_parent > 0 ) {
			return Destination::from_post_id(
				DestinationPostId::from_int( $post->post_parent )
			);
		}

		// External or relative URL (post_excerpt).
		$excerpt = trim( $post->post_excerpt );
		if ( ! empty( $excerpt ) ) {
			return Destination::from_url(
				DestinationUrl::from_string( $excerpt )
			);
		}

		// Fallback to home if no destination found.
		return Destination::from_url( DestinationUrl::home() );
	}

	/**
	 * Parse a date string to DateTimeImmutable.
	 *
	 * @param string $date_string The date string (MySQL format).
	 * @return DateTimeImmutable|null The parsed date, or null if invalid.
	 */
	private function parse_date( string $date_string ): ?DateTimeImmutable {
		if ( empty( $date_string ) || '0000-00-00 00:00:00' === $date_string ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date_string );

		return $date instanceof DateTimeImmutable ? $date : null;
	}
}
