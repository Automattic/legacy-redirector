<?php
/**
 * RedirectHttpStatus enum unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\RedirectHttpStatus;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * RedirectHttpStatusTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 */
final class RedirectHttpStatusTest extends MonkeyStubs {

	/**
	 * Test get_default returns 301.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectHttpStatus::get_default
	 */
	public function test_get_default_returns_301(): void {
		$status = RedirectHttpStatus::get_default();

		$this->assertSame( 301, $status->value );
		$this->assertSame( RedirectHttpStatus::MOVED_PERMANENTLY, $status );
	}

	/**
	 * Test is_permanent for permanent redirects.
	 *
	 * @dataProvider permanent_status_provider
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectHttpStatus::is_permanent
	 *
	 * @param RedirectHttpStatus $status The status to test.
	 */
	public function test_is_permanent_true( RedirectHttpStatus $status ): void {
		$this->assertTrue( $status->is_permanent() );
	}

	/**
	 * Data provider for permanent status codes.
	 *
	 * @return array<string, array{RedirectHttpStatus}>
	 */
	public function permanent_status_provider(): array {
		return array(
			'301' => array( RedirectHttpStatus::MOVED_PERMANENTLY ),
			'308' => array( RedirectHttpStatus::PERMANENT_REDIRECT ),
		);
	}

	/**
	 * Test is_permanent for temporary redirects.
	 *
	 * @dataProvider temporary_status_provider
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectHttpStatus::is_permanent
	 *
	 * @param RedirectHttpStatus $status The status to test.
	 */
	public function test_is_permanent_false( RedirectHttpStatus $status ): void {
		$this->assertFalse( $status->is_permanent() );
	}

	/**
	 * Data provider for temporary status codes.
	 *
	 * @return array<string, array{RedirectHttpStatus}>
	 */
	public function temporary_status_provider(): array {
		return array(
			'302' => array( RedirectHttpStatus::FOUND ),
			'303' => array( RedirectHttpStatus::SEE_OTHER ),
			'307' => array( RedirectHttpStatus::TEMPORARY_REDIRECT ),
		);
	}
}
