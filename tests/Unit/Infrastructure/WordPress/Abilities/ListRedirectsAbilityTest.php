<?php
/**
 * ListRedirectsAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * ListRedirectsAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 */
final class ListRedirectsAbilityTest extends MonkeyStubs {

	/**
	 * The mock query repository.
	 *
	 * @var RedirectQueryRepositoryInterface&Mockery\MockInterface
	 */
	private $query_repository;

	/**
	 * The ability under test.
	 *
	 * @var ListRedirectsAbility
	 */
	private ListRedirectsAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->query_repository = Mockery::mock( RedirectQueryRepositoryInterface::class );
		$this->ability          = new ListRedirectsAbility( $this->query_repository );
	}

	/**
	 * Test the redirects and the unpaginated total are both returned.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility::execute
	 */
	public function test_execute_returns_redirects_with_a_total(): void {
		$redirect = Redirect::reconstitute(
			3,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( '/new' ),
			'publish'
		);

		$this->query_repository->shouldReceive( 'find_matching' )->once()->andReturn( array( $redirect ) );
		$this->query_repository->shouldReceive( 'count_matching' )->once()->andReturn( 57 );

		$result = $this->ability->execute( array( 'limit' => 1 ) );

		$this->assertSame( 57, $result['total'] );
		$this->assertCount( 1, $result['redirects'] );
		$this->assertSame( 3, $result['redirects'][0]['id'] );
	}

	/**
	 * Test the 'any' filters are passed to the criteria as no filter at all.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility::execute
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility::filter_value
	 */
	public function test_execute_treats_any_as_no_filter(): void {
		$this->query_repository->shouldReceive( 'find_matching' )
			->once()
			->with(
				Mockery::on(
					static function ( RedirectCriteria $criteria ): bool {
						return null === $criteria->status()
							&& null === $criteria->destination_type()
							&& 'blog' === $criteria->search()
							&& 20 === $criteria->limit();
					}
				)
			)
			->andReturn( array() );

		$this->query_repository->shouldReceive( 'count_matching' )->andReturn( 0 );

		$this->ability->execute(
			array(
				'status'           => 'any',
				'destination_type' => 'any',
				'search'           => 'blog',
			)
		);
	}

	/**
	 * Test the filters reach the criteria when they are set.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility::execute
	 */
	public function test_execute_passes_filters_to_the_criteria(): void {
		$this->query_repository->shouldReceive( 'find_matching' )
			->once()
			->with(
				Mockery::on(
					static function ( RedirectCriteria $criteria ): bool {
						return 'disabled' === $criteria->status()
							&& 'post' === $criteria->destination_type()
							&& 40 === $criteria->offset();
					}
				)
			)
			->andReturn( array() );

		$this->query_repository->shouldReceive( 'count_matching' )->andReturn( 0 );

		$this->ability->execute(
			array(
				'status'           => 'disabled',
				'destination_type' => 'post',
				'offset'           => 40,
			)
		);
	}
}
