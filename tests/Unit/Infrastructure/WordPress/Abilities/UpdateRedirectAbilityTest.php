<?php
/**
 * UpdateRedirectAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\AuditDetail;
use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
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
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\BatchFailures
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::finding_schema
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::finding_to_array
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\AuditDetail
 * @uses \Automattic\LegacyRedirector\Application\FindingText
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
	 * The mock auditor.
	 *
	 * @var RedirectAuditor&Mockery\MockInterface
	 */
	private $auditor;

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
		Functions\when( '_x' )->returnArg( 1 );

		$this->manager    = Mockery::mock( RedirectManager::class );
		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->auditor    = Mockery::mock( RedirectAuditor::class );
		$this->auditor->shouldReceive( 'warnings' )->andReturn( array() )->byDefault();
		$this->ability = new UpdateRedirectAbility(
			$this->manager,
			new RedirectBatch( new RedirectFetcher( $this->repository ) ),
			$this->auditor
		);
	}

	/**
	 * Stub a redirect lookup by ID.
	 *
	 * @param int $redirect_id The redirect ID to resolve.
	 * @return Redirect The stubbed redirect.
	 */
	private function given_redirect( int $redirect_id ): Redirect {
		$redirect = Redirect::reconstitute(
			$redirect_id,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( '/new' ),
			'publish'
		);

		$this->repository->shouldReceive( 'find_by_id' )
			->with( $redirect_id )
			->andReturn( $redirect );

		return $redirect;
	}

	/**
	 * Test asking for no change is rejected rather than silently doing nothing.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_requires_a_destination_or_a_status(): void {
		$result = $this->ability->execute( array( 'redirects' => array( 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'legacy_redirector_nothing_to_update', $result->get_error_code() );
	}

	/**
	 * Test a status-only update goes through the status change path.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_changes_status_without_a_destination(): void {
		$redirect = $this->given_redirect( 4 );

		$this->manager->shouldReceive( 'change_status' )->once()->with( 4, 'draft' )->andReturn( RedirectCreationResult::success( $redirect ) );
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
		$redirect = $this->given_redirect( 4 );

		$this->manager->shouldReceive( 'update_destination' )
			->once()
			->with( 4, Mockery::type( Destination::class ), 'publish' )
			->andReturn( RedirectCreationResult::success( $redirect ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4 ),
				'to'        => '/newer',
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Test warnings about each saved redirect are returned, and none for one that failed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility::execute
	 */
	public function test_execute_returns_warnings_about_the_saved_redirects(): void {
		$saved = $this->given_redirect( 4 );
		$this->given_redirect( 5 );

		$this->manager->shouldReceive( 'update_destination' )->with( 4, Mockery::type( Destination::class ), null )->andReturn( RedirectCreationResult::success( $saved ) );
		$this->manager->shouldReceive( 'update_destination' )->with( 5, Mockery::type( Destination::class ), null )->andReturn( RedirectCreationResult::error( 'save-failed', 'Could not save.' ) );
		$this->auditor->shouldReceive( 'warnings' )
			->once()
			->with( $saved )
			->andReturn( array( new AuditFinding( $saved, AuditFindingType::POSSIBLE_LOOP, AuditDetail::loop( array( '/new', '/old' ) ) ) ) );

		$result = $this->ability->execute(
			array(
				'redirects' => array( 4, 5 ),
				'to'        => '/newer',
			)
		);

		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 4, $result['warnings'][0]['id'] );
		$this->assertSame( 'Possible redirect loop', $result['warnings'][0]['issue'] );
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
		$this->assertSame( 'legacy_redirector_invalid_destination', $result->get_error_code() );
	}
}
