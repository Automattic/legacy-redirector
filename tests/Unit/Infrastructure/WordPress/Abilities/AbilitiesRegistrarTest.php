<?php
/**
 * AbilitiesRegistrar unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;

/**
 * AbilitiesRegistrarTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility
 * @uses \Automattic\LegacyRedirector\Application\BatchOutcome
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\BatchFailures
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility
 */
final class AbilitiesRegistrarTest extends MonkeyStubs {

	/**
	 * The registrar under test.
	 *
	 * @var AbilitiesRegistrar
	 */
	private AbilitiesRegistrar $registrar;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );

		$fetcher = new RedirectFetcher( Mockery::mock( RedirectRepositoryInterface::class ) );

		$this->registrar = new AbilitiesRegistrar(
			Mockery::mock( RedirectManager::class ),
			$fetcher,
			new RedirectBatch( $fetcher ),
			Mockery::mock( RedirectQueryRepositoryInterface::class ),
			Mockery::mock( RedirectAuditor::class )
		);
	}

	/**
	 * Test the registrar hooks the Abilities API init actions.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar::register
	 */
	public function test_register_adds_actions(): void {
		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_abilities_api_categories_init', Mockery::type( 'array' ) );

		Functions\expect( 'add_action' )
			->once()
			->with( 'wp_abilities_api_init', Mockery::type( 'array' ) );

		$this->registrar->register();
	}

	/**
	 * Test every ability is fully described.
	 *
	 * Ability registration fails silently when a required argument is missing,
	 * so this asserts the whole contract rather than trusting each class.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar::abilities
	 */
	public function test_abilities_are_fully_described(): void {
		$names = array();

		foreach ( $this->registrar->abilities() as $ability ) {
			$name = $ability->name();
			$args = $ability->args();

			$this->assertStringStartsWith( 'legacy-redirector/', $name );
			$this->assertMatchesRegularExpression( '#^[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*$#', $name );
			$this->assertNotContains( $name, $names, 'Ability names must be unique.' );
			$names[] = $name;

			$this->assertNotEmpty( $args['label'], $name . ' needs a label.' );
			$this->assertNotEmpty( $args['description'], $name . ' needs a description.' );
			$this->assertSame( AbilitiesRegistrar::CATEGORY, $args['category'], $name . ' needs the plugin category.' );
			$this->assertIsCallable( $args['execute_callback'], $name . ' needs an execute callback.' );
			$this->assertIsCallable( $args['permission_callback'], $name . ' needs a permission callback.' );
			$this->assertArrayHasKey( 'output_schema', $args, $name . ' needs an output schema.' );
			$this->assertArrayHasKey( 'annotations', $args['meta'], $name . ' needs annotations.' );
			$this->assertArrayHasKey( 'readonly', $args['meta']['annotations'], $name . ' needs a readonly annotation.' );
		}

		$this->assertCount( 8, $names );
	}

	/**
	 * Test the abilities that change redirects are not annotated as read-only.
	 *
	 * MCP clients use these annotations to decide what to confirm with a user
	 * before calling, so a wrong annotation means silent data loss.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar::abilities
	 */
	public function test_writing_abilities_are_annotated_as_writes(): void {
		$writes = array(
			'legacy-redirector/create-redirect',
			'legacy-redirector/update-redirect',
			'legacy-redirector/set-redirect-status',
			'legacy-redirector/delete-redirect',
		);

		$annotations = array();
		foreach ( $this->registrar->abilities() as $ability ) {
			$annotations[ $ability->name() ] = $ability->args()['meta']['annotations'];
		}

		foreach ( $writes as $name ) {
			$this->assertFalse( $annotations[ $name ]['readonly'], $name . ' changes redirects.' );
		}

		$this->assertTrue(
			$annotations['legacy-redirector/delete-redirect']['destructive'],
			'Deleting a redirect is destructive.'
		);
		$this->assertFalse(
			$annotations['legacy-redirector/update-redirect']['destructive'],
			'Updating a redirect is not destructive.'
		);
		$this->assertFalse(
			$annotations['legacy-redirector/set-redirect-status']['destructive'],
			'Disabling a redirect keeps it, so it is not destructive.'
		);
		$this->assertTrue(
			$annotations['legacy-redirector/validate-redirects']['readonly'],
			'Validating only reports.'
		);
	}

	/**
	 * Test the category is registered only when it does not already exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar::register_category
	 */
	public function test_register_category_skips_an_existing_category(): void {
		Functions\expect( 'wp_has_ability_category' )
			->once()
			->with( AbilitiesRegistrar::CATEGORY )
			->andReturn( true );

		Functions\expect( 'wp_register_ability_category' )->never();

		$this->registrar->register_category();
	}

	/**
	 * Test the category is registered when it is missing.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar::register_category
	 */
	public function test_register_category_registers_a_missing_category(): void {
		Functions\expect( 'wp_has_ability_category' )->once()->andReturn( false );

		Functions\expect( 'wp_register_ability_category' )
			->once()
			->with( AbilitiesRegistrar::CATEGORY, Mockery::type( 'array' ) );

		$this->registrar->register_category();
	}
}
