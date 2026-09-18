<?php
/**
 * RedirectAuditor service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file includes testable subclass.

use Automattic\LegacyRedirector\Application\LoopDetector;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use WP_Post;

/**
 * RedirectAuditorTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class RedirectAuditorTest extends MonkeyStubs {

	/**
	 * The auditor under test.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->auditor = new RedirectAuditor();

		Functions\stubs(
			array(
				'is_wp_error'                      => static fn( $thing ) => $thing instanceof \WP_Error,
				'wp_remote_retrieve_response_code' => static fn( $response ) => $response['response']['code'] ?? 0,
				'wp_remote_retrieve_header'        => static fn( $response, $header ) => $response['headers'][ $header ] ?? '',
				// Mirrors core: strip one or more trailing slashes.
				'untrailingslashit'                => static fn( $value ) => rtrim( $value, '/\\' ),
			)
		);
	}

	/**
	 * Creates a redirect to a URL destination.
	 *
	 * @param string $source The source path.
	 * @param string $url    The destination URL.
	 * @return Redirect
	 */
	private function create_redirect( string $source = '/old-page', string $url = '/new-page' ): Redirect {
		return Redirect::create(
			SourceUrl::from_string( $source ),
			Destination::from_url( DestinationUrl::from_string( $url ) )
		);
	}

	/**
	 * Creates a redirect to a post ID destination.
	 *
	 * @param int $post_id The destination post ID.
	 * @return Redirect
	 */
	private function create_post_id_redirect( int $post_id = 123 ): Redirect {
		return Redirect::create(
			SourceUrl::from_string( '/old-page' ),
			Destination::from_post_id( DestinationPostId::from_int( $post_id ) )
		);
	}

	/**
	 * Creates a mock WP_Post object.
	 *
	 * @param string $status The post status.
	 * @return WP_Post
	 */
	private function create_mock_post( string $status = 'publish' ): WP_Post {
		return new WP_Post( $status );
	}

	/**
	 * Test audit_destination reports a deleted destination post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_deleted_post(): void {
		Functions\expect( 'get_post' )
			->once()
			->with( 999 )
			->andReturn( null );

		$finding = $this->auditor->audit_destination( $this->create_post_id_redirect( 999 ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_DELETED, $finding->type() );
	}

	/**
	 * Test audit_destination reports a trashed destination post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_trashed_post(): void {
		Functions\expect( 'get_post' )
			->once()
			->with( 123 )
			->andReturn( $this->create_mock_post( 'trash' ) );

		$finding = $this->auditor->audit_destination( $this->create_post_id_redirect( 123 ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_TRASHED, $finding->type() );
	}

	/**
	 * Test audit_destination reports an unpublished destination post with its status.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_an_unpublished_post(): void {
		Functions\expect( 'get_post' )
			->once()
			->with( 123 )
			->andReturn( $this->create_mock_post( 'draft' ) );

		$finding = $this->auditor->audit_destination( $this->create_post_id_redirect( 123 ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_UNPUBLISHED, $finding->type() );
		$this->assertSame( 'status: draft', $finding->extra_info() );
	}

	/**
	 * Test audit_destination accepts a published destination post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_accepts_a_published_post(): void {
		Functions\expect( 'get_post' )
			->once()
			->with( 123 )
			->andReturn( $this->create_mock_post( 'publish' ) );

		$this->assertNull( $this->auditor->audit_destination( $this->create_post_id_redirect( 123 ) ) );
	}

	/**
	 * Test audit_destination resolves relative paths across every post type.
	 *
	 * A redirect to a custom post type item must be judged by that post's
	 * status, not treated as unresolvable because only posts and pages were
	 * searched.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_resolves_custom_post_types(): void {
		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page', 'book' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'some-book', OBJECT, array( 'post', 'page', 'book' ) )
			->andReturn( $this->create_mock_post( 'draft' ) );

		$finding = $this->auditor->audit_destination( $this->create_redirect( '/old', '/some-book' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_UNPUBLISHED, $finding->type() );
	}

	/**
	 * Test audit_destination falls back to url_to_postid for permalink structures.
	 *
	 * A dated permalink cannot be walked as a hierarchical slug, so without
	 * this fallback an unpublished post behind one is never noticed.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_resolves_dated_permalinks(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 42 );

		Functions\expect( 'get_post' )
			->once()
			->with( 42 )
			->andReturn( $this->create_mock_post( 'draft' ) );

		$finding = $this->auditor->audit_destination( $this->create_redirect( '/old', '/2020/01/01/some-post/' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_UNPUBLISHED, $finding->type() );
	}

	/**
	 * Test audit_destination finds a trashed post behind its renamed slug.
	 *
	 * Trashing renames post_name with a __trashed suffix, so the direct
	 * lookup misses it.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_trashed_slug(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'gone-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( null );

		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'gone-page__trashed', OBJECT, array( 'post', 'page' ) )
			->andReturn( $this->create_mock_post( 'trash' ) );

		$finding = $this->auditor->audit_destination( $this->create_redirect( '/old', '/gone-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::POST_TRASHED, $finding->type() );
	}

	/**
	 * Test audit_destination strips query strings before the slug lookup.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_ignores_query_string_for_lookup(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'some-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $this->create_mock_post( 'publish' ) );

		$this->assertNull( $this->auditor->audit_destination( $this->create_redirect( '/old', '/some-page?utm_source=x' ) ) );
	}

	/**
	 * Test audit_destination treats a path with no post as indeterminate.
	 *
	 * Archives and rewrite endpoints have no post to find; only the optional
	 * HTTP check can say anything about them.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_accepts_a_path_with_no_post(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->twice()
			->andReturn( null );

		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		$this->assertNull( $this->auditor->audit_destination( $this->create_redirect( '/old', '/category/news/' ) ) );
	}

	/**
	 * Test audit_destination never looks up the home page by slug.
	 *
	 * A lookup with an empty slug matches any post with an empty post_name, so
	 * the home page must never be looked up by slug.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_accepts_home_without_lookup(): void {
		Functions\expect( 'get_page_by_path' )->never();
		Functions\expect( 'url_to_postid' )->never();

		$this->assertNull( $this->auditor->audit_destination( $this->create_redirect( '/old', '/' ) ) );
		$this->assertNull( $this->auditor->audit_destination( $this->create_redirect( '/old', '/?utm_source=x' ) ) );
	}

	/**
	 * Test audit_destination reports an absolute URL on a disallowed host.
	 *
	 * At request time wp_safe_redirect() quietly sends the visitor elsewhere,
	 * so the redirect is broken whether stored or proposed - and no HTTP
	 * request is needed to know it.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_disallowed_host(): void {
		Functions\expect( 'wp_validate_redirect' )
			->once()
			->with( 'https://external.com/page', '' )
			->andReturn( '' );

		$finding = $this->auditor->audit_destination( $this->create_redirect( '/old', 'https://external.com/page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, $finding->type() );
		$this->assertSame( 'host: external.com', $finding->extra_info() );
	}

	/**
	 * Test audit_destination accepts an allowed absolute URL without HTTP checks.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_accepts_an_allowed_host_without_http(): void {
		Functions\expect( 'wp_validate_redirect' )
			->once()
			->andReturnFirstArg();

		$this->assertNull( $this->auditor->audit_destination( $this->create_redirect( '/old', 'https://allowed.com/page' ) ) );
	}

	/**
	 * Test the HTTP check reports a 404 destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_404_url(): void {
		Functions\expect( 'wp_validate_redirect' )->once()->andReturnFirstArg();

		$auditor = new TestableRedirectAuditor( 404 );
		$finding = $auditor->audit_destination( $this->create_redirect( '/old', 'https://allowed.com/gone' ), true );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::URL_NOT_FOUND, $finding->type() );
	}

	/**
	 * Test the HTTP check reports a server error with its status.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_reports_a_server_error(): void {
		Functions\expect( 'wp_validate_redirect' )->once()->andReturnFirstArg();

		$auditor = new TestableRedirectAuditor( 503 );
		$finding = $auditor->audit_destination( $this->create_redirect( '/old', 'https://allowed.com/down' ), true );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::URL_SERVER_ERROR, $finding->type() );
		$this->assertSame( 'status: 503', $finding->extra_info() );
	}

	/**
	 * Test the HTTP check accepts a responding destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_destination
	 */
	public function test_audit_destination_accepts_a_responding_url(): void {
		Functions\expect( 'wp_validate_redirect' )->once()->andReturnFirstArg();

		$auditor = new TestableRedirectAuditor( 200 );

		$this->assertNull( $auditor->audit_destination( $this->create_redirect( '/old', 'https://allowed.com/page' ), true ) );
	}

	/**
	 * Test the probe confirms a source that redirects to its destination.
	 *
	 * A trailing-slash difference in the Location header is not a divergence.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::probe_source
	 */
	public function test_probe_source_confirms_a_matching_redirect(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/target/' ),
			)
		);

		$this->assertSame(
			array(
				'status'   => 'confirmed',
				'location' => 'https://example.com/target/',
			),
			$auditor->probe_source( $this->create_redirect( '/old', '/target' ) )
		);
	}

	/**
	 * Test the probe reports a redirect that goes somewhere else.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::probe_source
	 */
	public function test_probe_source_reports_a_diverted_redirect(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 302 ),
				'headers'  => array( 'location' => 'https://example.com/elsewhere' ),
			)
		);

		$probe = $auditor->probe_source( $this->create_redirect( '/old', '/target' ) );

		$this->assertSame( 'diverted', $probe['status'] );
		$this->assertSame( 'https://example.com/elsewhere', $probe['location'] );
	}

	/**
	 * Test a hop back to the source itself is dormant, not diverted.
	 *
	 * Apache directory redirects and trailing-slash canonicals send the
	 * visitor to the same path: the path is served, so the redirect never
	 * fires - but nothing was diverted anywhere.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::probe_source
	 */
	public function test_probe_source_treats_a_self_hop_as_dormant(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/old/' ),
			)
		);

		$this->assertSame( array( 'status' => 'dormant' ), $auditor->probe_source( $this->create_redirect( '/old', '/target' ) ) );
	}

	/**
	 * Test the probe reports a source that serves content and one that 404s.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::probe_source
	 */
	public function test_probe_source_reports_dormant_and_not_firing_sources(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$serving = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );
		$this->assertSame( array( 'status' => 'dormant' ), $serving->probe_source( $this->create_redirect( '/old', '/target' ) ) );

		$missing = new ProbeStubAuditor( array( 'response' => array( 'code' => 404 ) ) );
		$this->assertSame( array( 'status' => 'not-firing' ), $missing->probe_source( $this->create_redirect( '/old', '/target' ) ) );
	}

	/**
	 * Test the probe degrades gracefully when the site cannot reach itself.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::probe_source
	 */
	public function test_probe_source_reports_an_unreachable_site(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( new \WP_Error() );

		$this->assertSame( array( 'status' => 'unreachable' ), $auditor->probe_source( $this->create_redirect( '/old', '/target' ) ) );
	}

	/**
	 * Test source_warnings flags a reserved source.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::source_warnings
	 */
	public function test_source_warnings_flags_a_reserved_source(): void {
		$this->assertSame(
			array( AuditFindingType::RESERVED_SOURCE ),
			$this->auditor->source_warnings( SourceUrl::from_string( '/wp-admin' ) )
		);
	}

	/**
	 * Test source_warnings is empty for an ordinary source.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::source_warnings
	 */
	public function test_source_warnings_is_empty_for_an_ordinary_source(): void {
		$this->assertSame( array(), $this->auditor->source_warnings( SourceUrl::from_string( '/old-page' ) ) );
	}

	/**
	 * Test destination_checks flags an absolute URL on a disallowed host.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::destination_checks
	 */
	public function test_destination_checks_flags_a_disallowed_host(): void {
		Functions\expect( 'wp_validate_redirect' )
			->once()
			->with( 'https://external.com/page', '' )
			->andReturn( '' );

		$this->assertSame(
			array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED ),
			$this->auditor->destination_checks( $this->create_redirect( '/old', 'https://external.com/page' )->destination() )
		);
	}

	/**
	 * Test destination_checks passes an allowed host, and skips hostless forms.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::destination_checks
	 */
	public function test_destination_checks_passes_allowed_and_hostless_destinations(): void {
		Functions\expect( 'wp_validate_redirect' )
			->once()
			->andReturnFirstArg();

		$this->assertSame( array(), $this->auditor->destination_checks( $this->create_redirect( '/old', 'https://allowed.com/page' )->destination() ) );
		$this->assertSame( array(), $this->auditor->destination_checks( $this->create_redirect( '/old', '/relative-page' )->destination() ) );
		$this->assertSame( array(), $this->auditor->destination_checks( $this->create_post_id_redirect( 123 )->destination() ) );
	}

	/**
	 * Test destination_needs_http distinguishes conclusive from indeterminate.
	 *
	 * A clean audit of a post ID or a resolved path really means "fine"; for
	 * an absolute URL, the home page, or a path with no post behind it, only
	 * an HTTP request can judge, and a display must not claim otherwise.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::destination_needs_http
	 */
	public function test_destination_needs_http_flags_only_http_judgeable_destinations(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		$this->assertFalse( $this->auditor->destination_needs_http( $this->create_post_id_redirect( 123 ) ) );
		$this->assertTrue( $this->auditor->destination_needs_http( $this->create_redirect( '/old', 'https://allowed.com/page' ) ) );
		// The front controller never 404s the home path, so '/' is conclusive.
		$this->assertFalse( $this->auditor->destination_needs_http( $this->create_redirect( '/old', '/' ) ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );
		$this->assertFalse( $this->auditor->destination_needs_http( $this->create_redirect( '/old', '/resolved-page' ) ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );
		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );
		$this->assertTrue( $this->auditor->destination_needs_http( $this->create_redirect( '/old', '/unresolved-page' ) ) );
	}

	/**
	 * Test a chain landing on published content is conclusive without HTTP.
	 *
	 * A destination with no post behind it may be another redirect's source;
	 * following the hops to a published end answers the question the same as
	 * pointing at that content directly.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::destination_needs_http
	 */
	public function test_destination_needs_http_follows_chains_to_content(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		$start = $this->create_redirect( '/start', '/hop' );
		$end   = $this->create_redirect( '/hop', '/landing-page' );

		$detector = \Mockery::mock( LoopDetector::class );
		$detector->shouldReceive( 'follow' )->with( $start )->andReturn( $end );

		$auditor = new RedirectAuditor( $detector );

		// /start's destination resolves to no post; /landing-page (the chain
		// end) resolves to a published one.
		Functions\expect( 'get_page_by_path' )
			->twice()
			->andReturn( null, $this->create_mock_post( 'publish' ) );
		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		$this->assertFalse( $auditor->destination_needs_http( $start ) );
	}

	/**
	 * Test a chain meeting a cycle stays inconclusive.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::destination_needs_http
	 */
	public function test_destination_needs_http_stays_true_when_the_chain_cycles(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		$start = $this->create_redirect( '/start', '/hop' );

		$detector = \Mockery::mock( LoopDetector::class );
		$detector->shouldReceive( 'follow' )->with( $start )->andReturn( null );

		$auditor = new RedirectAuditor( $detector );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );
		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		$this->assertTrue( $auditor->destination_needs_http( $start ) );
	}

	/**
	 * Test audit reports a possible loop from the detector as a warning.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::warnings
	 */
	public function test_audit_reports_a_possible_loop(): void {
		$redirect = $this->create_redirect( '/loop-a', '/loop-b' );

		$detector = \Mockery::mock( LoopDetector::class );
		$detector
			->shouldReceive( 'find_cycle' )
			->once()
			->with( $redirect )
			->andReturn( array( '/loop-a', '/loop-b', '/loop-a' ) );

		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$findings = ( new RedirectAuditor( $detector ) )->audit( $redirect );

		$this->assertCount( 1, $findings );
		$this->assertSame( AuditFindingType::POSSIBLE_LOOP, $findings[0]->type() );
		$this->assertTrue( $findings[0]->is_warning() );
		$this->assertSame( '/loop-a -> /loop-b -> /loop-a', $findings[0]->extra_info() );
	}

	/**
	 * Test audits without a detector carry no loop findings.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::warnings
	 */
	public function test_audit_without_a_detector_skips_loop_checks(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$this->assertSame( array(), $this->auditor->audit( $this->create_redirect( '/loop-a', '/loop-b' ) ) );
	}

	/**
	 * Test warnings combines the reserved-source and loop rules.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::warnings
	 */
	public function test_warnings_combines_reserved_source_and_loop(): void {
		$redirect = $this->create_redirect( '/wp-admin', '/wp-admin' );

		$detector = \Mockery::mock( LoopDetector::class );
		$detector
			->shouldReceive( 'find_cycle' )
			->once()
			->andReturn( array( '/wp-admin', '/wp-admin' ) );

		$warnings = ( new RedirectAuditor( $detector ) )->warnings( $redirect );

		$this->assertCount( 2, $warnings );
		$this->assertSame( AuditFindingType::RESERVED_SOURCE, $warnings[0]->type() );
		$this->assertSame( AuditFindingType::POSSIBLE_LOOP, $warnings[1]->type() );
	}

	/**
	 * Test audit combines destination problems and source warnings.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 */
	public function test_audit_combines_destination_and_source_findings(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'draft' ) );

		$findings = $this->auditor->audit( $this->create_redirect( '/wp-admin', '/draft-page' ) );

		$this->assertCount( 2, $findings );
		$this->assertSame( AuditFindingType::POST_UNPUBLISHED, $findings[0]->type() );
		$this->assertSame( AuditFindingType::RESERVED_SOURCE, $findings[1]->type() );
		$this->assertTrue( $findings[1]->is_warning() );
	}

	/**
	 * Test audit returns nothing for a healthy redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 */
	public function test_audit_returns_nothing_for_a_healthy_redirect(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$this->assertSame( array(), $this->auditor->audit( $this->create_redirect() ) );
	}

	/**
	 * Test audit reports only the corruption for a corrupt row.
	 *
	 * A corrupt row carries placeholder values; checking them would report
	 * nonsense.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 */
	public function test_audit_reports_only_the_corruption_for_a_corrupt_row(): void {
		$corrupt = Redirect::reconstitute(
			123,
			SourceUrl::from_string( '/corrupt-123' ),
			Destination::from_url( DestinationUrl::from_string( '/' ) ),
			'publish',
			null,
			'Stored source is not a valid path'
		);

		$findings = $this->auditor->audit( $corrupt, true );

		$this->assertCount( 1, $findings );
		$this->assertSame( AuditFindingType::CORRUPT_DATA, $findings[0]->type() );
		$this->assertSame( 'Stored source is not a valid path', $findings[0]->extra_info() );
	}

	/**
	 * Test audit_batch collects findings across redirects and reports progress.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_batch
	 */
	public function test_audit_batch_collects_findings_and_reports_progress(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->twice()
			->andReturn( $this->create_mock_post( 'draft' ), $this->create_mock_post( 'publish' ) );

		$progress = array();

		$findings = $this->auditor->audit_batch(
			array(
				$this->create_redirect( '/one', '/draft-page' ),
				$this->create_redirect( '/two', '/live-page' ),
			),
			false,
			function ( int $count ) use ( &$progress ): void {
				$progress[] = $count;
			}
		);

		$this->assertCount( 1, $findings );
		$this->assertContainsOnlyInstancesOf( AuditFinding::class, $findings );
		$this->assertSame( AuditFindingType::POST_UNPUBLISHED, $findings[0]->type() );
		$this->assertSame( array( 1, 2 ), $progress );
	}

	/**
	 * Test audit_batch passes the source check down to audit.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_batch
	 */
	public function test_audit_batch_passes_the_source_check_down(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		// The destination is a published post, so only the source is reported.
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'get_page_by_path' )->justReturn( $this->create_mock_post( 'publish' ) );

		$findings = $auditor->audit_batch( array( $this->create_redirect() ), false, null, true );

		$this->assertCount( 1, $findings );
		$this->assertSame( AuditFindingType::SOURCE_DID_NOT_REDIRECT, $findings[0]->type() );
	}

	/**
	 * Test audit_source reports a source that serves its own response.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_reports_a_source_that_did_not_redirect(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );
		$finding = $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::SOURCE_DID_NOT_REDIRECT, $finding->type() );
		$this->assertFalse( $finding->is_warning() );
	}

	/**
	 * Test a source that 404s is reported with its status.
	 *
	 * A 404 means the redirect did not fire either, but the extra info tells
	 * the two cases apart at a glance.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_reports_a_missing_source(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 404 ) ) );
		$finding = $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::SOURCE_DID_NOT_REDIRECT, $finding->type() );
		$this->assertSame( 'status: 404', $finding->extra_info() );
	}

	/**
	 * Test audit_source accepts a source that redirects to its destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_accepts_a_matching_redirect(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/new-page' ),
			)
		);

		$this->assertNull( $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) ) );
	}

	/**
	 * Test a trailing slash is not treated as a mismatch.
	 *
	 * WordPress picks one slash form and redirects the other, so the two are
	 * the same landing place.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_ignores_a_trailing_slash_difference(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/new-page/' ),
			)
		);

		$this->assertNull( $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) ) );
	}

	/**
	 * Test audit_source reports a redirect that lands somewhere else.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_reports_a_mismatch(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/somewhere-else' ),
			)
		);
		$finding = $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::REDIRECT_MISMATCH, $finding->type() );
		$this->assertSame( 'to: https://example.com/somewhere-else', $finding->extra_info() );
	}

	/**
	 * Test a 3xx with no Location is reported as a mismatch.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_reports_a_redirect_with_no_location(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 302 ) ) );
		$finding = $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::REDIRECT_MISMATCH, $finding->type() );
		$this->assertNull( $finding->extra_info() );
	}

	/**
	 * Test a failed source request is a warning.
	 *
	 * A timeout or a refused HEAD says nothing about whether the redirect
	 * works, so --fix must leave the redirect alone.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_reports_a_failed_request_as_a_warning(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( new \WP_Error( 'http_request_failed', 'Connection refused' ) );
		$finding = $auditor->audit_source( $this->create_redirect( '/old-page', '/new-page' ) );

		$this->assertNotNull( $finding );
		$this->assertSame( AuditFindingType::SOURCE_REQUEST_FAILED, $finding->type() );
		$this->assertTrue( $finding->is_warning() );
	}

	/**
	 * Test audit_source matches a post ID destination through its permalink.
	 *
	 * A post ID destination has no URL to compare against, so the expected
	 * location has to come from get_permalink().
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_matches_a_post_id_destination(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/the-post/' );

		$auditor = new ProbeStubAuditor(
			array(
				'response' => array( 'code' => 301 ),
				'headers'  => array( 'location' => 'https://example.com/the-post/' ),
			)
		);

		$this->assertNull( $auditor->audit_source( $this->create_post_id_redirect( 123 ) ) );
	}

	/**
	 * Test audit_source skips a disabled redirect.
	 *
	 * A disabled redirect does not fire by design, so reporting that would
	 * flag every disabled redirect on the site.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_skips_a_disabled_redirect(): void {
		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertNull( $auditor->audit_source( $this->create_redirect()->with_status( 'draft' ) ) );
	}

	/**
	 * Test audit_source skips a reserved source.
	 *
	 * A reserved source is already reported, and a legacy URL there is
	 * legitimate, so requesting it adds nothing.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_skips_a_reserved_source(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertNull( $auditor->audit_source( $this->create_redirect( '/wp-admin', '/new-page' ) ) );
	}

	/**
	 * Test audit_source skips a corrupt row.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit_source
	 */
	public function test_audit_source_skips_a_corrupt_row(): void {
		$corrupt = Redirect::reconstitute(
			123,
			SourceUrl::from_string( '/corrupt-123' ),
			Destination::from_url( DestinationUrl::from_string( '/' ) ),
			'publish',
			null,
			'Stored source is not a valid path'
		);

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertNull( $auditor->audit_source( $corrupt ) );
	}

	/**
	 * Test audit includes the source finding when the source check is on.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 */
	public function test_audit_includes_the_source_finding_when_enabled(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		$findings = $auditor->audit( $this->create_redirect( '/old-page', '/new-page' ), false, true );

		$this->assertCount( 1, $findings );
		$this->assertSame( AuditFindingType::SOURCE_DID_NOT_REDIRECT, $findings[0]->type() );
	}

	/**
	 * Test audit omits the source finding when the source check is off.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectAuditor::audit
	 */
	public function test_audit_omits_the_source_finding_when_disabled(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$auditor = new ProbeStubAuditor( array( 'response' => array( 'code' => 200 ) ) );

		$this->assertSame( array(), $auditor->audit( $this->create_redirect( '/old-page', '/new-page' ) ) );
	}
}

/**
 * Testable subclass that allows controlling HTTP response codes.
 *
 * Overriding remote_get() lets tests drive the HTTP check without mocking
 * WordPress HTTP functions.
 */
class TestableRedirectAuditor extends RedirectAuditor {

	/**
	 * The response code to return.
	 *
	 * @var int
	 */
	private int $response_code;

	/**
	 * Constructor.
	 *
	 * @param int $response_code The response code to return.
	 */
	public function __construct( int $response_code ) {
		$this->response_code = $response_code;
	}

	/**
	 * Override to return a fixed response.
	 *
	 * @param string $url The URL to request (ignored).
	 * @return array The canned response.
	 */
	protected function remote_get( string $url ) {
		return array( 'response' => array( 'code' => $this->response_code ) );
	}
}

/**
 * Testable subclass returning a canned probe response.
 */
class ProbeStubAuditor extends RedirectAuditor {

	/**
	 * The canned response.
	 *
	 * @var array|\WP_Error
	 */
	private $response;

	/**
	 * Constructor.
	 *
	 * @param array|\WP_Error $response The response to return.
	 */
	public function __construct( $response ) {
		parent::__construct();

		$this->response = $response;
	}

	/**
	 * Override to return the canned response.
	 *
	 * @param string $url The URL to request (ignored).
	 * @return array|\WP_Error The canned response.
	 */
	protected function remote_get_without_redirects( string $url ) {
		return $this->response;
	}

	/**
	 * Override so enabling both checks does not reach the network.
	 *
	 * @param string $url The URL to request (ignored).
	 * @return array The canned response.
	 */
	protected function remote_get( string $url ) {
		return array( 'response' => array( 'code' => 200 ) );
	}
}
