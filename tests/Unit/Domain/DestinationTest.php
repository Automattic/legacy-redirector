<?php
/**
 * Destination value object unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use LogicException;

/**
 * DestinationTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class DestinationTest extends MonkeyStubs {

	/**
	 * Test from_url creates URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_url
	 */
	public function test_from_url_creates_url_destination(): void {
		$url         = DestinationUrl::from_string( '/page' );
		$destination = Destination::from_url( $url );

		$this->assertTrue( $destination->is_url() );
		$this->assertFalse( $destination->is_post_id() );
	}

	/**
	 * Test from_post_id creates post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_post_id
	 */
	public function test_from_post_id_creates_post_destination(): void {
		$post_id     = DestinationPostId::from_int( 123 );
		$destination = Destination::from_post_id( $post_id );

		$this->assertTrue( $destination->is_post_id() );
		$this->assertFalse( $destination->is_url() );
	}

	/**
	 * Test from_mixed with numeric value creates post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 */
	public function test_from_mixed_numeric_creates_post_destination(): void {
		$destination = Destination::from_mixed( 456 );

		$this->assertTrue( $destination->is_post_id() );
		$this->assertSame( 456, $destination->as_post_id()->value() );
	}

	/**
	 * Test from_mixed with numeric string creates post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 */
	public function test_from_mixed_numeric_string_creates_post_destination(): void {
		$destination = Destination::from_mixed( '789' );

		$this->assertTrue( $destination->is_post_id() );
		$this->assertSame( 789, $destination->as_post_id()->value() );
	}

	/**
	 * Test from_mixed with URL string creates URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 */
	public function test_from_mixed_url_creates_url_destination(): void {
		$destination = Destination::from_mixed( '/some-page' );

		$this->assertTrue( $destination->is_url() );
		$this->assertSame( '/some-page', $destination->as_url()->value() );
	}

	/**
	 * Test from_mixed with absolute URL creates URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 */
	public function test_from_mixed_absolute_url_creates_url_destination(): void {
		$destination = Destination::from_mixed( 'https://example.com/page' );

		$this->assertTrue( $destination->is_url() );
		$this->assertSame( 'https://example.com/page', $destination->as_url()->value() );
	}

	/**
	 * Test as_url returns URL for URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::as_url
	 */
	public function test_as_url_returns_url(): void {
		$url         = DestinationUrl::from_string( '/page' );
		$destination = Destination::from_url( $url );

		$this->assertSame( $url, $destination->as_url() );
	}

	/**
	 * Test as_url throws for post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::as_url
	 */
	public function test_as_url_throws_for_post_destination(): void {
		$destination = Destination::from_post_id( DestinationPostId::from_int( 123 ) );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Cannot get URL from a post ID destination.' );

		$destination->as_url();
	}

	/**
	 * Test as_post_id returns post ID for post destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::as_post_id
	 */
	public function test_as_post_id_returns_post_id(): void {
		$post_id     = DestinationPostId::from_int( 456 );
		$destination = Destination::from_post_id( $post_id );

		$this->assertSame( $post_id, $destination->as_post_id() );
	}

	/**
	 * Test as_post_id throws for URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::as_post_id
	 */
	public function test_as_post_id_throws_for_url_destination(): void {
		$destination = Destination::from_url( DestinationUrl::from_string( '/page' ) );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Cannot get post ID from a URL destination.' );

		$destination->as_post_id();
	}

	/**
	 * Test raw_value returns URL for URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::raw_value
	 */
	public function test_raw_value_returns_url(): void {
		$destination = Destination::from_url( DestinationUrl::from_string( '/page' ) );

		$this->assertSame( '/page', $destination->raw_value() );
	}

	/**
	 * Test raw_value returns post ID for post destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::raw_value
	 */
	public function test_raw_value_returns_post_id(): void {
		$destination = Destination::from_post_id( DestinationPostId::from_int( 123 ) );

		$this->assertSame( 123, $destination->raw_value() );
	}

	/**
	 * Test equals with same URL destinations.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::equals
	 */
	public function test_equals_same_url(): void {
		$destination1 = Destination::from_url( DestinationUrl::from_string( '/page' ) );
		$destination2 = Destination::from_url( DestinationUrl::from_string( '/page' ) );

		$this->assertTrue( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test equals with different URL destinations.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::equals
	 */
	public function test_equals_different_url(): void {
		$destination1 = Destination::from_url( DestinationUrl::from_string( '/page-one' ) );
		$destination2 = Destination::from_url( DestinationUrl::from_string( '/page-two' ) );

		$this->assertFalse( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test equals with same post ID destinations.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::equals
	 */
	public function test_equals_same_post_id(): void {
		$destination1 = Destination::from_post_id( DestinationPostId::from_int( 100 ) );
		$destination2 = Destination::from_post_id( DestinationPostId::from_int( 100 ) );

		$this->assertTrue( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test equals with different post ID destinations.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::equals
	 */
	public function test_equals_different_post_id(): void {
		$destination1 = Destination::from_post_id( DestinationPostId::from_int( 100 ) );
		$destination2 = Destination::from_post_id( DestinationPostId::from_int( 200 ) );

		$this->assertFalse( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test equals with different types.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::equals
	 */
	public function test_equals_different_types(): void {
		$destination1 = Destination::from_url( DestinationUrl::from_string( '/page' ) );
		$destination2 = Destination::from_post_id( DestinationPostId::from_int( 123 ) );

		$this->assertFalse( $destination1->equals( $destination2 ) );
	}

	/**
	 * Test __toString for URL destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::__toString
	 */
	public function test_to_string_url(): void {
		$destination = Destination::from_url( DestinationUrl::from_string( '/page' ) );

		$this->assertSame( '/page', (string) $destination );
	}

	/**
	 * Test __toString for post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::__toString
	 */
	public function test_to_string_post_id(): void {
		$destination = Destination::from_post_id( DestinationPostId::from_int( 999 ) );

		$this->assertSame( '999', (string) $destination );
	}
}
