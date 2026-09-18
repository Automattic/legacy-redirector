<?php
/**
 * Capability unit tests
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Brain\Monkey\Functions;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * Unit tests for the Infrastructure Capability class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 */
final class CapabilityTest extends TestCase {

	/**
	 * Test register returns false when capabilities are already current version.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::register
	 */
	public function test_register_returns_false_when_already_registered(): void {
		$capability = new Capability();

		Functions\expect( 'get_option' )
			->once()
			->with( 'manage_redirects_capability_version', 0 )
			->andReturn( 1 );

		$result = $capability->register();

		$this->assertFalse( $result );
	}

	/**
	 * Test register uses VIP functions when available.
	 *
	 * Brain\Monkey automatically makes function_exists() return true for stubbed functions.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::register
	 */
	public function test_register_uses_vip_functions_when_available(): void {
		$capability = new Capability();

		Functions\expect( 'get_option' )
			->once()
			->with( 'manage_redirects_capability_version', 0 )
			->andReturn( 0 );

		// Stub the VIP function - this makes function_exists return true for it.
		Functions\expect( 'wpcom_vip_add_role_caps' )
			->once()
			->with( 'administrator', 'manage_redirects' );

		Functions\expect( 'wpcom_vip_add_role_caps' )
			->once()
			->with( 'editor', 'manage_redirects' );

		Functions\expect( 'update_option' )
			->once()
			->with( 'manage_redirects_capability_version', 1 )
			->andReturn( true );

		$result = $capability->register();

		$this->assertTrue( $result );
	}

	/**
	 * Test register handles null role gracefully when VIP functions are available.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::register
	 */
	public function test_register_handles_nonexistent_role_with_vip(): void {
		$capability = new Capability( array( 'nonexistent_role' ) );

		Functions\expect( 'get_option' )
			->once()
			->with( 'manage_redirects_capability_version', 0 )
			->andReturn( 0 );

		// VIP function handles non-existent roles gracefully.
		Functions\expect( 'wpcom_vip_add_role_caps' )
			->once()
			->with( 'nonexistent_role', 'manage_redirects' );

		Functions\expect( 'update_option' )
			->once()
			->with( 'manage_redirects_capability_version', 1 )
			->andReturn( true );

		$result = $capability->register();

		$this->assertTrue( $result );
	}

	/**
	 * Test custom roles can be configured via constructor with VIP functions.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::__construct
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability::register
	 */
	public function test_custom_roles_can_be_configured(): void {
		$capability = new Capability( array( 'subscriber', 'custom_role' ) );

		Functions\expect( 'get_option' )
			->once()
			->with( 'manage_redirects_capability_version', 0 )
			->andReturn( 0 );

		Functions\expect( 'wpcom_vip_add_role_caps' )
			->once()
			->with( 'subscriber', 'manage_redirects' );

		Functions\expect( 'wpcom_vip_add_role_caps' )
			->once()
			->with( 'custom_role', 'manage_redirects' );

		Functions\expect( 'update_option' )
			->once()
			->with( 'manage_redirects_capability_version', 1 )
			->andReturn( true );

		$result = $capability->register();

		$this->assertTrue( $result );
	}

	/**
	 * Test constant is accessible.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
	 */
	public function test_constant_is_accessible(): void {
		$this->assertSame( 'manage_redirects', Capability::MANAGE_REDIRECTS_CAPABILITY );
	}
}
