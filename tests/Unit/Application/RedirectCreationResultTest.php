<?php
/**
 * RedirectCreationResult value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * RedirectCreationResultTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class RedirectCreationResultTest extends MonkeyStubs {

	/**
	 * Test error result behavior.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::is_error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error_code
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::error_message
	 */
	public function test_error_result_behavior(): void {
		$result = RedirectCreationResult::error( 'test-code', 'Test error message' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'test-code', $result->error_code() );
		$this->assertSame( 'Test error message', $result->error_message() );
	}

	/**
	 * Test success result behavior.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::success
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::is_error
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::redirect_id
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::redirect
	 */
	public function test_success_result_behavior(): void {
		$redirect = Redirect::reconstitute( 123, SourceUrl::from_string( '/old' ), Destination::from_mixed( '/new' ), 'publish' );
		$result   = RedirectCreationResult::success( $redirect );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
		$this->assertSame( $redirect, $result->redirect() );
	}
}
