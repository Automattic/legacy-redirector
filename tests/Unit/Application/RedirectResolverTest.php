<?php
/**
 * RedirectResolver service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\RedirectResolver;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * RedirectResolverTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
 */
final class RedirectResolverTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The resolver under test.
	 *
	 * @var RedirectResolver
	 */
	private RedirectResolver $resolver;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->resolver   = new RedirectResolver( $this->repository );
	}

	/**
	 * Stub home_url() to return a URL for path extraction and destination resolution.
	 *
	 * In subdirectory multisite, extract_path() uses home_url() to strip the subsite
	 * path prefix. For single-site (default), it returns a URL without path component.
	 *
	 * @param string $url The URL to return (default: https://example.com).
	 */
	private function stub_home_url( string $url = 'https://example.com' ): void {
		Functions\when( 'home_url' )->justReturn( $url );
	}

	/**
	 * Creates a test source URL.
	 *
	 * @param string $path The URL path.
	 * @return SourceUrl
	 */
	private function create_source( string $path = '/old-page' ): SourceUrl {
		return SourceUrl::from_string( $path );
	}

	/**
	 * Creates a URL destination.
	 *
	 * @param string $url The destination URL.
	 * @return Destination
	 */
	private function create_url_destination( string $url = '/new-page' ): Destination {
		return Destination::from_url( DestinationUrl::from_string( $url ) );
	}

	/**
	 * Creates a post ID destination.
	 *
	 * @param int $post_id The post ID.
	 * @return Destination
	 */
	private function create_post_id_destination( int $post_id = 123 ): Destination {
		return Destination::from_post_id( DestinationPostId::from_int( $post_id ) );
	}

	/**
	 * Creates a test Redirect entity.
	 *
	 * @param string $source_path      The source path.
	 * @param string $destination_path The destination path.
	 * @param string $status           The redirect status.
	 * @return Redirect
	 */
	private function create_redirect( string $source_path = '/old-page', string $destination_path = '/new-page', string $status = 'publish' ): Redirect {
		return Redirect::reconstitute(
			123,
			$this->create_source( $source_path ),
			$this->create_url_destination( $destination_path ),
			$status
		);
	}

	/**
	 * Expect one lookup: no query parameters preserved, and the repository
	 * asked once.
	 *
	 * @param Redirect|null $redirect What the repository returns.
	 * @param string|null   $source   The source the lookup must be for, as an
	 *                                admin would have saved it; null for any.
	 * @return void
	 */
	private function expect_lookup( ?Redirect $redirect, ?string $source = null ): void {
		Filters\expectApplied( 'legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$lookup = $this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		if ( null !== $source ) {
			$expected = SourceUrl::from_string( $source );
			$lookup->with( Mockery::on( fn( SourceUrl $s ) => $s->hash() === $expected->hash() ) );
		}
	}

	/**
	 * Test a found redirect resolves to its destination with the default status.
	 *
	 * @dataProvider data_destinations
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 *
	 * @param string     $request     The requested URL, for the source /old-page.
	 * @param string|int $destination The stored destination: a URL or a post ID.
	 * @param string     $expected    The URL redirected to.
	 */
	public function test_get_redirect_data_resolves_destination( string $request, $destination, string $expected ): void {
		$this->stub_home_url();

		if ( is_int( $destination ) ) {
			Functions\expect( 'get_permalink' )->once()->with( $destination )->andReturn( $expected );
			$destination = $this->create_post_id_destination( $destination );
		} else {
			$destination = $this->create_url_destination( $destination );
		}

		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( '/old-page' )
			->andReturnFirstArg();

		$this->expect_lookup( Redirect::reconstitute( 123, $this->create_source(), $destination, 'publish' ), '/old-page' );

		Filters\expectApplied( 'legacy_redirector_redirect_status' )
			->once()
			->with( 301, $request )
			->andReturnFirstArg();

		$this->assertSame(
			array(
				'url'         => $expected,
				'status_code' => 301,
			),
			$this->resolver->get_redirect_data( $request )
		);
	}

	/**
	 * Requests for /old-page, its stored destination, and the URL redirected to.
	 *
	 * @return array<string, array{string, string|int, string}>
	 */
	public static function data_destinations(): array {
		return array(
			'relative destination' => array( '/old-page', '/new-page', 'https://example.com/new-page' ),
			'full request URL'     => array( 'https://example.com/old-page', '/new-page', 'https://example.com/new-page' ),
			'absolute destination' => array( '/old-page', 'https://external.com/page', 'https://external.com/page' ),
			'post ID destination'  => array( '/old-page', 456, 'https://example.com/destination-post' ),
		);
	}

	/**
	 * Test get_redirect_data applies legacy_redirector_request_path filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_request_path_filter(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/modified-path', '/new-page' );

		// The filter modifies the path.
		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( '/original-path' )
			->andReturn( '/modified-path' );

		// Repository receives the modified path.
		$this->expect_lookup( $redirect, '/modified-path' );

		$this->assertIsArray( $this->resolver->get_redirect_data( '/original-path' ) );
	}

	/**
	 * Test get_redirect_data preserves specified query parameters.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_preserves_specified_query_params(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page?other=value', '/new-page' );

		// Path includes query string.
		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( '/old-page?utm_source=test&other=value' )
			->andReturnFirstArg();

		// Filter returns keys to preserve.
		Filters\expectApplied( 'legacy_redirector_preserve_query_params' )
			->once()
			->with( array(), '/old-page?utm_source=test&other=value' )
			->andReturn( array( 'utm_source' ) );

		// Repository receives path without preserved params - now the path includes the remaining query string.
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/old-page?other=value' ) )
			->andReturn( $redirect );

		// Mock add_query_arg to append preserved params to destination.
		Functions\expect( 'add_query_arg' )
			->once()
			->with( array( 'utm_source' => 'test' ), 'https://example.com/new-page' )
			->andReturn( 'https://example.com/new-page?utm_source=test' );

		Filters\expectApplied( 'legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/old-page?utm_source=test&other=value' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page?utm_source=test', $result['url'] );
	}

	/**
	 * Stripping a preserved key leaves every other byte of the query alone.
	 *
	 * @dataProvider data_queries_around_a_preserved_key
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 *
	 * @param string $request The request path, carrying utm_source.
	 * @param string $lookup  The path the lookup should use.
	 */
	public function test_get_redirect_data_strips_preserved_key_without_respelling_the_query( string $request, string $lookup ): void {
		$this->stub_home_url();
		Filters\expectApplied( 'legacy_redirector_preserve_query_params' )->andReturn( array( 'utm_source' ) );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( SourceUrl $source ) => $source->path() === SourceUrl::from_string( $lookup )->path() ) )
			->andReturn( null );

		$this->assertNull( $this->resolver->get_redirect_data( $request ) );
	}

	/**
	 * Requests carrying utm_source, and the lookup path each should leave.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_queries_around_a_preserved_key(): array {
		return array(
			'encoded space'          => array( '/p?q=foo%20bar&utm_source=x', '/p?q=foo%20bar' ),
			'dotted key'             => array( '/p?a.b=1&utm_source=x', '/p?a.b=1' ),
			'duplicate keys'         => array( '/p?t=a&utm_source=x&t=b', '/p?t=a&t=b' ),
			'array syntax'           => array( '/p?utm_source=x&x[]=1', '/p?x[]=1' ),
			'only the preserved key' => array( '/p?utm_source=x', '/p' ),
			'no preserved key'       => array( '/p?q=foo%20bar&t=a&t=b', '/p?q=foo%20bar&t=a&t=b' ),
		);
	}

	/**
	 * Test the redirect_status filter sets the status, and an invalid code
	 * falls back to the default.
	 *
	 * @dataProvider data_filtered_status_codes
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 *
	 * @param int $filtered The code the filter returns.
	 * @param int $expected The status code redirected with.
	 */
	public function test_get_redirect_data_applies_redirect_status_filter( int $filtered, int $expected ): void {
		$this->stub_home_url();
		$this->expect_lookup( $this->create_redirect() );

		Filters\expectApplied( 'legacy_redirector_redirect_status' )
			->once()
			->with( 301, '/old-page' )
			->andReturn( $filtered );

		$this->assertSame( $expected, $this->resolver->get_redirect_data( '/old-page' )['status_code'] ?? null );
	}

	/**
	 * Status codes a filter might return, and the code redirected with.
	 *
	 * @return array<string, array{int, int}>
	 */
	public static function data_filtered_status_codes(): array {
		return array(
			'redirect code'       => array( 302, 302 ),
			'not a redirect code' => array( 200, 301 ),
		);
	}

	/**
	 * Test get_redirect_data applies legacy_redirector_destination_url filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_destination_url_filter(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		// The request path filter strips the /amp suffix before lookup.
		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( '/old-page/amp' )
			->andReturn( '/old-page' );

		$this->expect_lookup( $redirect, '/old-page' );

		// The destination filter receives the resolved URL, filtered path, and original URL.
		Filters\expectApplied( 'legacy_redirector_destination_url' )
			->once()
			->with( 'https://example.com/new-page', '/old-page', '/old-page/amp' )
			->andReturn( 'https://example.com/new-page/amp' );

		$result = $this->resolver->get_redirect_data( '/old-page/amp' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page/amp', $result['url'] );
	}

	/**
	 * Test get_redirect_data returns null when the destination filter empties the URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_destination_filter_empties_url(): void {
		$this->stub_home_url();
		$this->expect_lookup( $this->create_redirect() );

		Filters\expectApplied( 'legacy_redirector_destination_url' )
			->once()
			->andReturn( '' );

		$this->assertNull( $this->resolver->get_redirect_data( '/old-page' ) );
	}

	/**
	 * Test get_redirect_data returns null for empty path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_for_empty_path(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( '' )
			->andReturn( '' );

		$result = $this->resolver->get_redirect_data( '' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when redirect not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_redirect_not_found(): void {
		$this->stub_home_url();
		$this->expect_lookup( null );

		$this->assertNull( $this->resolver->get_redirect_data( '/nonexistent-page' ) );
	}

	/**
	 * Test get_redirect_data returns null for invalid source URL.
	 *
	 * When SourceUrl::from_string() throws InvalidArgumentException (e.g., for
	 * a URL its sanitizing empties), get_redirect_data() catches it and
	 * returns null.
	 *
	 * We simulate this by having the request_path filter return a string that
	 * causes SourceUrl::from_string() to throw.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_for_invalid_source_url(): void {
		$this->stub_home_url();

		// The filter returns a URL with a scheme and host but neither path
		// nor query, which SourceUrl::from_string() rejects once Url::parse()
		// has split it.
		$url_without_path = 'http://example.com';

		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->andReturn( $url_without_path );

		Filters\expectApplied( 'legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		// SourceUrl::from_string('http://example.com') should throw because
		// after normalization there's no path or query - just scheme and host.
		$result = $this->resolver->get_redirect_data( '/some-input' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when get_permalink returns false.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_permalink_fails(): void {
		$this->stub_home_url();
		$redirect = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_post_id_destination( 456 ),
			'publish'
		);

		$this->expect_lookup( $redirect );

		// get_permalink returns false (post doesn't exist or can't get permalink).
		Functions\expect( 'get_permalink' )
			->once()
			->with( 456 )
			->andReturn( false );

		$this->assertNull( $this->resolver->get_redirect_data( '/old-page' ) );
	}

	/**
	 * A request must hash to the same SourceUrl as the stored source created
	 * from the equivalent string.
	 *
	 * The lookup path used to urldecode() before parsing while creation decoded
	 * once, so a source containing %25 could never be matched, and an encoded
	 * %23 or %3F was parsed as a real fragment or query delimiter.
	 *
	 * @dataProvider data_request_and_stored_source
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 *
	 * @param string $request_path  The raw request path, as a browser would send it.
	 * @param string $stored_source The source string an admin would have saved.
	 */
	public function test_lookup_hash_matches_creation_hash( string $request_path, string $stored_source ): void {
		$this->stub_home_url();
		$this->expect_lookup( $this->create_redirect( $stored_source ), $stored_source );

		$this->assertIsArray( $this->resolver->get_redirect_data( $request_path ) );
	}

	/**
	 * Data provider of request paths and the stored source they must match.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_request_and_stored_source(): array {
		return array(
			'encoded percent'     => array( '/100%25-cotton', '/100%25-cotton' ),
			'double encoded'      => array( '/a%2520b', '/a%2520b' ),
			'encoded hash'        => array( '/page%23section', '/page%23section' ),
			'encoded question'    => array( '/page%3Fnot-a-query', '/page%3Fnot-a-query' ),
			'encoded space'       => array( '/hello%20world', '/hello world' ),
			'plus'                => array( '/hello+world', '/hello+world' ),
			'encoded slash'       => array( '/a%2Fb', '/a%2Fb' ),
			'encoded ampersand'   => array( '/a%26b', '/a%26b' ),
			'multibyte'           => array( '/%D9%81%D9%88%D8%AA%D9%88/', '/فوتو/' ),
			'multibyte in query'  => array( '/photos/?test=%D9%81%D9%88%D8%AA%D9%88', '/photos/?test=فوتو' ),
			'cyrillic'            => array( '/%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82', '/привет' ),
			'cyrillic in query'   => array( '/page?q=%D1%82%D0%B5%D1%81%D1%82', '/page?q=тест' ),
			'emoji'               => array( '/%F0%9F%8E%89', '/🎉' ),
			'emoji in query'      => array( '/page?mood=%F0%9F%8E%89', '/page?mood=🎉' ),
			'japanese'            => array( '/JP%E7%B4%8D%E8%B1%86', '/JP納豆' ),
			'query preserved'     => array( '/page?a=1&b=2', '/page?a=1&b=2' ),
			// Characters sanitizing strips when typed are stripped from the
			// request too, before the parser can encode them, as 1.x did.
			'raw braces in query' => array( '/page?filter={all}', '/page?filter={all}' ),
			'raw caret in path'   => array( '/a^b', '/a^b' ),
		);
	}

	/**
	 * Test a request is looked up relative to the home path, query kept.
	 *
	 * The request reaches the filter still percent-encoded, as the browser
	 * sent it, while home_url() returns the home path decoded; the lookup must
	 * strip it anyway, then put the query back. Which paths count as under
	 * home is HomePath's to decide, and HomePathTest::data_make_relative()
	 * holds those cases; this test and the next prove the resolver asks it.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_strips_home_path_and_keeps_query(): void {
		$this->assert_lookup_path(
			'https://example.com/日本',
			'https://example.com/%E6%97%A5%E6%9C%AC/page?q=%D1%82%D0%B5%D1%81%D1%82',
			'/page?q=%D1%82%D0%B5%D1%81%D1%82',
			'/page?q=тест'
		);
	}

	/**
	 * Test a path not under the home path is looked up exactly as it arrived.
	 *
	 * Without a segment boundary, '/blogging-tips' would be stripped to
	 * '/ging-tips': its own redirect could never fire, and an unrelated
	 * redirect for '/ging-tips' would fire in its place.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_leaves_path_outside_home_intact(): void {
		$this->assert_lookup_path( 'https://example.com/blog', '/blogging-tips', '/blogging-tips' );
	}

	/**
	 * Test a raw-multibyte request URI reaches the stored source's hash.
	 *
	 * Browsers percent-encode, so the ordinary path never exercises this. A
	 * client that puts raw UTF-8 bytes on the request line does, and until the
	 * resolver stopped parsing them with a bare wp_parse_url() the bytes were
	 * rewritten to underscores on any host whose LC_CTYPE flags the C1 range
	 * as control characters - so the request resolved to '/日_日_' and matched
	 * nothing.
	 *
	 * Only macOS libc flags that range, and only under a UTF-8 LC_CTYPE, so
	 * the locale is forced. glibc (CI) and musl never flag it, so on Linux the
	 * unfixed code passes this test too: it guards macOS dev machines.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_raw_multibyte_request_matches_the_stored_source(): void {
		$previous = setlocale( LC_CTYPE, '0' );
		$applied  = setlocale( LC_CTYPE, 'en_GB.UTF-8', 'en_US.UTF-8', 'C.UTF-8', 'UTF-8' );

		if ( false === $applied ) {
			$this->markTestSkipped( 'No UTF-8 locale available to force LC_CTYPE.' );
		}

		try {
			$this->stub_home_url();
			$this->expect_lookup( $this->create_redirect( '/日本' ), '/日本' );

			$this->assertIsArray( $this->resolver->get_redirect_data( 'https://example.com/日本' ) );
		} finally {
			setlocale( LC_CTYPE, (string) $previous );
		}
	}

	/**
	 * Assert which path a request URL is looked up under for a given home URL.
	 *
	 * @param string      $home_url      The site's home URL.
	 * @param string      $request_url   The requested URL.
	 * @param string      $extracted     The path handed to the request_path filter,
	 *                                   still percent-encoded as the browser sent it.
	 * @param string|null $source_path   The normalized path the repository is queried
	 *                                   with, when decoding makes it differ from
	 *                                   $extracted. Defaults to $extracted.
	 * @return void
	 */
	private function assert_lookup_path( string $home_url, string $request_url, string $extracted, ?string $source_path = null ): void {
		$source_path = $source_path ?? $extracted;

		$this->stub_home_url( $home_url );

		Filters\expectApplied( 'legacy_redirector_request_path' )
			->once()
			->with( $extracted )
			->andReturnFirstArg();

		$this->expect_lookup( null, $source_path );

		$this->assertNull( $this->resolver->get_redirect_data( $request_url ) );
	}

	/**
	 * Test find_redirect returns redirect entity when found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::find_redirect
	 */
	public function test_find_redirect_returns_redirect_when_found(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/old-page' ) )
			->andReturn( $redirect );

		$result = $this->resolver->find_redirect( '/old-page' );

		$this->assertInstanceOf( Redirect::class, $result );
		$this->assertSame( '/old-page', $result->source()->path() );
	}

	/**
	 * Test find_redirect returns null when not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::find_redirect
	 */
	public function test_find_redirect_returns_null_when_not_found(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( null );

		$result = $this->resolver->find_redirect( '/nonexistent' );

		$this->assertNull( $result );
	}

	/**
	 * Test find_redirect returns null for invalid URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::find_redirect
	 */
	public function test_find_redirect_returns_null_for_invalid_url(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// Invalid URL that throws InvalidArgumentException in SourceUrl::from_string().
		$result = $this->resolver->find_redirect( '' );

		$this->assertNull( $result );
	}
}
