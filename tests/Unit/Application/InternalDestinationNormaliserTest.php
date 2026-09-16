<?php
/**
 * InternalDestinationNormaliser unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\InternalDestinationNormaliser;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * InternalDestinationNormaliserTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class InternalDestinationNormaliserTest extends MonkeyStubs {

	/**
	 * The normaliser under test.
	 *
	 * @var InternalDestinationNormaliser
	 */
	private InternalDestinationNormaliser $normaliser;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normaliser = new InternalDestinationNormaliser();
	}

	/**
	 * Test absolute URLs are rewritten to relative paths exactly when internal.
	 *
	 * @dataProvider data_normalise
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser::normalise
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser::to_internal_path
	 *
	 * @param string $home_url The site's home URL.
	 * @param string $input    The destination as entered.
	 * @param string $expected The destination as stored.
	 * @return void
	 */
	public function test_normalise( string $home_url, string $input, string $expected ): void {
		Functions\when( 'home_url' )->justReturn( $home_url );

		$destination = Destination::from_url( DestinationUrl::from_string( $input ) );

		$this->assertSame( $expected, $this->normaliser->normalise( $destination )->as_url()->value() );
	}

	/**
	 * Data provider for test_normalise.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function data_normalise(): array {
		return array(
			// Single site.
			'relative path untouched'           => array( 'https://example.com', '/foo', '/foo' ),
			'internal absolute made relative'   => array( 'https://example.com', 'https://example.com/foo', '/foo' ),
			'scheme difference still internal'  => array( 'https://example.com', 'http://example.com/foo', '/foo' ),
			'host match is case-insensitive'    => array( 'https://example.com', 'https://EXAMPLE.com/foo', '/foo' ),
			'bare host becomes home'            => array( 'https://example.com', 'https://example.com', '/' ),
			'trailing slash becomes home'       => array( 'https://example.com', 'https://example.com/', '/' ),
			'query preserved'                   => array( 'https://example.com', 'https://example.com/foo?a=1', '/foo?a=1' ),
			'fragment preserved'                => array( 'https://example.com', 'https://example.com/foo#bar', '/foo#bar' ),
			'external untouched'                => array( 'https://example.com', 'https://google.com/x', 'https://google.com/x' ),
			'www variant untouched'             => array( 'https://example.com', 'https://www.example.com/foo', 'https://www.example.com/foo' ),
			'host suffix attack untouched'      => array( 'https://example.com', 'https://example.com.attacker.net/x', 'https://example.com.attacker.net/x' ),
			'query mentioning host untouched'   => array( 'https://example.com', 'https://evil.com/?ref=example.com', 'https://evil.com/?ref=example.com' ),
			'different port untouched'          => array( 'https://example.com', 'https://example.com:8080/foo', 'https://example.com:8080/foo' ),
			'double slash untouched'            => array( 'https://example.com', 'https://example.com//foo', 'https://example.com//foo' ),
			'credentials untouched'             => array( 'https://example.com', 'https://user:pass@example.com/foo', 'https://user:pass@example.com/foo' ),

			// Subdirectory multisite.
			'subsite path made relative'        => array( 'https://example.com/sub1', 'https://example.com/sub1/foo', '/foo' ),
			'subsite root becomes home'         => array( 'https://example.com/sub1', 'https://example.com/sub1', '/' ),
			'subsite prefix boundary held'      => array( 'https://example.com/sub1', 'https://example.com/sub10/foo', 'https://example.com/sub10/foo' ),
			'other subsite untouched'           => array( 'https://example.com/sub1', 'https://example.com/sub2/bar', 'https://example.com/sub2/bar' ),
			'network root untouched'            => array( 'https://example.com/sub1', 'https://example.com/', 'https://example.com/' ),
			'subsite double slash untouched'    => array( 'https://example.com/sub1', 'https://example.com/sub1//x', 'https://example.com/sub1//x' ),

			// Unicode. Percent-encoded forms dominate because that is what a
			// browser copy-paste produces; the decoded rows use Latin-1
			// supplement characters, whose UTF-8 continuation bytes all sit
			// above 0x9F. PHP's parse_url() rewrites raw bytes in 0x80-0x9F to
			// '_' when LC_CTYPE is a UTF-8 locale, which would otherwise make
			// these assertions depend on the runner's locale.
			'encoded unicode made relative'     => array( 'https://example.com', 'https://example.com/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82' ),
			'encoded emoji made relative'       => array( 'https://example.com', 'https://example.com/%F0%9F%8E%89', '/%F0%9F%8E%89' ),
			'decoded unicode made relative'     => array( 'https://example.com', 'https://example.com/café', '/café' ),
			'unicode query preserved'           => array( 'https://example.com', 'https://example.com/foo?q=%D1%82%D0%B5%D1%81%D1%82', '/foo?q=%D1%82%D0%B5%D1%81%D1%82' ),
			'unicode fragment preserved'        => array( 'https://example.com', 'https://example.com/foo#café', '/foo#café' ),
			'external unicode untouched'        => array( 'https://example.com', 'https://google.com/café', 'https://google.com/café' ),
			'unicode under subsite prefix'      => array( 'https://example.com/sub1', 'https://example.com/sub1/café', '/café' ),
			'encoded unicode under subsite'     => array( 'https://example.com/sub1', 'https://example.com/sub1/%F0%9F%8E%89', '/%F0%9F%8E%89' ),
			'unicode outside subsite untouched' => array( 'https://example.com/sub1', 'https://example.com/sub2/café', 'https://example.com/sub2/café' ),
			'unicode home path made relative'   => array( 'https://example.com/café', 'https://example.com/café/page', '/page' ),
			'unicode home path boundary held'   => array( 'https://example.com/café', 'https://example.com/cafétéria/page', 'https://example.com/cafétéria/page' ),
		);
	}

	/**
	 * Test post ID destinations pass through untouched.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser::normalise
	 */
	public function test_normalise_leaves_post_id_destinations_alone(): void {
		$destination = Destination::from_post_id( DestinationPostId::from_int( 5 ) );

		$this->assertSame( $destination, $this->normaliser->normalise( $destination ) );
	}

	/**
	 * Test external URLs are reported as not internal.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser::to_internal_path
	 */
	public function test_to_internal_path_returns_null_for_external_urls(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$this->assertNull( $this->normaliser->to_internal_path( 'https://google.com/x' ) );
	}
}
