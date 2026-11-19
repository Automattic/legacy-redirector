<?php
/**
 * RedirectCriteria unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Tests the bounds RedirectCriteria puts on paging and sorting.
 *
 * The CLI and the list ability pass user input straight in, so a zero or
 * negative limit, a negative offset or a lower-case order must still make
 * a query that works.
 *
 * @covers \Automattic\LegacyRedirector\Domain\RedirectCriteria
 */
final class RedirectCriteriaTest extends TestCase {

	/**
	 * Test the limit is at least one and the offset at least zero.
	 *
	 * @dataProvider data_paging
	 *
	 * @param int $limit           The limit given.
	 * @param int $offset          The offset given.
	 * @param int $expected_limit  The limit kept.
	 * @param int $expected_offset The offset kept.
	 */
	public function test_paging_is_bounded( int $limit, int $offset, int $expected_limit, int $expected_offset ): void {
		$criteria = new RedirectCriteria( null, null, null, 'date', 'DESC', $limit, $offset );

		$this->assertSame( $expected_limit, $criteria->limit() );
		$this->assertSame( $expected_offset, $criteria->offset() );
	}

	/**
	 * Data provider for paging bounds.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
	 */
	public static function data_paging(): array {
		return array(
			'in range'        => array( 25, 50, 25, 50 ),
			'zero limit'      => array( 0, 0, 1, 0 ),
			'negative limit'  => array( -5, 0, 1, 0 ),
			'negative offset' => array( 10, -3, 10, 0 ),
		);
	}

	/**
	 * Test the order is upper-cased, as the query expects.
	 */
	public function test_order_is_upper_cased(): void {
		$this->assertSame( 'ASC', ( new RedirectCriteria( null, null, null, 'date', 'asc' ) )->order() );
	}

	/**
	 * Test an empty search is no search.
	 *
	 * @dataProvider data_searches
	 *
	 * @param string|null $search   The search given.
	 * @param bool        $expected Whether it counts as a search.
	 */
	public function test_an_empty_search_is_no_search( ?string $search, bool $expected ): void {
		$this->assertSame( $expected, ( new RedirectCriteria( null, null, $search ) )->has_search() );
	}

	/**
	 * Data provider for searches.
	 *
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public static function data_searches(): array {
		return array(
			'none'  => array( null, false ),
			'empty' => array( '', false ),
			'term'  => array( 'old', true ),
			'zero'  => array( '0', true ),
		);
	}
}
