<?php
/**
 * UpdateRedirectAbility unit tests.
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
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * UpdateRedirectAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\BatchFailures
 */
final class UpdateRedirectAbilityTest extends MonkeyStubs {

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
	 * @var UpdateRedirectAbility
	 */
	private UpdateRedirectAbility $ability;

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
		$this->ability    = new UpdateRedirectAbility(
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
	 * Test asking for no change is rejected rather than silently doing nothing.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_requires_a_destination_or_a_status(): void {
		$result = $this->ability->execute( array( 'redirects' => array( 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_nothing_to_update', $result->get_error_code() );
	}

	/**
	 * Test a status-only update goes through the status change path.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_changes_status_without_a_destination(): void {
		$this->given_redirect( 4 );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 4, 'draft' )->andReturn( RedirectCreationResult::success( 1 ) );
		$this->manager->shouldNotReceive( 'update_destination' );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4 ),
				'status'    => 'disabled',
			)
		);

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( array(), $result['failed'] );
	}

	/**
	 * Test a destination update carries any status change with it.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_updates_destination_and_status_together(): void {
		$this->given_redirect( 4 );

		$this->manager->shouldReceive( 'update_destination' )
			->once()
			->with( 4, Mockery::type( Destination::class ), 'publish' )
			->andReturn( RedirectCreationResult::success( 1 ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4 ),
				'to'        => '/newer',
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 1, $result['updated'] );
	}

	/**
	 * Test a failed save is reported per redirect instead of failing the call.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_reports_redirects_it_could_not_save(): void {
		$this->given_redirect( 4 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 9 )->andReturn( null );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 4, 'publish' )->andReturn( RedirectCreationResult::error( 'save-failed', 'Could not save.' ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4, 9 ),
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 0, $result['updated'] );
		$this->assertCount( 2, $result['failed'] );
	}

	/**
	 * Test an unusable destination is rejected before anything is changed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_rejects_an_invalid_destination(): void {
		$this->manager->shouldNotReceive( 'update_destination' );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4 ),
				'to'        => 'nonsense',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_invalid_destination', $result->get_error_code() );
	}
}
