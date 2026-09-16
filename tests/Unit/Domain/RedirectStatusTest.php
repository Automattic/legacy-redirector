<?php
/**
 * RedirectStatus enum unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\RedirectStatus;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * RedirectStatusTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\RedirectStatus
 */
final class RedirectStatusTest extends MonkeyStubs {

	/**
	 * Test get_default returns 301.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectStatus::get_default
	 */
	public function test_get_default_returns_301(): void {
		$status = RedirectStatus::get_default();

		$this->assertSame( 301, $status->value );
		$this->assertSame( RedirectStatus::MOVED_PERMANENTLY, $status );
	}

	/**
	 * Test is_permanent for permanent redirects.
	 *
	 * @dataProvider permanent_status_provider
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectStatus::is_permanent
	 *
	 * @param RedirectStatus $status The status to test.
	 */
	public function test_is_permanent_true( RedirectStatus $status ): void {
		$this->assertTrue( $status->is_permanent() );
	}

	/**
	 * Data provider for permanent status codes.
	 *
	 * @return array<string, array{RedirectStatus}>
	 */
	public function permanent_status_provider(): array {
		return array(
			'301' => array( RedirectStatus::MOVED_PERMANENTLY ),
			'308' => array( RedirectStatus::PERMANENT_REDIRECT ),
		);
	}

	/**
	 * Test is_permanent for temporary redirects.
	 *
	 * @dataProvider temporary_status_provider
	 * @covers \Automattic\LegacyRedirector\Domain\RedirectStatus::is_permanent
	 *
	 * @param RedirectStatus $status The status to test.
	 */
	public function test_is_permanent_false( RedirectStatus $status ): void {
		$this->assertFalse( $status->is_permanent() );
	}

	/**
	 * Data provider for temporary status codes.
	 *
	 * @return array<string, array{RedirectStatus}>
	 */
	public function temporary_status_provider(): array {
		return array(
			'302' => array( RedirectStatus::FOUND ),
			'303' => array( RedirectStatus::SEE_OTHER ),
			'307' => array( RedirectStatus::TEMPORARY_REDIRECT ),
		);
	}
}
