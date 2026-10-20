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
use Automattic\LegacyRedirector\Domain\InvalidRedirectValue;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use LogicException;

/**
 * DestinationTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
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
	 * Test an int, or a string of digits only, is a post ID.
	 *
	 * @dataProvider data_post_id_values
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 *
	 * @param int|string $value    The destination value.
	 * @param int        $expected The post ID it names.
	 */
	public function test_from_mixed_treats_digits_as_a_post_id( int|string $value, int $expected ): void {
		$destination = Destination::from_mixed( $value );

		$this->assertTrue( $destination->is_post_id() );
		$this->assertSame( $expected, $destination->as_post_id()->value() );
	}

	/**
	 * Destination values that name a post.
	 *
	 * @return array<string, array{int|string, int}>
	 */
	public static function data_post_id_values(): array {
		return array(
			'int'           => array( 456, 456 ),
			'digits string' => array( '789', 789 ),
		);
	}

	/**
	 * Test a numeric string that is not all digits is refused as a post ID,
	 * not cast to some other post's ID or refused as a URL.
	 *
	 * @dataProvider data_numeric_but_not_digits
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 *
	 * @param string $value The destination value.
	 */
	public function test_from_mixed_refuses_numeric_strings_that_are_not_all_digits( string $value ): void {
		$this->expectException( InvalidRedirectValue::class );
		$this->expectExceptionMessage( 'Post ID must contain only digits.' );

		Destination::from_mixed( $value );
	}

	/**
	 * Strings is_numeric() accepts that are not a post ID.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_numeric_but_not_digits(): array {
		return array(
			'decimal'        => array( '12.7' ),
			'exponent'       => array( '1e3' ),
			'leading space'  => array( ' 12' ),
			'trailing space' => array( '12 ' ),
			'negative'       => array( '-5' ),
			'plus sign'      => array( '+12' ),
		);
	}

	/**
	 * Test a string that is neither digits nor numeric is still parsed as a
	 * URL and refused with the URL error.
	 *
	 * @dataProvider data_not_numeric
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 *
	 * @param string $value The destination value.
	 */
	public function test_from_mixed_refuses_non_numeric_junk_as_a_url( string $value ): void {
		$this->expectException( InvalidRedirectValue::class );
		$this->expectExceptionMessage( 'Absolute destination URLs must use http or https scheme.' );

		Destination::from_mixed( $value );
	}

	/**
	 * Strings is_numeric() refuses that are not a URL either.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_not_numeric(): array {
		return array(
			'word'          => array( 'nonsense' ),
			'digits, words' => array( '12abc' ),
			'hexadecimal'   => array( '0x1A' ), // phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- Pinned as a string that is not a post ID.
		);
	}

	/**
	 * Test zero is still refused as a post ID, as an int or a string.
	 *
	 * @dataProvider data_zero_values
	 * @covers \Automattic\LegacyRedirector\Domain\Destination::from_mixed
	 *
	 * @param int|string $value The destination value.
	 */
	public function test_from_mixed_refuses_zero( int|string $value ): void {
		$this->expectException( InvalidRedirectValue::class );
		$this->expectExceptionMessage( 'Post ID must be a positive integer.' );

		Destination::from_mixed( $value );
	}

	/**
	 * Zero in each form.
	 *
	 * @return array<string, array{int|string}>
	 */
	public static function data_zero_values(): array {
		return array(
			'int'    => array( 0 ),
			'string' => array( '0' ),
		);
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
