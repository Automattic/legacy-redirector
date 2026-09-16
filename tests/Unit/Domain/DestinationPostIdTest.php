<?php
/**
 * DestinationPostId value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use InvalidArgumentException;

/**
 * DestinationPostIdTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId
 */
final class DestinationPostIdTest extends MonkeyStubs {

	/**
	 * Test from_int creates valid DestinationPostId.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_int
	 */
	public function test_from_int_with_valid_id(): void {
		$destination = DestinationPostId::from_int( 123 );

		$this->assertSame( 123, $destination->value() );
	}

	/**
	 * Test from_int throws for zero.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_int
	 */
	public function test_from_int_throws_for_zero(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Post ID must be a positive integer.' );

		DestinationPostId::from_int( 0 );
	}

	/**
	 * Test from_int throws for negative.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_int
	 */
	public function test_from_int_throws_for_negative(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Post ID must be a positive integer.' );

		DestinationPostId::from_int( -1 );
	}

	/**
	 * Test from_mixed with integer.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_mixed
	 */
	public function test_from_mixed_with_integer(): void {
		$destination = DestinationPostId::from_mixed( 456 );

		$this->assertSame( 456, $destination->value() );
	}

	/**
	 * Test from_mixed with numeric string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_mixed
	 */
	public function test_from_mixed_with_numeric_string(): void {
		$destination = DestinationPostId::from_mixed( '789' );

		$this->assertSame( 789, $destination->value() );
	}

	/**
	 * Test from_mixed throws for non-numeric string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_mixed
	 */
	public function test_from_mixed_throws_for_non_numeric(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Post ID must be numeric.' );

		DestinationPostId::from_mixed( 'abc' );
	}

	/**
	 * Test from_mixed throws for zero string.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::from_mixed
	 */
	public function test_from_mixed_throws_for_zero_string(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Post ID must be a positive integer.' );

		DestinationPostId::from_mixed( '0' );
	}

	/**
	 * Test value returns post ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::value
	 */
	public function test_value_returns_post_id(): void {
		$destination = DestinationPostId::from_int( 42 );

		$this->assertSame( 42, $destination->value() );
	}

	/**
	 * Test __toString returns string representation.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\DestinationPostId::__toString
	 */
	public function test_to_string(): void {
		$destination = DestinationPostId::from_int( 999 );

		$this->assertSame( '999', (string) $destination );
	}
}
