<?php
/**
 * RedirectCreationResult value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * RedirectCreationResultTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult
 */
final class RedirectCreationResultTest extends MonkeyStubs {

	/**
	 * Test error result behaviour.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::is_error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error_code
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error_message
	 */
	public function test_error_result_behaviour(): void {
		$result = RedirectCreationResult::error( 'test-code', 'Test error message' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'test-code', $result->error_code() );
		$this->assertSame( 'Test error message', $result->error_message() );
	}

	/**
	 * Test success result behaviour.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::success
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::is_error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::redirect_id
	 */
	public function test_success_result_behaviour(): void {
		$result = RedirectCreationResult::success( 123 );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
	}
}
