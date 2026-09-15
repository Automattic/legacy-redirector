<?php
/**
 * Abilities API integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Abilities;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;
use WP_Ability;

/**
 * Integration tests for the abilities this plugin registers.
 *
 * These run the abilities the way a client does: through the registry, so
 * that input and output are validated against the registered schemas and the
 * permission callbacks are enforced.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\CreateRedirectAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\DeleteRedirectAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\FindRedirectDomainsAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\GetRedirectAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ListRedirectsAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectBatch
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\SetRedirectStatusAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\UpdateRedirectAbility
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\ValidateRedirectsAbility
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssue
 * @uses \Automattic\LegacyRedirector\Domain\ValidationIssueType
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class AbilitiesTest extends TestCase {

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is only available in WordPress 6.9 and later.' );
		}

		( new Capability() )->register();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = wp_set_current_user( $user_id );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Get a registered ability.
	 *
	 * @param string $name The ability name, without the plugin namespace.
	 * @return WP_Ability The ability.
	 */
	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( 'wpcom-legacy-redirector/' . $name );

		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' should be registered.' );

		return $ability;
	}

	/**
	 * Create a published post and return the path that resolves to it.
	 *
	 * Creation validates the destination, so tests need somewhere real to
	 * point at.
	 *
	 * @param string $slug The post slug.
	 * @return string The post's path, relative to the site root.
	 */
	private function path_to_new_post( string $slug ): string {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => $slug,
			)
		);

		return '/' . $slug;
	}

	/**
	 * Test the plugin registers its abilities and their category.
	 *
	 * @return void
	 */
	public function test_abilities_are_registered(): void {
		$this->assertTrue( wp_has_ability_category( AbilitiesRegistrar::CATEGORY ) );

		$expected = array(
			'create-redirect',
			'get-redirect',
			'list-redirects',
			'update-redirect',
			'set-redirect-status',
			'delete-redirect',
			'validate-redirects',
			'find-redirect-domains',
		);

		foreach ( $expected as $name ) {
			$this->assertSame(
				AbilitiesRegistrar::CATEGORY,
				$this->ability( $name )->get_category(),
				$name . ' should be in the plugin category.'
			);
		}
	}

	/**
	 * Test a redirect can be created, read, updated and deleted through the abilities.
	 *
	 * @return void
	 */
	public function test_redirect_round_trip(): void {
		$first  = $this->path_to_new_post( 'first-target' );
		$second = $this->path_to_new_post( 'second-target' );

		$created = $this->ability( 'create-redirect' )->execute(
			array(
				'from' => '/old-page',
				'to'   => $first,
			)
		);

		$this->assertIsArray( $created, 'Creation should succeed.' );
		$this->assertSame( '/old-page', $created['from'] );
		$this->assertSame( 'enabled', $created['status'] );

		$fetched = $this->ability( 'get-redirect' )->execute( array( 'redirect' => '/old-page' ) );

		$this->assertSame( $created['id'], $fetched['id'] );
		$this->assertSame( $first, $fetched['to'] );

		$updated = $this->ability( 'update-redirect' )->execute(
			array(
				'redirects' => array( '/old-page' ),
				'to'        => $second,
				'status'    => 'disabled',
			)
		);

		$this->assertSame( 1, $updated['updated'] );
		$this->assertSame( array(), $updated['failed'] );

		$fetched = $this->ability( 'get-redirect' )->execute( array( 'redirect' => $created['id'] ) );

		$this->assertSame( $second, $fetched['to'] );
		$this->assertSame( 'disabled', $fetched['status'] );

		$deleted = $this->ability( 'delete-redirect' )->execute(
			array( 'redirects' => array( $created['id'] ) )
		);

		$this->assertSame( 1, $deleted['deleted'] );
		$this->assertInstanceOf(
			'WP_Error',
			$this->ability( 'get-redirect' )->execute( array( 'redirect' => '/old-page' ) ),
			'The redirect should be gone.'
		);
	}

	/**
	 * Test a redirect created through an ability is visible to the admin list.
	 *
	 * @return void
	 */
	public function test_created_redirect_is_listed(): void {
		$this->ability( 'create-redirect' )->execute(
			array(
				'from'   => '/listed',
				'to'     => $this->path_to_new_post( 'listed-target' ),
				'status' => 'disabled',
			)
		);

		$listed = $this->ability( 'list-redirects' )->execute( array( 'status' => 'disabled' ) );

		$this->assertSame( 1, $listed['total'] );
		$this->assertSame( '/listed', $listed['redirects'][0]['from'] );

		$enabled = $this->ability( 'list-redirects' )->execute( array( 'status' => 'enabled' ) );

		$this->assertSame( 0, $enabled['total'] );
	}

	/**
	 * Test a redirect can be turned off and back on without changing where it points.
	 *
	 * @return void
	 */
	public function test_set_redirect_status_toggles_a_redirect(): void {
		$destination = $this->path_to_new_post( 'status-target' );

		$created = $this->ability( 'create-redirect' )->execute(
			array(
				'from' => '/toggled',
				'to'   => $destination,
			)
		);

		$disabled = $this->ability( 'set-redirect-status' )->execute(
			array(
				'redirects' => array( '/toggled' ),
				'status'    => 'disabled',
			)
		);

		$this->assertSame( 1, $disabled['updated'] );
		$this->assertSame( array(), $disabled['failed'] );

		$fetched = $this->ability( 'get-redirect' )->execute( array( 'redirect' => $created['id'] ) );

		$this->assertSame( 'disabled', $fetched['status'] );
		$this->assertSame( $destination, $fetched['to'], 'The destination should be untouched.' );

		$enabled = $this->ability( 'set-redirect-status' )->execute(
			array(
				'redirects' => array( $created['id'] ),
				'status'    => 'enabled',
			)
		);

		$this->assertSame( 1, $enabled['updated'] );

		$fetched = $this->ability( 'get-redirect' )->execute( array( 'redirect' => $created['id'] ) );

		$this->assertSame( 'enabled', $fetched['status'] );
	}

	/**
	 * Test a redirect pointing at a deleted post is reported as broken.
	 *
	 * @return void
	 */
	public function test_validate_reports_a_broken_redirect(): void {
		$post_id = self::factory()->post->create();

		$this->ability( 'create-redirect' )->execute(
			array(
				'from' => '/points-at-a-post',
				'to'   => $post_id,
			)
		);

		wp_delete_post( $post_id, true );

		$result = $this->ability( 'validate-redirects' )->execute( array() );

		$this->assertSame( 1, $result['checked'] );
		$this->assertCount( 1, $result['issues'] );
		$this->assertSame( '/points-at-a-post', $result['issues'][0]['from'] );
		$this->assertNotEmpty( $result['issues'][0]['description'] );
	}

	/**
	 * Test the outbound domains of external redirects are reported.
	 *
	 * @return void
	 */
	public function test_find_redirect_domains_reports_external_hosts(): void {
		$this->create_redirect( '/one', 'https://example.org/a' );
		$this->create_redirect( '/two', 'https://example.org/b' );
		$this->create_redirect( '/three', 'https://example.net/c' );

		$result = $this->ability( 'find-redirect-domains' )->execute();

		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( 'example.net', 'example.org' ), $result['domains'] );
	}

	/**
	 * Test a redirect cannot be updated to point back at its own source.
	 *
	 * @return void
	 */
	public function test_update_to_own_source_is_rejected(): void {
		$this->create_redirect( '/loop-me', 'https://example.org/somewhere' );

		$result = $this->ability( 'update-redirect' )->execute(
			array(
				'redirects' => array( '/loop-me' ),
				'to'        => '/loop-me',
			)
		);

		$this->assertSame( 0, $result['updated'] );
		$this->assertCount( 1, $result['failed'] );

		$fetched = $this->ability( 'get-redirect' )->execute( array( 'redirect' => '/loop-me' ) );

		$this->assertSame( 'https://example.org/somewhere', $fetched['to'], 'The destination should be untouched.' );
	}

	/**
	 * Test input that does not match the schema is rejected before execution.
	 *
	 * @return void
	 */
	public function test_invalid_input_is_rejected_by_the_schema(): void {
		$result = $this->ability( 'create-redirect' )->execute( array( 'from' => '/only-a-source' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Test a user without the capability cannot read or change redirects.
	 *
	 * @return void
	 */
	public function test_abilities_require_the_manage_redirects_capability(): void {
		$this->create_redirect( '/private', '/destination' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$read = $this->ability( 'list-redirects' )->execute( array() );

		$this->assertInstanceOf( 'WP_Error', $read );
		$this->assertSame( 'ability_invalid_permissions', $read->get_error_code() );

		$write = $this->ability( 'delete-redirect' )->execute( array( 'redirects' => array( '/private' ) ) );

		$this->assertInstanceOf( 'WP_Error', $write );
		$this->assertSame( 'ability_invalid_permissions', $write->get_error_code() );
	}
}
