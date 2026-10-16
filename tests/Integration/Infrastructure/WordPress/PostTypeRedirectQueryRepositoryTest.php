<?php
/**
 * PostTypeRedirectQueryRepository integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for PostTypeRedirectQueryRepository.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 */
final class PostTypeRedirectQueryRepositoryTest extends TestCase {

	/**
	 * Paging by date visits every row exactly once, newest ID first, even
	 * when an import gave them all the same timestamp.
	 */
	public function test_paging_by_date_covers_every_row_despite_identical_dates(): void {
		$ids = $this->create_redirects_with_one_date( 5 );

		$seen = array();
		for ( $offset = 0; $offset < 5; $offset++ ) {
			$page = $this->query_repository()->find_matching( new RedirectCriteria( null, null, null, 'date', 'DESC', 1, $offset ) );
			$seen = array_merge( $seen, array_map( static fn ( Redirect $redirect ): ?int => $redirect->id(), $page ) );
		}

		rsort( $ids );
		$this->assertSame( $ids, $seen );
	}

	/**
	 * Ordering by ID needs no tiebreaker and is passed through as asked.
	 */
	public function test_ordering_by_id_is_passed_through(): void {
		$ids = $this->create_redirects_with_one_date( 3 );

		$found = $this->query_repository()->find_matching( new RedirectCriteria( null, null, null, 'ID', 'ASC' ) );

		$this->assertSame( $ids, array_map( static fn ( Redirect $redirect ): ?int => $redirect->id(), $found ) );
	}

	/**
	 * Create redirects that all share one post_date, as a bulk import does.
	 *
	 * @param int $count How many to create.
	 * @return int[] Their IDs, in creation order.
	 */
	private function create_redirects_with_one_date( int $count ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$redirect_id = $this->create_redirect( "/same-second-$i", '/target' );
			wp_update_post(
				array(
					'ID'            => $redirect_id,
					'post_date'     => '2026-01-01 00:00:00',
					'post_date_gmt' => '2026-01-01 00:00:00',
					'edit_date'     => true,
				)
			);
			$ids[] = $redirect_id;
		}

		return $ids;
	}
}
