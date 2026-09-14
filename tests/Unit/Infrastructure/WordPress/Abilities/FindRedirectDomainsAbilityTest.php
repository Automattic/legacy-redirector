<?php
/**
 * FindRedirectDomainsAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * FindRedirectDomainsAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility
 */
final class FindRedirectDomainsAbilityTest extends MonkeyStubs {

	/**
	 * The mock query repository.
	 *
	 * @var RedirectQueryRepositoryInterface&Mockery\MockInterface
	 */
	private $query_repository;

	/**
	 * The ability under test.
	 *
	 * @var FindRedirectDomainsAbility
	 */
	private FindRedirectDomainsAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->query_repository = Mockery::mock( RedirectQueryRepositoryInterface::class );
		$this->ability          = new FindRedirectDomainsAbility( $this->query_repository );
	}

	/**
	 * Test the domains are deduplicated and sorted.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility::execute
	 */
	public function test_execute_returns_unique_sorted_domains(): void {
		$this->query_repository->shouldReceive( 'get_external_destination_urls' )
			->once()
			->with( 500, 0 )
			->andReturn(
				array(
					'https://example.org/one',
					'https://example.com/two',
					'https://example.org/three',
					'',
				)
			);

		$this->assertSame(
			array(
				'count'   => 2,
				'domains' => array( 'example.com', 'example.org' ),
			),
			$this->ability->execute()
		);
	}

	/**
	 * Test a full page of results is followed by a request for the next page.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility::execute
	 */
	public function test_execute_pages_through_the_destinations(): void {
		$first_page = array_fill( 0, 500, 'https://example.org/page' );

		$this->query_repository->shouldReceive( 'get_external_destination_urls' )
			->once()
			->with( 500, 0 )
			->andReturn( $first_page );

		$this->query_repository->shouldReceive( 'get_external_destination_urls' )
			->once()
			->with( 500, 500 )
			->andReturn( array( 'https://example.net/last' ) );

		$result = $this->ability->execute();

		$this->assertSame( array( 'example.net', 'example.org' ), $result['domains'] );
	}
}
