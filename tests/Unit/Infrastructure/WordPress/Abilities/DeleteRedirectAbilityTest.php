<?php
/**
 * DeleteRedirectAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * DeleteRedirectAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility
 */
final class DeleteRedirectAbilityTest extends MonkeyStubs {

	/**
	 * The mock manager.
	 *
	 * @var RedirectManager&Mockery\MockInterface
	 */
	private $manager;

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The ability under test.
	 *
	 * @var DeleteRedirectAbility
	 */
	private DeleteRedirectAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->manager    = Mockery::mock( RedirectManager::class );
		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->ability    = new DeleteRedirectAbility(
			$this->manager,
			new RedirectBatch( new RedirectFetcher( $this->repository ) )
		);
	}

	/**
	 * Stub a redirect lookup by ID.
	 *
	 * @param int $redirect_id The redirect ID to resolve.
	 * @return void
	 */
	private function given_redirect( int $redirect_id ): void {
		$this->repository->shouldReceive( 'find_by_id' )
			->with( $redirect_id )
			->andReturn(
				Redirect::reconstitute(
					$redirect_id,
					SourceUrl::from_string( '/old' ),
					Destination::from_mixed( '/new' ),
					'publish'
				)
			);
	}

	/**
	 * Test each resolved redirect is deleted through the manager.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility::execute
	 */
	public function test_execute_deletes_each_redirect(): void {
		$this->given_redirect( 1 );
		$this->given_redirect( 2 );

		$this->manager->shouldReceive( 'delete_by_id' )->once()->with( 1 )->andReturn( true );
		$this->manager->shouldReceive( 'delete_by_id' )->once()->with( 2 )->andReturn( true );

		$result = $this->ability->execute( array( 'redirects' => array( 1, 2 ) ) );

		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( array(), $result['failed'] );
	}

	/**
	 * Test a deletion that does not happen is reported, not counted.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility::execute
	 */
	public function test_execute_reports_redirects_it_could_not_delete(): void {
		$this->given_redirect( 1 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 2 )->andReturn( null );

		$this->manager->shouldReceive( 'delete_by_id' )->once()->with( 1 )->andReturn( false );

		$result = $this->ability->execute( array( 'redirects' => array( 1, 2 ) ) );

		$this->assertSame( 0, $result['deleted'] );
		$this->assertCount( 2, $result['failed'] );
		$this->assertSame( '2', $result['failed'][0]['redirect'] );
	}
}
