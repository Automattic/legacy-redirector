<?php
/**
 * ValidateRedirectsAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * ValidateRedirectsAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility
 */
final class ValidateRedirectsAbilityTest extends MonkeyStubs {

	/**
	 * The mock query repository.
	 *
	 * @var RedirectQueryRepositoryInterface&Mockery\MockInterface
	 */
	private $query_repository;

	/**
	 * The mock auditor.
	 *
	 * @var RedirectAuditor&Mockery\MockInterface
	 */
	private $auditor;

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The ability under test.
	 *
	 * @var ValidateRedirectsAbility
	 */
	private ValidateRedirectsAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->query_repository = Mockery::mock( RedirectQueryRepositoryInterface::class );
		$this->auditor          = Mockery::mock( RedirectAuditor::class );
		$this->repository       = Mockery::mock( RedirectRepositoryInterface::class );
		$this->ability          = new ValidateRedirectsAbility(
			$this->query_repository,
			$this->auditor,
			new RedirectBatch( new RedirectFetcher( $this->repository ) )
		);
	}

	/**
	 * Build a redirect for testing.
	 *
	 * @param int $redirect_id The redirect ID.
	 * @return Redirect The redirect.
	 */
	private function redirect( int $redirect_id ): Redirect {
		return Redirect::reconstitute(
			$redirect_id,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( 12 ),
			'publish'
		);
	}

	/**
	 * Test the enabled redirects are checked when no redirects are named.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::execute
	 */
	public function test_execute_checks_a_batch_when_no_redirects_are_given(): void {
		$this->query_repository->shouldReceive( 'find_matching' )
			->once()
			->with(
				Mockery::on(
					static function ( RedirectCriteria $criteria ): bool {
						return 'enabled' === $criteria->status() && 100 === $criteria->limit();
					}
				)
			)
			->andReturn( array( $this->redirect( 1 ), $this->redirect( 2 ) ) );

		$this->auditor->shouldReceive( 'validate_batch' )->once()->andReturn( array() );

		$result = $this->ability->execute( array() );

		$this->assertSame( 2, $result['checked'] );
		$this->assertSame( array(), $result['issues'] );
		$this->assertSame( array(), $result['failed'] );
	}

	/**
	 * Test named redirects are checked instead of a batch.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::execute
	 */
	public function test_execute_checks_the_given_redirects(): void {
		$this->repository->shouldReceive( 'find_by_id' )->with( 1 )->andReturn( $this->redirect( 1 ) );
		$this->repository->shouldReceive( 'find_by_id' )->with( 8 )->andReturn( null );
		$this->query_repository->shouldNotReceive( 'find_matching' );

		$this->auditor->shouldReceive( 'validate_batch' )
			->once()
			->with( Mockery::type( 'array' ), true )
			->andReturn( array() );

		$result = $this->ability->execute(
			array(
				'redirects'  => array( 1, 8 ),
				'check_urls' => true,
			)
		);

		$this->assertSame( 1, $result['checked'] );
		$this->assertCount( 1, $result['failed'] );
	}

	/**
	 * Test an issue is returned with the redirect it belongs to.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::execute
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility::issue_to_array
	 */
	public function test_execute_describes_each_issue(): void {
		$redirect = $this->redirect( 3 );

		$this->query_repository->shouldReceive( 'find_matching' )->andReturn( array( $redirect ) );
		$this->auditor->shouldReceive( 'validate_batch' )->andReturn(
			array( new ValidationIssue( $redirect, ValidationIssueType::POST_DELETED ) )
		);

		$result = $this->ability->execute( array() );

		$this->assertCount( 1, $result['issues'] );
		$this->assertSame( 3, $result['issues'][0]['id'] );
		$this->assertSame( '/old', $result['issues'][0]['from'] );
		$this->assertNotEmpty( $result['issues'][0]['issue'] );
		$this->assertNotEmpty( $result['issues'][0]['description'] );
	}
}
