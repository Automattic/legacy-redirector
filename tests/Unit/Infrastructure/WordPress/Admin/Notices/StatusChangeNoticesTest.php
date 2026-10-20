<?php
/**
 * StatusChangeNotices unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * StatusChangeNoticesTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::duplicate_sources_url
 */
final class StatusChangeNoticesTest extends MonkeyStubs {

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\stubs(
			array(
				'wp_kses',
				'absint'             => static fn( $value ): int => abs( (int) $value ),
				'admin_url'          => static fn( $path = '' ): string => 'https://example.com/wp-admin/' . $path,
				'add_query_arg'      => static fn( $key, $value, $url ): string => $url . '&' . $key . '=' . $value,
				'get_current_screen' => (object) array( 'id' => 'edit-' . PostType::POST_TYPE ),
			)
		);
	}

	/**
	 * Clean up the request.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$_GET = array();

		parent::tear_down();
	}

	/**
	 * Render the notices for a request.
	 *
	 * @param array<string, string> $get The query arguments.
	 * @return string The rendered notices.
	 */
	private function render( array $get ): string {
		$_GET = $get;

		ob_start();
		( new StatusChangeNotices() )->display();

		return (string) ob_get_clean();
	}

	/**
	 * Test the Test bulk action submitted without JavaScript says what to do instead.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices::display
	 */
	public function test_bulk_test_without_javascript_explains_the_alternatives(): void {
		$output = $this->render( array( 'bulk_test_needs_js' => '1' ) );

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'needs JavaScript', $output );
		$this->assertStringContainsString( '<code>wp legacy-redirector test</code>', $output );
	}

	/**
	 * Test a fully successful bulk enable keeps its success notice.
	 */
	public function test_bulk_enable_success_is_unchanged(): void {
		$output = $this->render( array( 'bulk_redirects_enabled' => '2' ) );

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( '2 redirects enabled.', $output );
	}

	/**
	 * Test a refused duplicate turns the notice into a warning that says why.
	 */
	public function test_bulk_enable_with_refused_duplicate_warns(): void {
		$output = $this->render(
			array(
				'bulk_redirects_enabled' => '1',
				'bulk_redirects_failed'  => '1',
				'bulk_redirects_reason'  => 'duplicate',
			)
		);

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Enabled 1 of 2 redirects. 1 could not be enabled.', $output );
		$this->assertStringContainsString( 'shares its source with an enabled redirect', $output );
		$this->assertStringContainsString( 'duplicate_sources=1', $output );
	}

	/**
	 * Test every row refused is a warning, not a green "0 redirects".
	 */
	public function test_bulk_disable_all_refused_warns(): void {
		$output = $this->render(
			array(
				'bulk_redirects_disabled' => '0',
				'bulk_redirects_failed'   => '2',
				'bulk_redirects_reason'   => 'failed',
			)
		);

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'Disabled 0 of 2 redirects. 2 could not be disabled.', $output );
		$this->assertStringNotContainsString( 'duplicate_sources', $output );
	}

	/**
	 * Test an unknown reason in the URL is not reflected back.
	 */
	public function test_unknown_reason_falls_back_to_generic(): void {
		$output = $this->render(
			array(
				'bulk_redirects_enabled' => '0',
				'bulk_redirects_failed'  => '1',
				'bulk_redirects_reason'  => '<script>',
			)
		);

		$this->assertStringContainsString( 'could not be saved', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}
}
