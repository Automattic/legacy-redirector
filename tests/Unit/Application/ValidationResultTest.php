<?php
/**
 * ValidationResult value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\ValidationResult;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * ValidationResultTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\ValidationResult
 */
final class ValidationResultTest extends MonkeyStubs {

	/**
	 * Test valid result behavior.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::valid
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::is_valid
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::is_invalid
	 */
	public function test_valid_result_behavior(): void {
		$result = ValidationResult::valid();

		$this->assertTrue( $result->is_valid() );
		$this->assertFalse( $result->is_invalid() );
		$this->assertNull( $result->error_code() );
		$this->assertNull( $result->error_message() );
	}

	/**
	 * Test invalid result behavior.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::invalid
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::is_valid
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::is_invalid
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::error_code
	 * @covers \Automattic\LegacyRedirector\Application\ValidationResult::error_message
	 */
	public function test_invalid_result_behavior(): void {
		$result = ValidationResult::invalid( 'duplicate-uri', 'A redirect for this URI already exists' );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'duplicate-uri', $result->error_code() );
		$this->assertSame( 'A redirect for this URI already exists', $result->error_message() );
	}
}
