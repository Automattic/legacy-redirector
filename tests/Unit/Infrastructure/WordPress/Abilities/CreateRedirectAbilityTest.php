<?php
/**
 * CreateRedirectAbility unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;
use WP_Error;

/**
 * CreateRedirectAbilityTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class CreateRedirectAbilityTest extends MonkeyStubs {

	/**
	 * The mock manager.
	 *
	 * @var RedirectManager&Mockery\MockInterface
	 */
	private $manager;

	/**
	 * The ability under test.
	 *
	 * @var CreateRedirectAbility
	 */
	private CreateRedirectAbility $ability;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$this->manager = Mockery::mock( RedirectManager::class );
		$this->ability = new CreateRedirectAbility( $this->manager );
	}

	/**
	 * Test a created redirect is returned in the shape the output schema describes.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility::execute
	 */
	public function test_execute_returns_the_created_redirect(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->manager->shouldReceive( 'create_redirect' )
			->once()
			->with( Mockery::any(), Mockery::any(), true, 'publish' )
			->andReturn( RedirectCreationResult::success( 42 ) );

		$result = $this->ability->execute(
			array(
				'from' => '/old-page',
				'to'   => '/new-page',
			)
		);

		$this->assertSame(
			array(
				'id'     => 42,
				'from'   => '/old-page',
				'to'     => '/new-page',
				'type'   => 'url',
				'status' => 'enabled',
			),
			$result
		);
	}

	/**
	 * Test a disabled redirect is created as a draft.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility::execute
	 */
	public function test_execute_creates_a_disabled_redirect_as_a_draft(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->manager->shouldReceive( 'create_redirect' )
			->once()
			->with( Mockery::any(), Mockery::any(), true, 'draft' )
			->andReturn( RedirectCreationResult::success( 7 ) );

		$result = $this->ability->execute(
			array(
				'from'   => '/old-page',
				'to'     => 123,
				'status' => 'disabled',
			)
		);

		$this->assertSame( 'disabled', $result['status'] );
		$this->assertSame( 'post', $result['type'] );
		$this->assertSame( 123, $result['to'] );
	}

	/**
	 * Test a creation failure becomes a namespaced error.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility::execute
	 */
	public function test_execute_returns_an_error_when_creation_fails(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->manager->shouldReceive( 'create_redirect' )
			->once()
			->andReturn( RedirectCreationResult::error( 'duplicate-redirect', 'Already exists.' ) );

		$result = $this->ability->execute(
			array(
				'from' => '/old-page',
				'to'   => '/new-page',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_duplicate_redirect', $result->get_error_code() );
	}

	/**
	 * Test an unusable source path is rejected before the manager is called.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility::execute
	 */
	public function test_execute_rejects_an_invalid_source(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->manager->shouldNotReceive( 'create_redirect' );

		$result = $this->ability->execute(
			array(
				'from' => 'http://example.com',
				'to'   => '/new-page',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpcom_legacy_redirector_invalid_redirect', $result->get_error_code() );
	}
}
