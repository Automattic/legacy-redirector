<?php
/**
 * SetRedirectStatusAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * SetRedirectStatusAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\BatchFailures
 */
final class SetRedirectStatusAbilityTest extends MonkeyStubs {

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
	 * @var SetRedirectStatusAbility
	 */
	private SetRedirectStatusAbility $ability;

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
		$this->ability    = new SetRedirectStatusAbility(
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
	 * Test disabling maps to the draft post status.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility::execute
	 */
	public function test_execute_disables_a_redirect(): void {
		$this->given_redirect( 3 );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 3, 'draft' )->andReturn( RedirectCreationResult::success( 1 ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 3 ),
				'status'    => 'disabled',
			)
		);

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( array(), $result['failed'] );
	}

	/**
	 * Test enabling maps to the publish post status, for every redirect given.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility::execute
	 */
	public function test_execute_enables_several_redirects(): void {
		$this->given_redirect( 3 );
		$this->given_redirect( 4 );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 3, 'publish' )->andReturn( RedirectCreationResult::success( 1 ) );
		$this->manager->shouldReceive( 'change_status' )->once()->with( 4, 'publish' )->andReturn( RedirectCreationResult::success( 1 ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 3, 4 ),
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 2, $result['updated'] );
	}

	/**
	 * Test the destination is never touched when only the status changes.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility::execute
	 */
	public function test_execute_leaves_the_destination_alone(): void {
		$this->given_redirect( 3 );

		$this->manager->shouldReceive( 'change_status' )->andReturn( RedirectCreationResult::success( 1 ) );
		$this->manager->shouldNotReceive( 'update_destination' );

		$this->ability->execute(
			array(
				'redirects' => array( 3 ),
				'status'    => 'disabled',
			)
		);
	}

	/**
	 * Test a redirect that cannot be changed is reported, not counted.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility::execute
	 */
	public function test_execute_reports_redirects_it_could_not_change(): void {
		$this->given_redirect( 3 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 9 )->andReturn( null );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 3, 'publish' )->andReturn( RedirectCreationResult::error( 'save-failed', 'Could not save.' ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 3, 9 ),
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 0, $result['updated'] );
		$this->assertCount( 2, $result['failed'] );
	}
}
