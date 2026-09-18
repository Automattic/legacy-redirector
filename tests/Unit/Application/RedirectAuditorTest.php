<?php
/**
 * RedirectAuditor service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file includes testable subclass.

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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
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
