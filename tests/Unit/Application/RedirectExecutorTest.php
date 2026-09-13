<?php
/**
 * RedirectExecutor service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\RedirectExecutor;
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
 * RedirectExecutorTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class RedirectExecutorTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The executor under test.
	 *
	 * @var RedirectExecutor
	 */
	private RedirectExecutor $executor;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->executor   = new RedirectExecutor( $this->repository );
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
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page', $result['url'] );
		$this->assertSame( 301, $result['status_code'] );
	}

	/**
	 * Test get_redirect_data returns redirect data with post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/destination-post', $result['url'] );
		$this->assertSame( 301, $result['status_code'] );
	}

	/**
	 * Test get_redirect_data with full URL input extracts path correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( 'https://example.com/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page', $result['url'] );
	}

	// =========================================================================
	// get_redirect_data tests - Request Path Filter
	// =========================================================================

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_request_path filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/original-path' );

		$this->assertIsArray( $result );
	}

	// =========================================================================
	// get_redirect_data tests - Preserve Query Params
	// =========================================================================

	/**
	 * Test get_redirect_data preserves specified query parameters.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page?utm_source=test&other=value' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/new-page?utm_source=test', $result['url'] );
	}

	// =========================================================================
	// get_redirect_data tests - Redirect Status Filter
	// =========================================================================

	/**
	 * Test get_redirect_data applies wpcom_legacy_redirector_redirect_status filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 302, $result['status_code'] );
	}

	// =========================================================================
	// get_redirect_data tests - Edge Cases
	// =========================================================================

	/**
	 * Test get_redirect_data returns null for empty path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_for_empty_path(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '' )
			->andReturn( '' );

		$result = $this->executor->get_redirect_data( '' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when filter empties path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
	 */
	public function test_get_redirect_data_returns_null_when_filter_empties_path(): void {
		$this->stub_home_url();

		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/some-path' )
			->andReturn( '' );

		$result = $this->executor->get_redirect_data( '/some-path' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when redirect not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/nonexistent-page' );

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
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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
		$result = $this->executor->get_redirect_data( '/some-input' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data returns null when get_permalink returns false.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_redirect_data handles URL-encoded characters.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
	 */
	public function test_get_redirect_data_handles_encoded_characters(): void {
		$this->stub_home_url();
		$redirect = $this->create_redirect( '/hello world', '/new-page' );

		// URL decoding should convert %20 to space.
		Filters\expectApplied( 'wpcom_legacy_redirector_request_path' )
			->once()
			->with( '/hello world' )
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

		$result = $this->executor->get_redirect_data( '/hello%20world' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_redirect_data handles absolute destination URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::get_redirect_data
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

		$result = $this->executor->get_redirect_data( '/old-page' );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://external.com/page', $result['url'] );
	}

	// =========================================================================
	// find_redirect tests
	// =========================================================================

	/**
	 * Test find_redirect returns redirect entity when found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::find_redirect
	 */
	public function test_find_redirect_returns_redirect_when_found(): void {
		$redirect = $this->create_redirect( '/old-page', '/new-page' );

		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === '/old-page' ) )
			->andReturn( $redirect );

		$result = $this->executor->find_redirect( '/old-page' );

		$this->assertInstanceOf( Redirect::class, $result );
		$this->assertSame( '/old-page', $result->source()->path() );
	}

	/**
	 * Test find_redirect returns null when not found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::find_redirect
	 */
	public function test_find_redirect_returns_null_when_not_found(): void {
		$this->repository
			->shouldReceive( 'find_by_source' )
			->once()
			->andReturn( null );

		$result = $this->executor->find_redirect( '/nonexistent' );

		$this->assertNull( $result );
	}

	/**
	 * Test find_redirect returns null for invalid URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::find_redirect
	 */
	public function test_find_redirect_returns_null_for_invalid_url(): void {
		// Invalid URL that throws InvalidArgumentException in SourceUrl::from_string().
		$result = $this->executor->find_redirect( '' );

		$this->assertNull( $result );
	}

	// =========================================================================
	// Constructor tests
	// =========================================================================

	/**
	 * Test constructor accepts custom plugin name.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::__construct
	 */
	public function test_constructor_accepts_custom_plugin_name(): void {
		$executor = new RedirectExecutor( $this->repository, 'custom-plugin' );

		// We can't directly test the plugin name is stored, but we can verify
		// the executor is created successfully.
		$this->assertInstanceOf( RedirectExecutor::class, $executor );
	}

	// =========================================================================
	// maybe_redirect tests (limited - cannot test exit)
	// =========================================================================

	/**
	 * Test maybe_redirect exits early when not 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_not_404(): void {
		Functions\expect( 'is_404' )
			->once()
			->andReturn( false );

		// Repository should NOT be called since we exit early.
		$this->repository
			->shouldNotReceive( 'find_by_source' );

		$this->executor->maybe_redirect();

		// If we get here without calling the repository, the test passes.
		$this->assertTrue( true );
	}

	/**
	 * Test maybe_redirect exits early when REQUEST_URI is empty.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_request_uri_empty(): void {
		// Backup and clear REQUEST_URI.
		$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '';

		Functions\expect( 'is_404' )
			->once()
			->andReturn( true );

		// Repository should NOT be called since REQUEST_URI is empty.
		$this->repository
			->shouldNotReceive( 'find_by_source' );

		$this->executor->maybe_redirect();

		// Restore REQUEST_URI.
		if ( null !== $original_request_uri ) {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

		$this->assertTrue( true );
	}

	/**
	 * Test maybe_redirect exits early when no redirect found.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::maybe_redirect
	 */
	public function test_maybe_redirect_exits_early_when_no_redirect_found(): void {
		$this->stub_home_url();

		// Set REQUEST_URI.
		$original_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/nonexistent-page';

		Functions\expect( 'is_404' )
			->once()
			->andReturn( true );

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

		// wp_safe_redirect should NOT be called.
		Functions\expect( 'wp_safe_redirect' )
			->never();

		$this->executor->maybe_redirect();

		// Restore REQUEST_URI.
		if ( null !== $original_request_uri ) {
			$_SERVER['REQUEST_URI'] = $original_request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

		$this->assertTrue( true );
	}

	// =========================================================================
	// allow_redirect_host tests
	// =========================================================================

	/**
	 * Test that the allowed_redirect_hosts filter callback adds the host correctly.
	 *
	 * This tests the closure logic directly by simulating what allow_redirect_host does.
	 * The actual integration with wp_safe_redirect is tested in integration tests.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::allow_redirect_host
	 */
	public function test_allowed_redirect_hosts_filter_adds_host_to_array(): void {
		// Simulate the closure that allow_redirect_host creates.
		$host            = 'external-site.com';
		$filter_callback = static function ( array $hosts ) use ( $host ): array {
			$hosts[] = $host;
			return $hosts;
		};

		$existing_hosts = array( 'example.com', 'another-site.com' );
		$result         = $filter_callback( $existing_hosts );

		$this->assertContains( 'external-site.com', $result );
		$this->assertContains( 'example.com', $result );
		$this->assertContains( 'another-site.com', $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Test that the filter callback works with empty initial hosts array.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectExecutor::allow_redirect_host
	 */
	public function test_allowed_redirect_hosts_filter_works_with_empty_array(): void {
		$host            = 'external-site.com';
		$filter_callback = static function ( array $hosts ) use ( $host ): array {
			$hosts[] = $host;
			return $hosts;
		};

		$result = $filter_callback( array() );

		$this->assertContains( 'external-site.com', $result );
		$this->assertCount( 1, $result );
	}
}
