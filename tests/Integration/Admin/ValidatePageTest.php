<?php
/**
 * ValidatePage integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\ValidatePage;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for the Validate Redirects admin page.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\ValidatePage
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\RedirectPostMapper
 */
final class ValidatePageTest extends TestCase {

	/**
	 * The page under test.
	 *
	 * @var ValidatePage
	 */
	private ValidatePage $page;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// The view uses submit_button(), which only admin requests load.
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->page = new ValidatePage( $this->query_repository(), $this->auditor(), $this->audit_results() );
	}

	/**
	 * Clean up request superglobals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['_validate_report'], $_GET['status'], $_GET['limit'], $_GET['check_urls'] );

		parent::tear_down();
	}

	/**
	 * Render the page and return its output.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Test a healthy set of redirects reports no issues.
	 */
	public function test_healthy_redirects_report_no_issues(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/healthy-source', $post_id );

		$output = $this->render();

		$this->assertStringContainsString( 'Checked 1 redirect(s): 0 problem(s), 0 warning(s).', $output );
		$this->assertStringContainsString( 'No issues found.', $output );
	}

	/**
	 * Test a broken destination is reported as a problem, matching the CLI.
	 */
	public function test_broken_destination_is_reported_as_a_problem(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/broken-source', $post_id );
		wp_delete_post( $post_id, true );

		$output = $this->render();

		$this->assertStringContainsString( '1 problem(s), 0 warning(s)', $output );
		$this->assertStringContainsString( '/broken-source', $output );
		$this->assertStringContainsString( 'Post deleted', $output );
		$this->assertStringContainsString( 'Problem', $output );
	}

	/**
	 * Test a reserved source is reported as a warning.
	 */
	public function test_reserved_source_is_reported_as_a_warning(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/wp-admin/', $post_id );

		$output = $this->render();

		$this->assertStringContainsString( '0 problem(s), 1 warning(s)', $output );
		$this->assertStringContainsString( 'Reserved WordPress path', $output );
		$this->assertStringContainsString( 'Warning', $output );
	}

	/**
	 * Test rendering the page records the run as the audit summary.
	 */
	public function test_render_records_the_summary(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/recorded-broken', $post_id );
		wp_delete_post( $post_id, true );

		$output = $this->render();

		$summary = $this->audit_results()->summary();
		$this->assertNotNull( $summary );
		$this->assertSame( 1, $summary['problems'] );
		$this->assertSame( 1, $summary['checked'] );
		$this->assertStringContainsString( 'now the recorded audit', $output );
	}

	/**
	 * Test request parameters are ignored without a valid nonce.
	 *
	 * The check_urls flag makes the server issue an HTTP request per URL
	 * destination, so a crafted link must not be able to switch it on.
	 */
	public function test_parameters_without_nonce_fall_back_to_defaults(): void {
		$this->create_redirect( '/external-source', 'https://example.com/page' );
		$this->create_redirect( '/disabled-source', 'https://example.com/other' );
		wp_update_post(
			array(
				'ID'          => $this->repository()->get_id_by_source( SourceUrl::from_string( '/disabled-source' ) ),
				'post_status' => 'draft',
			)
		);

		$_GET['check_urls'] = '1';
		$_GET['status']     = 'any';

		$http_requests = 0;
		$count_http    = function () use ( &$http_requests ) {
			++$http_requests;
			return array( 'response' => array( 'code' => 200 ) );
		};
		add_filter( 'pre_http_request', $count_http );

		try {
			$output = $this->render();
		} finally {
			remove_filter( 'pre_http_request', $count_http );
		}

		// No nonce: check_urls stays off and status stays 'enabled'.
		$this->assertSame( 0, $http_requests );
		$this->assertStringContainsString( 'Checked 1 redirect(s)', $output );
	}

	/**
	 * Test a valid nonce enables the requested parameters.
	 */
	public function test_parameters_with_nonce_are_honoured(): void {
		$this->create_redirect( '/nonce-external', 'https://example.com/page' );

		$_GET['_validate_report'] = wp_create_nonce( ValidatePage::PAGE_SLUG );
		$_GET['check_urls']       = '1';

		$respond_404 = static function () {
			return array( 'response' => array( 'code' => 404 ) );
		};
		add_filter( 'pre_http_request', $respond_404 );

		try {
			$output = $this->render();
		} finally {
			remove_filter( 'pre_http_request', $respond_404 );
		}

		$this->assertStringContainsString( 'Destination returns 404', $output );
	}
}
