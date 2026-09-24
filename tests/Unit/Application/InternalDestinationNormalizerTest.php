<?php
/**
 * InternalDestinationNormalizer unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\InternalDestinationNormalizer;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * InternalDestinationNormalizerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class InternalDestinationNormalizerTest extends MonkeyStubs {

	/**
	 * The normalizer under test.
	 *
	 * @var InternalDestinationNormalizer
	 */
	private InternalDestinationNormalizer $normalizer;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->normalizer = new InternalDestinationNormalizer();
	}

	/**
	 * Test absolute URLs are rewritten to relative paths exactly when internal.
	 *
	 * @dataProvider data_normalize
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::normalize
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::to_internal_path
	 *
	 * @param string $home_url The site's home URL.
	 * @param string $input    The destination as entered.
	 * @param string $expected The destination as stored.
	 * @return void
	 */
	public function test_normalize( string $home_url, string $input, string $expected ): void {
		Functions\when( 'home_url' )->justReturn( $home_url );

		$destination = Destination::from_url( DestinationUrl::from_string( $input ) );

		$this->assertSame( $expected, $this->normalizer->normalize( $destination )->as_url()->value() );
	}

	/**
	 * Data provider for test_normalize.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function data_normalize(): array {
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

			// Unicode and encoding canonicalization: '/café' and '/caf%C3%A9'
			// are two spellings of one target, so both store as the decoded
			// form. The query alone keeps its percent-encoding, because its
			// values have sub-structure a decode would corrupt.
			'encoded unicode made relative'     => array( 'https://example.com', 'https://example.com/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/привет' ),
			'encoded emoji made relative'       => array( 'https://example.com', 'https://example.com/%F0%9F%8E%89', '/🎉' ),
			'decoded unicode made relative'     => array( 'https://example.com', 'https://example.com/café', '/café' ),
			'unicode query preserved'           => array( 'https://example.com', 'https://example.com/foo?q=%D1%82%D0%B5%D1%81%D1%82', '/foo?q=%D1%82%D0%B5%D1%81%D1%82' ),
			'unicode fragment preserved'        => array( 'https://example.com', 'https://example.com/foo#café', '/foo#café' ),
			'external unicode untouched'        => array( 'https://example.com', 'https://google.com/café', 'https://google.com/café' ),
			'unicode under subsite prefix'      => array( 'https://example.com/sub1', 'https://example.com/sub1/café', '/café' ),
			'encoded unicode under subsite'     => array( 'https://example.com/sub1', 'https://example.com/sub1/%F0%9F%8E%89', '/🎉' ),
			'unicode outside subsite untouched' => array( 'https://example.com/sub1', 'https://example.com/sub2/café', 'https://example.com/sub2/café' ),
			'unicode home path made relative'   => array( 'https://example.com/café', 'https://example.com/café/page', '/page' ),
			'unicode home path boundary held'   => array( 'https://example.com/café', 'https://example.com/cafétéria/page', 'https://example.com/cafétéria/page' ),

			// Encoding canonicalization edges.
			'matching port made relative'       => array( 'https://example.com:8080', 'https://example.com:8080/foo', '/foo' ),
			'relative encoded canonicalized'    => array( 'https://example.com', '/caf%C3%A9', '/café' ),
			'relative decoded untouched'        => array( 'https://example.com', '/café', '/café' ),
			'raw query becomes encoded'         => array( 'https://example.com', 'https://example.com/foo?q=тест', '/foo?q=%D1%82%D0%B5%D1%81%D1%82' ),
			'literal %26 in query preserved'    => array( 'https://example.com', 'https://example.com/foo?q=a%26b', '/foo?q=a%26b' ),
			'encoded fragment decoded'          => array( 'https://example.com', 'https://example.com/foo#caf%C3%A9', '/foo#café' ),
			// An encoded slash decodes to a real one, exactly as SourceUrl
			// treats sources; a destination relying on the distinction was
			// ambiguous to begin with.
			'encoded slash in path decodes'     => array( 'https://example.com', 'https://example.com/a%2Fb', '/a/b' ),
			// Unless decoding would make the path scheme-relative.
			'decoding to double slash refused'  => array( 'https://example.com', '/%2F%2Fx', '/%2F%2Fx' ),

			// Escapes whose decoded form would mean something else, or could
			// not be stored, stay encoded - in upper case, so one target
			// still has one spelling.
			'encoded question mark kept'        => array( 'https://example.com', '/a%3Fb', '/a%3Fb' ),
			'encoded hash kept'                 => array( 'https://example.com', '/a%23b', '/a%23b' ),
			'encoded percent kept'              => array( 'https://example.com', '/100%25', '/100%25' ),
			'encoded NUL kept'                  => array( 'https://example.com', '/a%00b', '/a%00b' ),
			'encoded newline kept'              => array( 'https://example.com', '/a%0Ab', '/a%0Ab' ),
			'invalid UTF-8 byte kept'           => array( 'https://example.com', '/%FF/x', '/%FF/x' ),
			'truncated UTF-8 sequence kept'     => array( 'https://example.com', '/caf%C3', '/caf%C3' ),
			'overlong UTF-8 sequence kept'      => array( 'https://example.com', '/%C0%AF', '/%C0%AF' ),
			'kept escapes upper-cased'          => array( 'https://example.com', '/a%3fb', '/a%3Fb' ),
			'safe decoded beside kept'          => array( 'https://example.com', '/caf%C3%A9%3F', '/café%3F' ),
			'kept escape in fragment'           => array( 'https://example.com', 'https://example.com/foo#a%23b', '/foo#a%23b' ),
			'encoded plus kept'                 => array( 'https://example.com', '/a%2Bb', '/a%2Bb' ),
			// Url::parse_encoded() encodes a raw space as '+', so a literal
			// '+' reaches the normalizer indistinguishable from a space.
			'literal plus read as a space'      => array( 'https://example.com', '/a+b', '/a b' ),
		);
	}

	/**
	 * Test canonicalizing an already canonical destination changes nothing.
	 *
	 * The migration re-walks stored destinations on every version bump, so a
	 * second pass must not decode what the first deliberately kept - '%2541'
	 * once became '%41', then 'A'.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::canonicalize
	 */
	public function test_canonicalize_is_idempotent(): void {
		foreach ( array( '/a%2541', '/100%25', '/a%3Fb', '/caf%C3%A9%3F', '/%FF/x', '/a+b', '/a%20b', '/a%2Bb', '/foo?q=a%26b#caf%C3%A9' ) as $relative ) {
			$once = $this->normalizer->canonicalize( $relative );

			$this->assertSame( $once, $this->normalizer->canonicalize( (string) $once ), $relative . ' should canonicalize the same way twice.' );
		}
	}

	/**
	 * Test both spellings of one internal destination reach one stored form.
	 *
	 * The inconsistency this canonicalization exists to remove: before it,
	 * whichever encoding the admin happened to type was what got stored, so
	 * one target could be two different strings.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::normalize
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::canonicalize
	 */
	public function test_encoded_and_decoded_forms_converge(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$stored = array();
		foreach ( array( 'https://example.com/caf%C3%A9', 'https://example.com/café', '/caf%C3%A9', '/café' ) as $entered ) {
			$destination = Destination::from_url( DestinationUrl::from_string( $entered ) );
			$stored[]    = $this->normalizer->normalize( $destination )->as_url()->value();
		}

		$this->assertSame( array( '/café', '/café', '/café', '/café' ), $stored );
	}

	/**
	 * Test post ID destinations pass through untouched.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::normalize
	 */
	public function test_normalize_leaves_post_id_destinations_alone(): void {
		$destination = Destination::from_post_id( DestinationPostId::from_int( 5 ) );

		$this->assertSame( $destination, $this->normalizer->normalize( $destination ) );
	}

	/**
	 * Test external URLs are reported as not internal.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer::to_internal_path
	 */
	public function test_to_internal_path_returns_null_for_external_urls(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		$this->assertNull( $this->normalizer->to_internal_path( 'https://google.com/x' ) );
	}
}
