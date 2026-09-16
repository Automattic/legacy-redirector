<?php
/**
 * GetRedirectAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * GetRedirectAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 */
final class GetRedirectAbilityTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The ability under test.
	 *
	 * @var GetRedirectAbility
	 */
	private GetRedirectAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->ability    = new GetRedirectAbility( new RedirectFetcher( $this->repository ) );
	}

	/**
	 * Test a found redirect is returned in the shape the output schema describes.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility::execute
	 */
	public function test_execute_returns_the_redirect(): void {
		$this->repository->shouldReceive( 'find_by_id' )
			->with( 5 )
			->andReturn(
				Redirect::reconstitute(
					5,
					SourceUrl::from_string( '/old' ),
					Destination::from_mixed( 99 ),
					'draft'
				)
			);

		$this->assertSame(
			array(
				'id'     => 5,
				'from'   => '/old',
				'to'     => 99,
				'type'   => 'post',
				'status' => 'disabled',
			),
			$this->ability->execute( array( 'redirect' => 5 ) )
		);
	}

	/**
	 * Test a missing redirect is an error rather than an empty result.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility::execute
	 */
	public function test_execute_returns_an_error_when_not_found(): void {
		$this->repository->shouldReceive( 'find_by_id' )->with( 5 )->andReturn( null );

		$result = $this->ability->execute( array( 'redirect' => '5' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_not_found', $result->get_error_code() );
	}

	/**
	 * Test an unparseable identifier is an error.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility::execute
	 */
	public function test_execute_returns_an_error_for_an_invalid_identifier(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$result = $this->ability->execute( array( 'redirect' => 'http://example.com' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_invalid_identifier', $result->get_error_code() );
	}
}
