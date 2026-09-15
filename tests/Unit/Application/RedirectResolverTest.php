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
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
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

	// =========================================================================
	// get_redirect_data tests - Happy Path
	// =========================================================================

	/**
	 * Test get_redirect_data returns redirect data for valid URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_redirect_data_for_valid_url(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		// Mock apply_filters for request_path filter (returns path unchanged).
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/old-page' )
			->andReturnFirstArg();

		// Mock apply_filters for preserve_query_params filter (no params to preserve).
		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->with( array(), '/old-page' )
			->andReturn( array() );

		// Mock the repository to return the redirect.
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/old-page' ) )
			->andReturn( $redirect );

		// Mock apply_filters for redirect_status filter.
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->with( 301, '/old-page' )
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page', $result['url'] );
		$this->assertSame( 301, $result['status_code'] );
	}

	/**
	 * Test get_redirect_data returns redirect data with post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_redirect_data_for_post_id_destination(): void {
		$this->stub_home_url();
		$redirect = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_post_id_destination( 456 ),
			'publish'
		);

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		// Mock get_permalink for post ID destination.
		Functions\expect( 'get_permalink' )
			->once()
			->with( 456 )
			->andReturn( 'https://example.com/destination-post' );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/destination-post', $result['url'] );
		$this->assertSame( 301, $result['status_code'] );
	}

	/**
	 * Test get_redirect_data with full URL input extracts path correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_extracts_path_from_full_url(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/old-page' )
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( 'https://example.com/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page', $result['url'] );
	}

	// =========================================================================
	// get_redirect_data tests - Request Path Filter
	// =========================================================================

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_request_path filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_request_path_filter(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/modified-path', '/new-page' );

		// The filter modifies the path.
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/original-path' )
			->andReturn( '/modified-path' );

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		// Repository receives the modified path.
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/modified-path' ) )
			->andReturn( $redirect );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/original-path' );

		$this->assertIsArray( $result );
	}

	// =========================================================================
	// get_redirect_data tests - Preserve Query Params
	// =========================================================================

	/**
	 * Test get_redirect_data preserves specified query parameters.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_preserves_specified_query_params(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page?other=value', '/new-page' );

		// Path includes query string.
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/old-page?utm_source=test&other=value' )
			->andReturnFirstArg();

		// Filter returns keys to preserve.
		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->with( array(), '/old-page?utm_source=test&other=value' )
			->andReturn( array( 'utm_source' ) );

		// Mock remove_query_arg to strip preserved params before lookup.
		Functions\expect( 'remove_query_arg' )
			->once()
			->with( array( 'utm_source' ), '/old-page?utm_source=test&other=value' )
			->andReturn( '/old-page?other=value' );

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

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/old-page?utm_source=test&other=value' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page?utm_source=test', $result['url'] );
	}

	// =========================================================================
	// get_redirect_data tests - Redirect Status Filter
	// =========================================================================

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_redirect_status filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_redirect_status_filter(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		// Filter changes status to 302.
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->with( 301, '/old-page' )
			->andReturn( 302 );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 302, $result['status_code'] );
	}

	// =========================================================================
	// get_redirect_data tests - Destination URL Filter
	// =========================================================================

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_destination_url filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_applies_destination_url_filter(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		// The request path filter strips the /amp suffix before lookup.
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/old-page/amp' )
			->andReturn( '/old-page' );

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		// The destination filter receives the resolved URL, filtered path, and original URL.
		Filters\expectApplied( 'wpcom_legacy_redirector_destination_url' )
			->once()
			->with( 'https://example.com/new-page', '/old-page', '/old-page/amp' )
			->andReturn( 'https://example.com/new-page/amp' );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

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
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		Filters\expectApplied( 'wpcom_legacy_redirector_destination_url' )
			->once()
			->andReturn( '' );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// get_redirect_data tests - Edge Cases
	// =========================================================================

	/**
	 * Test get_redirect_data returns null for empty path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_for_empty_path(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '' )
			->andReturn( '' );

		$result = $this->resolver->get_redirect_data( '' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when filter empties path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_filter_empties_path(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/some-path' )
			->andReturn( '' );

		$result = $this->resolver->get_redirect_data( '/some-path' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when redirect not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_redirect_not_found(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( null );

		$result = $this->resolver->get_redirect_data( '/nonexistent-page' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null for invalid source URL.
	 *
	 * When SourceUrl::from_string() throws InvalidArgumentException (e.g., for
	 * malformed URLs that esc_url_raw empties), get_redirect_data() catches it
	 * and returns null.
	 *
	 * We simulate this by having the request_path filter return a string that
	 * causes SourceUrl::from_string() to throw (e.g., a URL with only a scheme
	 * that esc_url_raw accepts but has no path).
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_for_invalid_source_url(): void {
		$this->stub_home_url();

		// The filter returns a path that, when passed to SourceUrl::from_string(),
		// will cause an InvalidArgumentException because after parsing it has
		// neither path nor query.
		// We use 'http://example.com' - after esc_url_raw it remains, but when
		// normalised in SourceUrl, it has no path (just scheme and host).
		// However the stub for esc_url_raw returns the input, and wp_parse_url
		// will parse it correctly giving just host/scheme with no path.

		$url_without_path = 'http://example.com';

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturn( $url_without_path );

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		// SourceUrl::from_string('http://example.com') should throw because
		// after normalisation there's no path or query - just scheme and host.
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

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		// get_permalink returns false (post doesn't exist or can't get permalink).
		Functions\expect( 'get_permalink' )
			->once()
			->with( 456 )
			->andReturn( false );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data handles URL-encoded characters.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_handles_encoded_characters(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/hello world', '/new-page' );

		// The path reaches the filter still encoded; SourceUrl owns decoding.
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/hello%20world' )
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/hello world' ) )
			->andReturn( $redirect );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/hello%20world' );

		$this->assertIsArray( $result );
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

		$expected = SourceUrl::from_string( $stored_source );
		$redirect = Redirect::reconstitute( 123, $expected, $this->create_url_destination(), 'publish' );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )->once()->andReturnFirstArg();
		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )->once()->andReturn( array() );
		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )->once()->andReturn( 301 );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( SourceUrl $s ) => $s->hash() === $expected->hash() ) )
			->andReturn( $redirect );

		$this->assertIsArray( $this->resolver->get_redirect_data( $request_path ) );
	}

	/**
	 * Data provider of request paths and the stored source they must match.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function data_request_and_stored_source(): array {
		return array(
			'encoded percent'    => array( '/100%25-cotton', '/100%25-cotton' ),
			'double encoded'     => array( '/a%2520b', '/a%2520b' ),
			'encoded hash'       => array( '/page%23section', '/page%23section' ),
			'encoded question'   => array( '/page%3Fnot-a-query', '/page%3Fnot-a-query' ),
			'encoded space'      => array( '/hello%20world', '/hello world' ),
			'plus'               => array( '/hello+world', '/hello+world' ),
			'encoded slash'      => array( '/a%2Fb', '/a%2Fb' ),
			'encoded ampersand'  => array( '/a%26b', '/a%26b' ),
			'multibyte'          => array( '/%D9%81%D9%88%D8%AA%D9%88/', '/فوتو/' ),
			'multibyte in query' => array( '/photos/?test=%D9%81%D9%88%D8%AA%D9%88', '/photos/?test=فوتو' ),
			'query preserved'    => array( '/page?a=1&b=2', '/page?a=1&b=2' ),
		);
	}

	/**
	 * Test get_redirect_data handles absolute destination URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_handles_absolute_destination_url(): void {
		$this->stub_home_url();
		$redirect = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			Destination::from_url( DestinationUrl::from_string( 'https://external.com/page' ) ),
			'publish'
		);

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( $redirect );

		Filters\expectApplied( 'wpcom_legacy_redirector_redirect_status' )
			->once()
			->andReturn( 301 );

		$result = $this->resolver->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://external.com/page', $result['url'] );
	}

	// =========================================================================
	// get_redirect_data tests - Subdirectory home path
	// =========================================================================

	/**
	 * Test the subsite prefix is stripped from a request inside the subsite.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_strips_subsite_prefix(): void {
		$this->assert_lookup_path( 'https://example.com/blog', '/blog/old-page', '/old-page' );
	}

	/**
	 * Test a path that merely shares the prefix's characters is left intact.
	 *
	 * Without a segment boundary, '/blogging-tips' would be stripped to
	 * '/ging-tips': its own redirect could never fire, and an unrelated
	 * redirect for '/ging-tips' would fire in its place.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_does_not_strip_partial_segment_match(): void {
		$this->assert_lookup_path( 'https://example.com/blog', '/blogging-tips', '/blogging-tips' );
	}

	/**
	 * Test a request for the subsite home itself looks up the root path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_maps_subsite_home_to_root(): void {
		$this->assert_lookup_path( 'https://example.com/blog', '/blog', '/' );
	}

	/**
	 * Assert which path a request URL is looked up under for a given home URL.
	 *
	 * @param string $home_url     The site's home URL.
	 * @param string $request_url  The requested URL.
	 * @param string $lookup_path  The path the repository should be queried with.
	 * @return void
	 */
	private function assert_lookup_path( string $home_url, string $request_url, string $lookup_path ): void {
		$this->stub_home_url( $home_url );

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( $lookup_path )
			->andReturnFirstArg();

		Filters\expectApplied( 'wpcom_legacy_redirector_preserve_query_params' )
			->once()
			->andReturn( array() );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === $lookup_path ) )
			->andReturn( null );

		$this->assertNull( $this->resolver->get_redirect_data( $request_url ) );
	}

	// =========================================================================
	// find_redirect tests
	// =========================================================================

	/**
	 * Test find_redirect returns redirect entity when found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::find_redirect
	 */
	public function test_find_redirect_returns_redirect_when_found(): void {
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
		// Invalid URL that throws InvalidArgumentException in SourceUrl::from_string().
		$result = $this->resolver->find_redirect( '' );

		$this->assertNull( $result );
	}
}
